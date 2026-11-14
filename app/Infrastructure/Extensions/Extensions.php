<?php

declare(strict_types=1);

namespace App\Infrastructure\Extensions;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Installed extensions (point 173): the state lives in storage/app/extensions.json
 *   {"v":1,"installed":{"ocr-ell":{"version":"4.1.0","files":["ocr/tessdata/ell.traineddata"],"enabled":true,"at":"…"}}}
 * (files relative to storage/app). Installing takes only catalogue entries (Catalogue) and checks every file
 * against its pinned SHA-256 and size; the download itself is the $fetch closure from a net job (D-020,
 * App\Jobs\Net\InstallExtension). Removing deletes exactly the files the state lists.
 *
 * "enabled" lets an installed data pack be switched off without deleting it (OCR: the language is not used).
 */
final class Extensions
{
    private const int LOCK_SECONDS = 900;

    public function __construct(private readonly ?string $root = null) {}

    /** @return array<string, array{version: string, files: list<string>, enabled: bool, at: string}> */
    public function installed(): array
    {
        $file = $this->stateFile();
        if (! is_file($file)) {
            return [];
        }
        $state = json_decode((string) file_get_contents($file), true);
        $installed = is_array($state) && is_array($state['installed'] ?? null) ? $state['installed'] : [];
        $out = [];
        foreach ($installed as $key => $entry) {
            if (is_string($key) && is_array($entry) && is_string($entry['version'] ?? null) && is_array($entry['files'] ?? null)) {
                $out[$key] = [
                    'version' => $entry['version'],
                    'files' => array_values(array_filter($entry['files'], 'is_string')),
                    'enabled' => ($entry['enabled'] ?? true) !== false,
                    'at' => is_string($entry['at'] ?? null) ? $entry['at'] : '',
                ];
            }
        }

        return $out;
    }

    /** Installed and all its files on disk. */
    public function isInstalled(string $key): bool
    {
        $entry = $this->installed()[$key] ?? null;
        if ($entry === null) {
            return false;
        }
        foreach ($entry['files'] as $path) {
            if (! is_file($this->path($path))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Catalogue entries of a group that are installed, intact and switched on.
     *
     * @return list<Extension>
     */
    public function enabled(string $group): array
    {
        $installed = $this->installed();

        return array_values(array_filter(Catalogue::group($group),
            fn (Extension $e): bool => ($installed[$e->key]['enabled'] ?? false) && $this->isInstalled($e->key)));
    }

    /** An installed pack whose catalogue version is newer (or different) – "Aktualisieren". */
    public function updatable(string $key): bool
    {
        $entry = $this->installed()[$key] ?? null;
        $ext = Catalogue::find($key);

        return $entry !== null && $ext !== null && $ext->installable() && $ext->version !== $entry['version'];
    }

    /**
     * Installs or updates a catalogue entry. Files of an older version that the new one no longer lists are removed.
     *
     * @param  Closure(string, string, string): void  $fetch  downloads url → target and checks the SHA-256 (net job, D-020)
     */
    public function install(string $key, Closure $fetch): void
    {
        $ext = Catalogue::find($key) ?? throw new RuntimeException('Unbekannte Erweiterung: '.$key);
        if (! $ext->installable()) {
            throw new RuntimeException('Noch nicht verfügbar: '.$key);
        }
        $this->locked(function () use ($ext, $fetch): void {
            File::ensureDirectoryExists($this->path($ext->dir));
            foreach ($ext->files as $file) {
                $target = $this->path($ext->dir.'/'.$file['name']);
                $fetch($file['url'], $file['sha256'], $target);
                if (! self::verify($target, $file['sha256'], $file['size'])) {
                    @unlink($target);
                    throw new RuntimeException('Prüfsumme falsch: '.$file['name']);
                }
            }
            $state = $this->installed();
            foreach (array_diff($state[$ext->key]['files'] ?? [], $ext->paths()) as $old) {
                $this->unlinkInside($old);
            }
            $state[$ext->key] = ['version' => (string) $ext->version, 'files' => $ext->paths(), 'enabled' => $state[$ext->key]['enabled'] ?? true, 'at' => now()->toIso8601String()];
            $this->write($state);
        });
    }

    /** Deletes the pack's files and forgets it. */
    public function remove(string $key): void
    {
        $this->locked(function () use ($key): void {
            $state = $this->installed();
            foreach ($state[$key]['files'] ?? [] as $path) {
                $this->unlinkInside($path);
            }
            unset($state[$key]);
            $this->write($state);
        });
    }

    public function setEnabled(string $key, bool $enabled): void
    {
        $this->locked(function () use ($key, $enabled): void {
            $state = $this->installed();
            if (isset($state[$key])) {
                $state[$key]['enabled'] = $enabled;
                $this->write($state);
            }
        });
    }

    /** A file matches its pin: exact size and SHA-256. */
    public static function verify(string $path, string $sha256, int $size): bool
    {
        return is_file($path) && filesize($path) === $size && hash_equals($sha256, (string) hash_file('sha256', $path));
    }

    /** Absolute path of a path relative to storage/app. */
    public function path(string $relative): string
    {
        return ($this->root ?? storage_path('app')).'/'.$relative;
    }

    private function stateFile(): string
    {
        return $this->path('extensions.json');
    }

    /** @param array<string, array{version: string, files: list<string>, enabled: bool, at: string}> $installed */
    private function write(array $installed): void
    {
        $tmp = $this->stateFile().'.tmp';
        $json = json_encode(['v' => 1, 'installed' => $installed === [] ? new \stdClass : $installed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json) === false || ! @rename($tmp, $this->stateFile())) {
            @unlink($tmp);
            throw new RuntimeException('Schreiben fehlgeschlagen: extensions.json');
        }
    }

    /** Only paths below the storage root are deleted (the state file could be edited by hand). */
    private function unlinkInside(string $relative): void
    {
        if (str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
            return;
        }
        $path = $this->path($relative);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @param Closure(): void $work */
    private function locked(Closure $work): void
    {
        $lock = Cache::lock('extensions', self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('Eine andere Installation läuft gerade.');
        }
        try {
            $work();
        } finally {
            $lock->release();
        }
    }
}

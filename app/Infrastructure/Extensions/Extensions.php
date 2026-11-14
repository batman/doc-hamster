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
 * 172: an entry's requires are installed along with it ("auto": true = only there for another entry); removing an
 * entry also removes its auto-installed requires that nothing else installed needs any more.
 */
final class Extensions
{
    private const int LOCK_SECONDS = 900;

    public function __construct(private readonly ?string $root = null) {}

    /** @return array<string, array{version: string, files: list<string>, enabled: bool, auto: bool, at: string}> */
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
                    'auto' => ($entry['auto'] ?? false) === true,
                    'at' => is_string($entry['at'] ?? null) ? $entry['at'] : '',
                ];
            }
        }

        return $out;
    }

    /** Installed, all its files on disk and everything it requires installed too. */
    public function isInstalled(string $key): bool
    {
        $entry = $this->installed()[$key] ?? null;
        if ($entry === null || ! $this->present($entry['files'])) {
            return false;
        }
        foreach (Catalogue::find($key)->requires ?? [] as $required) {
            if (! $this->isInstalled($required)) {
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

        if ($entry === null || $ext === null || ! $ext->installable()) {
            return false;
        }
        if ($ext->version !== $entry['version']) {
            return true;
        }
        foreach ($ext->requires as $required) {
            if ($this->updatable($required)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Installs or updates a catalogue entry and, first, what it requires (172). Files of an older version that the
     * new one no longer lists are removed; a required entry that is already there in its version is kept as it is.
     *
     * @param  Closure(string, string, string): void  $fetch  downloads url → target and checks the SHA-256 (net job, D-020)
     */
    public function install(string $key, Closure $fetch): void
    {
        $ext = Catalogue::find($key) ?? throw new RuntimeException('Unbekannte Erweiterung: '.$key);
        $order = [...self::requiredBy($ext), $ext];
        foreach ($order as $item) {
            if (! $item->installable()) {
                throw new RuntimeException('Noch nicht verfügbar: '.$item->key);
            }
        }
        $this->locked(function () use ($order, $ext, $fetch): void {
            foreach ($order as $item) {
                $state = $this->installed();
                $current = $state[$item->key] ?? null;
                $explicit = $item->key === $ext->key;
                if ($explicit || $current === null || $current['version'] !== $item->version || ! $this->present($current['files'])) {
                    $this->fetchFiles($item, $fetch);
                    foreach (array_diff($current['files'] ?? [], $item->paths()) as $old) {
                        $this->unlinkInside($old);
                    }
                }
                $state[$item->key] = ['version' => (string) $item->version, 'files' => $item->paths(), 'enabled' => $current['enabled'] ?? true,
                    'auto' => ! $explicit && ($current['auto'] ?? true), 'at' => now()->toIso8601String()];
                $this->write($state);
            }
        });
    }

    /** Deletes the pack's files and forgets it, with its auto-installed requires nothing else needs (172). */
    public function remove(string $key): void
    {
        $this->locked(function () use ($key): void {
            $state = $this->installed();
            $this->drop($key, $state);
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

    /**
     * What an entry requires, deepest first, each once.
     *
     * @param  array<string, true>  $seen
     * @return list<Extension>
     */
    private static function requiredBy(Extension $ext, array &$seen = []): array
    {
        $out = [];
        foreach ($ext->requires as $key) {
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $required = Catalogue::find($key) ?? throw new RuntimeException('Unbekannte Erweiterung: '.$key);
            array_push($out, ...self::requiredBy($required, $seen));
            $out[] = $required;
        }

        return $out;
    }

    /** Downloads the files that are not there yet in their pinned form. @param Closure(string, string, string): void $fetch */
    private function fetchFiles(Extension $ext, Closure $fetch): void
    {
        if ($ext->files === []) {
            return;
        }
        File::ensureDirectoryExists($this->path($ext->dir));
        foreach ($ext->files as $file) {
            $target = $this->path($ext->dir.'/'.$file['name']);
            if (is_file($target) && hash_equals($file['sha256'], (string) hash_file('sha256', $target))) {
                continue;
            }
            $fetch($file['url'], $file['sha256'], $target);
            if (! self::verify($target, $file['sha256'], $file['size'])) {
                @unlink($target);
                throw new RuntimeException('Prüfsumme falsch: '.$file['name']);
            }
        }
    }

    /** @param array<string, array{version: string, files: list<string>, enabled: bool, auto: bool, at: string}> $state */
    private function drop(string $key, array &$state): void
    {
        foreach ($state[$key]['files'] ?? [] as $path) {
            $this->unlinkInside($path);
        }
        unset($state[$key]);
        foreach (Catalogue::find($key)->requires ?? [] as $required) {
            $needed = array_filter(array_keys($state), static fn (string $other): bool => in_array($required, Catalogue::find($other)->requires ?? [], true));
            if (($state[$required]['auto'] ?? false) && $needed === []) {
                $this->drop($required, $state);
            }
        }
    }

    /** @param list<string> $files */
    private function present(array $files): bool
    {
        foreach ($files as $path) {
            if (! is_file($this->path($path))) {
                return false;
            }
        }

        return true;
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

    /** @param array<string, array{version: string, files: list<string>, enabled: bool, auto: bool, at: string}> $installed */
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

<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

/**
 * The installed modules of this installation (D-041, D-042: one organisation, so a module counts for the whole
 * installation): storage/app/modules/installed.json plus one folder of code per module. A file, not a table, so
 * docHamster knows its modules before the database answers – and a broken module can be switched off by hand.
 *
 * Entry per key: manifest (Steckbrief), source ("owner/repo"), rights (granted), enabled, error (why it was switched
 * off), installed_at, latest (after "Update": version and rights of the newest release).
 */
final class InstalledModules
{
    private static bool $refreshing = false;

    public function __construct(private readonly ?string $root = null) {}

    public function root(): string
    {
        return $this->root ?? storage_path('app/modules');
    }

    /** Where a module's code lives. */
    public function path(string $key): string
    {
        return $this->root().'/'.$key;
    }

    /** @return array<string, array{manifest: array<string, mixed>, source: string, rights: list<string>, enabled: bool, error: ?string, installed_at: string, latest: ?array{version: string, rights: list<string>}}> */
    public function all(): array
    {
        $file = $this->root().'/installed.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return is_array($data) ? $data : [];
    }

    /** @return array{manifest: array<string, mixed>, source: string, rights: list<string>, enabled: bool, error: ?string, installed_at: string, latest: ?array{version: string, rights: list<string>}}|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function manifest(string $key): ?Manifest
    {
        $entry = $this->find($key);
        try {
            return $entry === null ? null : Manifest::from($entry['manifest']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  list<string>  $rights
     */
    public function save(Manifest $manifest, string $source, array $rights): void
    {
        $all = $this->all();
        $all[$manifest->key] = ['manifest' => $manifest->toArray(), 'source' => $source, 'rights' => array_values(array_intersect($rights, $manifest->rights)),
            'enabled' => true, 'error' => null, 'installed_at' => now()->toIso8601String(), 'latest' => null];
        $this->write($all);
    }

    /** A66: switched off with the reason the Software page shows; on again by the owner. */
    public function switchOff(string $key, ?string $error = null): void
    {
        $this->change($key, ['enabled' => false, 'error' => $error === null ? null : mb_substr($error, 0, 300)]);
    }

    public function switchOn(string $key): void
    {
        $this->change($key, ['enabled' => true, 'error' => null]);
    }

    /** @param  array{version: string, rights: list<string>}|null  $latest */
    public function remember(string $key, ?array $latest): void
    {
        $this->change($key, ['latest' => $latest]);
    }

    public function forget(string $key): void
    {
        $all = $this->all();
        unset($all[$key]);
        $this->write($all);
        File::deleteDirectory($this->path($key));
    }

    /** @param  array<string, mixed>  $values */
    private function change(string $key, array $values): void
    {
        $all = $this->all();
        if (isset($all[$key])) {
            $all[$key] = [...$all[$key], ...$values];
            $this->write($all);
        }
    }

    /** @param  array<string, mixed>  $all */
    private function write(array $all): void
    {
        $before = self::running($this->all());
        File::ensureDirectoryExists($this->root());
        $file = $this->root().'/installed.json';
        file_put_contents($file.'.tmp', json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($file.'.tmp', $file);
        if (self::running($all) !== $before) {
            self::refreshRoutes();
        }
    }

    /**
     * The switched-on modules with their versions – what decides which pages exist.
     *
     * @param  array<string, array<string, mixed>>  $all
     */
    private static function running(array $all): string
    {
        $on = [];
        foreach ($all as $key => $entry) {
            if (($entry['enabled'] ?? false) === true) {
                $on[] = $key.'@'.(is_array($entry['manifest'] ?? null) ? (string) ($entry['manifest']['version'] ?? '') : '');
            }
        }
        sort($on);

        return implode(',', $on);
    }

    /**
     * An update prepares the addresses (`php artisan optimize`, point 164.5); a module installed, updated, switched on or
     * off afterwards would miss its pages (404) or keep dead ones. Prepare them again – the fresh list starts the modules
     * as they are now – or, should that fail, go without the prepared list.
     */
    private static function refreshRoutes(): void
    {
        // Preparing starts the modules again; a module switched off while it starts must not prepare once more.
        if (self::$refreshing || ! app()->routesAreCached()) {
            return;
        }
        self::$refreshing = true;
        try {
            Artisan::call('route:cache');
        } catch (Throwable $e) {
            report($e);
            try {
                Artisan::call('route:clear');
            } catch (Throwable) {
                // Nothing more to do: the next update prepares the addresses again.
            }
        } finally {
            self::$refreshing = false;
        }
    }
}

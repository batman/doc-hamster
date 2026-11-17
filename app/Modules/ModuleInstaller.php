<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Puts a checked module.zip in place and takes it out again (D-041) – nothing here talks to the internet (D-020).
 * Unpacks only plain files below the module folder (no "..", no absolute paths, size and count limits), keeps the
 * old version until the new one is complete, copies public/ to public/modules/<key>, runs the module's migrations.
 * Removing keeps the module's data unless the owner ticked "Daten auch löschen" (F18 a): then its tables (prefix
 * m_<key>_) and their migration rows go too.
 */
final class ModuleInstaller
{
    private const MAX_FILES = 2000;

    private const MAX_BYTES = 100 * 1024 * 1024;

    public function __construct(private readonly InstalledModules $installed) {}

    /**
     * The Steckbrief fetched now must be the module the owner agreed to: same key, it fits this docHamster, and it
     * asks for no right beyond the granted ones (an update with new rights asks again, A65/F16).
     *
     * @param  list<string>  $granted
     */
    public function agreed(Manifest $manifest, string $key, array $granted): void
    {
        if ($manifest->key !== $key) {
            throw new RuntimeException(__('modules.other_module'));
        }
        if (! $manifest->fits()) {
            throw new RuntimeException(__('modules.api_mismatch', ['api' => $manifest->api, 'ours' => ModuleApi::VERSION]));
        }
        if (array_diff($manifest->rights, $granted) !== []) {
            throw new RuntimeException(__('modules.new_rights'));
        }
    }

    /** @param list<string> $rights */
    public function install(Manifest $manifest, string $source, array $rights, string $zipFile): void
    {
        $target = $this->installed->path($manifest->key);
        $fresh = $target.'.new';
        File::deleteDirectory($fresh);
        $this->unpack($zipFile, $fresh);
        try {
            $inside = Manifest::from(json_decode((string) @file_get_contents($fresh.'/dochamster-module.json'), true) ?: []);
        } catch (\InvalidArgumentException $e) {
            File::deleteDirectory($fresh);
            throw new RuntimeException($e->getMessage(), previous: $e);
        }
        if ($inside->key !== $manifest->key || $inside->version !== $manifest->version || array_diff($inside->rights, $manifest->rights) !== [] || ! is_file($fresh.'/src/Module.php')) {
            File::deleteDirectory($fresh);
            throw new RuntimeException(__('modules.zip_mismatch'));
        }
        File::deleteDirectory($target.'.old');
        if (is_dir($target)) {
            rename($target, $target.'.old');
        }
        rename($fresh, $target);
        File::deleteDirectory($target.'.old');

        $public = public_path('modules/'.$manifest->key);
        File::deleteDirectory($public);
        if (is_dir($target.'/public')) {
            File::copyDirectory($target.'/public', $public);
        }
        if (is_dir($target.'/database/migrations')) {
            Artisan::call('migrate', ['--path' => $target.'/database/migrations', '--realpath' => true, '--force' => true]);
        }
        $this->installed->save($manifest, $source, $rights);
    }

    public function remove(string $key, bool $withData): void
    {
        $manifest = $this->installed->manifest($key);
        if ($withData && $manifest !== null) {
            $migrations = $this->installed->path($key).'/database/migrations';
            $names = is_dir($migrations) ? array_map(static fn (string $f): string => pathinfo($f, PATHINFO_FILENAME), glob($migrations.'/*.php') ?: []) : [];
            Schema::disableForeignKeyConstraints();
            foreach (Schema::getTableListing() as $table) {
                $plain = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
                if (str_starts_with($plain, $manifest->tablePrefix())) {
                    Schema::drop($plain);
                }
            }
            Schema::enableForeignKeyConstraints();
            if ($names !== []) {
                DB::table('migrations')->whereIn('migration', $names)->delete();
            }
        }
        File::deleteDirectory(public_path('modules/'.$key));
        $this->installed->forget($key);
    }

    private function unpack(string $zipFile, string $to): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException(__('modules.zip_broken'));
        }
        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException(__('modules.zip_broken'));
            }
            $bytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? str_replace('\\', '/', (string) $stat['name']) : '';
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                if (str_starts_with($name, '/') || preg_match('~(^|/)\.\.(/|$)~', $name) === 1 || preg_match('~^[A-Za-z]:~', $name) === 1) {
                    throw new RuntimeException(__('modules.zip_broken'));
                }
                $bytes += (int) $stat['size'];
                if ($bytes > self::MAX_BYTES) {
                    throw new RuntimeException(__('modules.zip_broken'));
                }
                File::ensureDirectoryExists(dirname($to.'/'.$name));
                $stream = $zip->getStream((string) $stat['name']);
                if ($stream === false) {
                    throw new RuntimeException(__('modules.zip_broken'));
                }
                file_put_contents($to.'/'.$name, $stream);
                fclose($stream);
            }
        } catch (RuntimeException $e) {
            File::deleteDirectory($to);
            throw $e;
        } finally {
            $zip->close();
        }
    }
}

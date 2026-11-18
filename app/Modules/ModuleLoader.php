<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * Starts the switched-on modules once per request or job (D-041): their classes load only from their own folder and
 * namespace, their views and texts get the namespace "m-<key>", their migrations join docHamster's, then
 * Module::register() plugs them in. A module whose Steckbrief no longer fits this docHamster, whose entry class is
 * missing or that throws is switched off with the reason – docHamster keeps running (A66).
 */
final class ModuleLoader
{
    public function __construct(private readonly InstalledModules $installed, private readonly ModuleConnections $connections) {}

    public function boot(): void
    {
        foreach ($this->installed->all() as $key => $entry) {
            if (! $entry['enabled']) {
                continue;
            }
            $manifest = $this->installed->manifest((string) $key);
            if ($manifest === null) {
                $this->installed->switchOff((string) $key, __('modules.bad_manifest', ['field' => '–']));

                continue;
            }
            if (! $manifest->fits()) {
                $this->installed->switchOff($manifest->key, __('modules.api_mismatch', ['api' => $manifest->api, 'ours' => ModuleApi::VERSION]));

                continue;
            }
            try {
                $this->start($manifest, $entry['rights']);
            } catch (Throwable $e) {
                Log::warning('Module '.$manifest->key.' switched off: '.$e->getMessage());
                $this->installed->switchOff($manifest->key, __('modules.crashed', ['reason' => $e->getMessage()]));
            }
        }
    }

    /** @param list<string> $rights */
    private function start(Manifest $manifest, array $rights): void
    {
        $path = $this->installed->path($manifest->key);
        self::autoload($manifest->namespace(), $path.'/src');
        if (is_dir($path.'/resources/views')) {
            View::addNamespace('m-'.$manifest->key, $path.'/resources/views');
        }
        if (is_dir($path.'/lang')) {
            app('translator')->addNamespace('m-'.$manifest->key, $path.'/lang');
        }
        if (is_dir($path.'/database/migrations')) {
            app('migrator')->path($path.'/database/migrations');
        }
        $class = $manifest->entry();
        $module = class_exists($class) ? new $class : null;
        if (! $module instanceof Module) {
            throw new ModuleRightMissing(__('modules.no_entry', ['class' => $class]));
        }
        $context = new ModuleContext($manifest, $rights, $this->connections);
        $this->connections->contexts[$manifest->key] = $context;
        $module->register($context);
    }

    /** PSR-4 for one module: only classes of its own namespace, only files below its src folder. */
    private static function autoload(string $prefix, string $dir): void
    {
        spl_autoload_register(static function (string $class) use ($prefix, $dir): void {
            if (! str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
                return;
            }
            $file = $dir.'/'.str_replace('\\', '/', $relative).'.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}

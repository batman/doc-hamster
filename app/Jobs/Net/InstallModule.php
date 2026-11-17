<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use App\Modules\Manifest;
use App\Modules\ModuleInstaller;
use App\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Installs or updates one module from its GitHub project (D-041): asks for the Steckbrief again, checks that it is the
 * one the owner agreed to (same module, no right beyond the granted ones), downloads and checks the zip, then hands
 * it to the ModuleInstaller. Run with dispatchSync in the owner's request, like InstallExtension.
 */
final class InstallModule implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param list<string> $rights the rights the owner granted */
    public function __construct(public readonly string $repo, public readonly string $key, public readonly array $rights)
    {
        $this->onQueue(QueueName::Net->value);
    }

    public function handle(ModuleSource $source, ModuleInstaller $installer): Manifest
    {
        $manifest = $source->find($this->repo);
        $installer->agreed($manifest, $this->key, $this->rights);
        $zip = tempnam(sys_get_temp_dir(), 'dhm');
        try {
            $source->download($this->repo, $manifest, $zip.'.zip');
            $installer->install($manifest, $this->repo, $this->rights, $zip.'.zip');
        } finally {
            @unlink($zip);
            @unlink($zip.'.zip');
        }

        return $manifest;
    }
}

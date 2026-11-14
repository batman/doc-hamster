<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use App\Infrastructure\Extensions\Extensions;
use App\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Einstellungen → Erweiterungen (point 173): installs or updates one catalogue entry; the download lives here (D-020), run with dispatchSync in the request like SetUpOcr. */
final class InstallExtension implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $key)
    {
        $this->onQueue(QueueName::Net->value);
    }

    public function handle(Extensions $extensions): void
    {
        $extensions->install($this->key, SetUpOcr::fetcher());
    }
}

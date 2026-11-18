<?php

declare(strict_types=1);

namespace DocHamsterModules\Revolut;

use App\Modules\ModuleRuntime;
use App\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Everything that talks to Revolut runs as this job on the net queue (D-020): "sync" from the cron every 15 minutes
 * and after an upload; "accounts", "probe", "sync" and "draft" synchronously from the settings page (dispatchSync, the
 * result comes back). A failure is kept as the connection's last error and shown on the page.
 */
final class RevolutJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param array<string, mixed> $data */
    public function __construct(public readonly string $action, public readonly array $data = [])
    {
        $this->onQueue(QueueName::Net->value);
    }

    /**
     * Runs the job right here in the request and hands back its result (dispatchSync would give only a status).
     *
     * @param  array<string, mixed>  $data
     */
    public static function now(string $action, array $data = []): mixed
    {
        return (new self($action, $data))->handle();
    }

    public function handle(): mixed
    {
        return ModuleRuntime::asOwner(function (): mixed {
            $settings = Settings::load();
            try {
                $client = Client::from($settings);
                $sync = new Sync(ModuleRuntime::context('revolut'), $settings, $client);

                return match ($this->action) {
                    'accounts' => $sync->refreshAccounts(),
                    'probe' => $sync->probe(),
                    'draft' => (new Drafts($client))->create($this->data),
                    default => $settings->confirmed() ? $sync->run() : null,
                };
            } catch (Throwable $e) {
                $settings->save(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
                throw $e;
            }
        });
    }
}

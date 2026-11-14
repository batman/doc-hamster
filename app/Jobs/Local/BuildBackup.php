<?php

declare(strict_types=1);

namespace App\Jobs\Local;

use App\Domain\Backup\Backup;
use App\Domain\Backup\BackupBuilder;
use App\Domain\Backup\BackupOptions;
use App\Domain\Backup\JobSecret;
use App\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds a large backup in the background (point 206.3, D-023) on the local queue, with progress in its row. The
 * password arrives sealed (JobSecret) inside an encrypted payload; one try only, and a failure keeps no payload.
 */
final class BuildBackup implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public string $backupId, #[\SensitiveParameter] public string $sealedPassword)
    {
        $this->onQueue(QueueName::Local->value);
    }

    public function handle(): void
    {
        $backup = Backup::query()->find($this->backupId);
        if ($backup === null || $backup->status !== Backup::QUEUED) {
            return;
        }
        $backup->forceFill(['status' => Backup::RUNNING, 'progress' => 0])->save();

        try {
            $password = JobSecret::open($backup->id, $this->sealedPassword);
            $last = 0;
            [$path, $size] = BackupBuilder::toFile($backup->id, $backup->org_id, BackupOptions::from($backup->options ?? []), $backup->created_by, $password,
                static function (int $done, int $total) use ($backup, &$last): void {
                    $percent = (int) floor($done * 99 / max(1, $total));
                    if ($percent >= $last + 2) {
                        $last = $percent;
                        $backup->forceFill(['progress' => $percent])->save();
                    }
                });
            unset($password);
            $backup->forceFill(['status' => Backup::DONE, 'progress' => 100, 'path' => $path, 'size' => $size, 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);
            $backup->forceFill(['status' => Backup::FAILED, 'error' => mb_substr($e->getMessage(), 0, 250), 'finished_at' => now()])->save();
        }
    }

    /** Never keep the (sealed) payload around in failed_jobs. */
    public function failed(?Throwable $e): void
    {
        Backup::query()->whereKey($this->backupId)->whereIn('status', [Backup::QUEUED, Backup::RUNNING])
            ->update(['status' => Backup::FAILED, 'finished_at' => now()]);
    }
}

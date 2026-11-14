<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\Restore\BackupRestorer;
use App\Domain\Backup\Restore\RestoreFailed;
use Illuminate\Console\Command;

/**
 * Restores a full "Backup herunterladen" ZIP into an empty instance (point 206.7, AK-4.8.7). The ZIP password is
 * asked on the console when the backup is protected (--password exists for scripts, but lands in the shell
 * history); the owner's new login likewise.
 */
final class RestoreCommand extends Command
{
    protected $signature = 'dochamster:restore {zip : The backup ZIP} {--password= : ZIP password (better: leave out and type it)} {--owner= : Username of the restored owner} {--owner-password= : Their new password (better: leave out and type it)}';

    protected $description = 'Restore a full backup into an empty instance';

    public function handle(BackupRestorer $restorer): int
    {
        $zip = (string) $this->argument('zip');
        $owner = (string) ($this->option('owner') ?? $this->ask(__('backup.restore.owner')));
        $ownerPassword = (string) ($this->option('owner-password') ?? $this->secret(__('backup.restore.owner_password')));
        $password = $this->option('password');
        $password = is_string($password) && $password !== '' ? $password : null;

        try {
            BackupRestorer::ensureEmpty();
            try {
                $report = $restorer->restore($zip, $password, $owner, $ownerPassword);
            } catch (RestoreFailed $e) {
                if ($e->reason !== 'password_needed' || ! $this->input->isInteractive()) {
                    throw $e;
                }
                $report = $restorer->restore($zip, (string) $this->secret(__('backup.restore.zip_password')), $owner, $ownerPassword);
            }
        } catch (RestoreFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__('backup.restore.done', ['org' => $report->orgName, 'files' => $report->files]));
        foreach ($report->rows as $table => $count) {
            $this->line("  {$table}: {$count}");
        }
        if ($report->skippedPeople !== []) {
            $this->warn(__('backup.restore.skipped', ['names' => implode(', ', $report->skippedPeople)]));
        }
        if ($report->missingFiles !== []) {
            $this->warn(__('backup.restore.missing', ['count' => count($report->missingFiles)]));
        }
        $this->line(__('backup.restore.next'));

        return self::SUCCESS;
    }
}

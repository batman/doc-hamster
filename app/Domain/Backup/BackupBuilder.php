<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Backup\Zip\ArchiveZip;
use App\Domain\Backup\Zip\StreamZip;
use Closure;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Builds a backup: streamed to the browser, or as a file in private storage (with or without password).
 */
final class BackupBuilder
{
    /** Streams the plain ZIP to the response output (no file on the server). */
    public static function stream(string $orgId, BackupOptions $options, ?string $userId): void
    {
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('No output stream.');
        }
        (new BackupWriter(new StreamZip($out), $orgId, $options, $userId))->write();
        fclose($out);
    }

    /**
     * Builds backups/<id>.zip; with a password the outer ZIP holds the encrypted backup.zip (D-023).
     *
     * @param  (Closure(int, int): void)|null  $progress
     * @return array{string, int} path on the local disk, size
     */
    public static function toFile(string $id, string $orgId, BackupOptions $options, ?string $userId, #[\SensitiveParameter] ?string $password = null, ?Closure $progress = null): array
    {
        $dir = Backup::workDir();
        $target = 'backups/'.$id.'.zip';
        $inner = $password === null ? $dir.DIRECTORY_SEPARATOR.$id.'.zip' : $dir.DIRECTORY_SEPARATOR.$id.'.inner.zip';
        try {
            (new BackupWriter(new ArchiveZip($inner), $orgId, $options, $userId, $progress))->write();
            if ($password !== null) {
                ArchiveZip::encrypt($inner, $dir.DIRECTORY_SEPARATOR.$id.'.zip', $password, (string) __('backup.readme_protected'));
            }
        } catch (\Throwable $e) {
            @unlink($inner);
            Storage::disk('local')->delete($target);

            throw $e;
        }

        return [$target, (int) Storage::disk('local')->size($target)];
    }
}

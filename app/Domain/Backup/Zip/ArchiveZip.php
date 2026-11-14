<?php

declare(strict_types=1);

namespace App\Domain\Backup\Zip;

use RuntimeException;
use ZipArchive;

/**
 * A backup ZIP built as a file (background job, or any backup with a password) with PHP's ZipArchive: ZIP64 when
 * needed, data files deflated, receipts stored as they are.
 */
final class ArchiveZip implements ZipSink
{
    private ZipArchive $zip;

    private int $added = 0;

    public function __construct(private readonly string $path)
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup file.');
        }
    }

    public function addString(string $name, string $contents): array
    {
        $this->zip->addFromString($name, $contents);
        $this->zip->setCompressionName($name, ZipArchive::CM_DEFLATE);
        $this->added++;

        return [strlen($contents), hash('sha256', $contents)];
    }

    public function addFile(string $name, string $path): array
    {
        $hash = hash_file('sha256', $path);
        $size = filesize($path);
        if ($hash === false || $size === false || ! $this->zip->addFile($path, $name)) {
            throw new RuntimeException("Could not add {$name}.");
        }
        $this->zip->setCompressionName($name, ZipArchive::CM_STORE);
        // libzip opens every added file only on close – write in between so a big backup never holds too many open.
        if (++$this->added % 500 === 0) {
            $this->reopen();
        }

        return [$size, $hash];
    }

    public function finish(): void
    {
        if (! $this->zip->close()) {
            throw new RuntimeException('Could not write the backup file.');
        }
    }

    /**
     * D-023: AES-256 hides contents, not names – so a protected backup is an outer ZIP with a short neutral
     * LIESMICH.txt and one encrypted entry backup.zip that holds the readable structure. Replaces $inner.
     */
    public static function encrypt(string $inner, string $target, #[\SensitiveParameter] string $password, string $readme): void
    {
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the protected backup file.');
        }
        $zip->addFromString('LIESMICH.txt', $readme);
        $zip->addFile($inner, 'backup.zip');
        $zip->setCompressionName('backup.zip', ZipArchive::CM_STORE);
        if (! $zip->setEncryptionName('backup.zip', ZipArchive::EM_AES_256, $password) || ! $zip->close()) {
            throw new RuntimeException('Could not encrypt the backup.');
        }
        @unlink($inner);
    }

    private function reopen(): void
    {
        $this->finish();
        $this->zip = new ZipArchive;
        if ($this->zip->open($this->path) !== true) {
            throw new RuntimeException('Could not reopen the backup file.');
        }
    }
}

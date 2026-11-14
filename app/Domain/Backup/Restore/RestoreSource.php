<?php

declare(strict_types=1);

namespace App\Domain\Backup\Restore;

use App\Domain\Backup\Backup;
use App\Domain\Backup\BackupWriter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Opens an untrusted backup ZIP and stages its files – after ZipGuard and the manifest – under generated names in
 * a private folder, each checked against its manifest size and SHA-256 while it is copied. A protected backup
 * (outer ZIP + encrypted backup.zip) is unpacked once with the password first. delete() removes everything.
 */
final class RestoreSource
{
    private const int CHUNK = 1 << 20;

    public readonly string $dir;

    public Manifest $manifest;

    /** @var array<string, string> ZIP name => staged absolute path */
    private array $staged = [];

    /**
     * @param  string|null  $schema  this instance's schema version (default: the latest migration that ran)
     */
    public function __construct(private readonly string $zipPath, #[\SensitiveParameter] private readonly ?string $password = null, private readonly ?string $schema = null)
    {
        $this->dir = Backup::workDir().DIRECTORY_SEPARATOR.'restore-'.Str::lower((string) Str::ulid());
        File::ensureDirectoryExists($this->dir, 0700);
    }

    public function open(): self
    {
        $zip = $this->zip($this->zipPath);
        try {
            $limit = (int) config('dochamster.backup.restore_max_bytes');
            $entries = ZipGuard::entries($zip, [...ZipGuard::BACKUP_ROOT, ...ZipGuard::PROTECTED_ROOT], self::maxEntries(), $limit, self::maxRatio());
            if (isset($entries['backup.zip']) && ! isset($entries['manifest.json'])) {
                $inner = $this->unpackProtected($zip, $entries, $limit);
                $zip->close();
                $zip = $this->zip($inner);
                $entries = ZipGuard::entries($zip, ZipGuard::BACKUP_ROOT, self::maxEntries(), $limit, self::maxRatio());
            } elseif (isset($entries['backup.zip'])) {
                throw new RestoreFailed('unknown_entry', ['name' => 'backup.zip']);
            }
            if (! isset($entries['manifest.json']) || $entries['manifest.json']['size'] > 50 * 1024 * 1024) {
                throw new RestoreFailed('manifest');
            }
            $this->manifest = Manifest::parse((string) $zip->getFromIndex($entries['manifest.json']['index']), $entries, $this->schema ?? BackupWriter::schemaVersion());

            // Zip bomb: the ZIP may not unpack to more than twice what its manifest promises.
            $declared = array_sum(array_column($entries, 'size'));
            if ($declared > min($limit, 2 * $this->manifest->totalBytes() + 50 * 1024 * 1024)) {
                throw new RestoreFailed('too_large');
            }
            $free = @disk_free_space($this->dir);
            if ($free !== false && $free < $declared * 1.2) {
                throw new RestoreFailed('disk');
            }

            $n = 0;
            foreach ($this->manifest->files as $name => $file) {
                $target = $this->dir.DIRECTORY_SEPARATOR.(++$n).'.bin';
                $this->copy($zip, $entries[$name]['index'], $target, $file['size'], $file['sha256'], $name);
                $this->staged[$name] = $target;
            }
        } catch (\Throwable $e) {
            $this->delete();

            throw $e;
        } finally {
            try {
                $zip->close();
            } catch (\Throwable) {
                // already closed (protected backup: the outer ZIP)
            }
        }

        return $this;
    }

    /** The staged copy of a ZIP entry (only names the manifest lists). */
    public function path(string $name): string
    {
        return $this->staged[$name] ?? throw new RestoreFailed('missing_file', ['name' => $name]);
    }

    public function delete(): void
    {
        File::deleteDirectory($this->dir);
    }

    private function zip(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if (! is_file($path) || is_link($path) || $zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RestoreFailed('unreadable');
        }

        return $zip;
    }

    /**
     * @param  array<string, array{index: int, size: int, encrypted: bool}>  $entries
     */
    private function unpackProtected(ZipArchive $zip, array $entries, int $limit): string
    {
        if (array_diff(array_keys($entries), ZipGuard::PROTECTED_ROOT) !== [] || ! $entries['backup.zip']['encrypted']) {
            throw new RestoreFailed('unknown_entry', ['name' => 'backup.zip']);
        }
        if ($this->password === null || $this->password === '') {
            throw new RestoreFailed('password_needed');
        }
        $zip->setPassword($this->password);
        $target = $this->dir.DIRECTORY_SEPARATOR.'inner.zip';
        $this->copy($zip, $entries['backup.zip']['index'], $target, min($limit, $entries['backup.zip']['size']), null, 'backup.zip');

        return $target;
    }

    /** Copies one entry, never more than $size bytes, and compares the checksum. */
    private function copy(ZipArchive $zip, int $index, string $target, int $size, ?string $sha256, string $name): void
    {
        $in = $zip->getStreamIndex($index);
        if ($in === false) {
            throw new RestoreFailed($name === 'backup.zip' ? 'password_wrong' : 'unreadable');
        }
        $out = fopen($target, 'xb');
        if ($out === false) {
            fclose($in);
            throw new RestoreFailed('unreadable');
        }
        $hash = hash_init('sha256');
        $written = 0;
        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) {
                    throw new RestoreFailed($name === 'backup.zip' ? 'password_wrong' : 'unreadable');
                }
                $written += strlen($chunk);
                if ($written > $size) {
                    throw new RestoreFailed('checksum', ['name' => $name]);
                }
                hash_update($hash, $chunk);
                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        if ($written !== $size || ($sha256 !== null && ! hash_equals($sha256, hash_final($hash)))) {
            throw new RestoreFailed($name === 'backup.zip' ? 'password_wrong' : 'checksum', ['name' => $name]);
        }
    }

    private static function maxEntries(): int
    {
        return (int) config('dochamster.backup.restore_max_entries');
    }

    private static function maxRatio(): int
    {
        return (int) config('dochamster.backup.restore_max_ratio');
    }
}

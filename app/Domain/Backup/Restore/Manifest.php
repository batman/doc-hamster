<?php

declare(strict_types=1);

namespace App\Domain\Backup\Restore;

use App\Domain\Backup\BackupWriter;

/**
 * manifest.json of a backup, checked before anything else (D-023): its format, the schema version (a newer one is
 * refused), and that it lists exactly the ZIP's files with their sizes. It proves integrity, not authenticity –
 * the content is checked again on the way in (BackupRestorer).
 */
final readonly class Manifest
{
    public const string ULID = '/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/';

    private const array TYPES = ['data', 'csv', 'readme', 'original', 'derived', 'pack'];

    /**
     * @param  array<string, array{size: int, sha256: string, type: string, file_id: ?string}>  $files
     */
    private function __construct(
        public string $schemaVersion,
        public string $appVersion,
        public string $orgId,
        public ?string $createdBy,
        public bool $full,
        public array $files,
    ) {}

    /**
     * @param  array<string, array{index: int, size: int, encrypted: bool}>  $entries  the ZIP's files (ZipGuard)
     */
    public static function parse(string $json, array $entries, string $localSchema): self
    {
        $data = json_decode($json, true, 16);
        if (! is_array($data) || ($data['format'] ?? null) !== BackupWriter::FORMAT || ! is_int($data['format_version'] ?? null)
            || ! is_array($data['files'] ?? null) || ! is_string($data['schema_version'] ?? null) || ! is_string($data['org_id'] ?? null)) {
            throw new RestoreFailed('manifest');
        }
        if ($data['format_version'] > BackupWriter::FORMAT_VERSION || strcmp($data['schema_version'], $localSchema) > 0) {
            throw new RestoreFailed('newer', ['version' => is_string($data['app_version'] ?? null) ? $data['app_version'] : '?']);
        }
        if (preg_match(self::ULID, $data['org_id']) !== 1 || (($data['created_by'] ?? null) !== null && (! is_string($data['created_by']) || preg_match(self::ULID, $data['created_by']) !== 1))) {
            throw new RestoreFailed('manifest');
        }

        $files = [];
        foreach ($data['files'] as $file) {
            $path = is_array($file) ? ($file['path'] ?? null) : null;
            $fileId = is_array($file) ? ($file['file_id'] ?? null) : null;
            if (! is_array($file) || ! is_string($path) || ! is_int($file['size'] ?? null) || $file['size'] < 0
                || ! is_string($file['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $file['sha256']) !== 1
                || ! in_array($file['type'] ?? null, self::TYPES, true) || ($fileId !== null && (! is_string($fileId) || preg_match(self::ULID, $fileId) !== 1))
                || $path === 'manifest.json' || isset($files[$path])) {
                throw new RestoreFailed('manifest');
            }
            ZipGuard::checkName($path, ZipGuard::BACKUP_ROOT);
            if (! isset($entries[$path])) {
                throw new RestoreFailed('missing_file', ['name' => $path]);
            }
            if ($entries[$path]['size'] !== $file['size']) {
                throw new RestoreFailed('checksum', ['name' => $path]);
            }
            $files[$path] = ['size' => $file['size'], 'sha256' => $file['sha256'], 'type' => $file['type'], 'file_id' => $fileId];
        }
        foreach (array_keys($entries) as $name) {
            if ($name !== 'manifest.json' && ! isset($files[$name])) {
                throw new RestoreFailed('unlisted', ['name' => $name]);
            }
        }

        return new self($data['schema_version'], is_string($data['app_version'] ?? null) ? $data['app_version'] : '', $data['org_id'],
            $data['created_by'] ?? null, ($data['full'] ?? false) === true, $files);
    }

    /** Sum of all listed sizes – the restore extracts at most twice this (zip-bomb limit). */
    public function totalBytes(): int
    {
        return (int) array_sum(array_column($this->files, 'size'));
    }
}

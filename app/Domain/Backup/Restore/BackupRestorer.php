<?php

declare(strict_types=1);

namespace App\Domain\Backup\Restore;

use App\Domain\Backup\BackupTables;
use finfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Restores a full backup into an EMPTY instance (point 206.7, AK-4.8.7) – the service behind
 * `php artisan dochamster:restore`, ready for a later restore step in the setup wizard. The ZIP is untrusted:
 * RestoreSource checks names, limits, manifest and checksums first; rows and files are checked again here; nothing
 * reaches the database before all of that passed, and then everything goes in one transaction with the original IDs.
 *
 * People: the backup's owner (the one who made it, else the first owner) comes back as the owner with the username
 * and password given here; members without an account here are skipped and reported – their notes go to the owner.
 */
final class BackupRestorer
{
    /** File types a restore accepts – the same as an upload plus the app's own text documents. */
    private const array BINARY = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'application/pdf' => 'pdf'];

    private const array TEXT = ['text/plain' => 'txt', 'text/markdown' => 'md', 'text/html' => 'html'];

    public function restore(string $zipPath, #[\SensitiveParameter] ?string $zipPassword, string $ownerUsername, #[\SensitiveParameter] string $ownerPassword): RestoreReport
    {
        $ownerUsername = mb_strtolower(trim($ownerUsername));
        if (preg_match('/^[a-z0-9._-]{3,64}$/', $ownerUsername) !== 1 || mb_strlen($ownerPassword) < 12) {
            throw new RestoreFailed('owner');
        }
        self::ensureEmpty();

        $source = (new RestoreSource($zipPath, $zipPassword))->open();
        $written = [];
        try {
            $manifest = $source->manifest;
            if (! $manifest->full) {
                throw new RestoreFailed('not_full');
            }
            $orgId = $manifest->orgId;
            $tables = [];
            foreach (array_keys(BackupTables::TABLES) as $table) {
                $name = 'data/'.$table.'.json';
                if (isset($manifest->files[$name])) {
                    $tables[$table] = RestoreRows::read($table, $source->path($name), $orgId);
                }
            }
            if (count($tables['organizations'] ?? []) !== 1) {
                throw new RestoreFailed('content', ['name' => 'organizations']);
            }

            [$owner, $skipped] = self::people($tables, $manifest->createdBy);
            $tables['users'] = [[...$owner, 'username' => $ownerUsername, 'password_hash' => Hash::make($ownerPassword)]];
            foreach ($tables as $table => $rows) {
                $tables[$table] = RestoreRows::mapPeople($table, $rows, $skipped['ids'], (string) $owner['id']);
            }
            $tables['folders'] = RestoreRows::parentsFirst($tables['folders'] ?? []);

            [$tables['document_files'], $missing] = $this->files($source, $tables['document_files'] ?? [], $orgId, $written);

            DB::transaction(static function () use ($tables): void {
                self::ensureEmpty();
                foreach ($tables as $table => $rows) {
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($written);

            throw $e;
        } finally {
            $source->delete();
        }

        return new RestoreReport($orgId, (string) ($tables['organizations'][0]['name'] ?? ''), (string) $owner['id'],
            array_map('count', $tables), $skipped['names'], $missing, count($written));
    }

    /** D-023: only into an instance without any organisation, person or document. */
    public static function ensureEmpty(): void
    {
        foreach (['organizations', 'users', 'documents'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RestoreFailed('not_empty');
            }
        }
    }

    /**
     * @param  array<string, list<array<string, scalar|null>>>  $tables
     * @return array{array<string, scalar|null>, array{ids: array<string, true>, names: list<string>}}
     */
    private static function people(array $tables, ?string $createdBy): array
    {
        $owners = array_values(array_filter($tables['memberships'] ?? [], static fn (array $m): bool => ($m['role'] ?? null) === 'owner' && ($m['revoked_at'] ?? null) === null));
        $ownerIds = array_map(static fn (array $m): string => (string) $m['user_id'], $owners);
        $ownerId = $createdBy !== null && in_array($createdBy, $ownerIds, true) ? $createdBy : ($ownerIds[0] ?? null);
        $owner = null;
        $skipped = ['ids' => [], 'names' => []];
        foreach ($tables['users'] ?? [] as $user) {
            if ($user['id'] === $ownerId) {
                $owner = $user;
            } else {
                $skipped['ids'][(string) $user['id']] = true;
                $skipped['names'][] = (string) ($user['display_name'] ?? $user['id']);
            }
        }
        if ($owner === null) {
            throw new RestoreFailed('no_owner');
        }

        return [$owner, $skipped];
    }

    /**
     * Writes the receipts through the normal storage under generated names; type, size and checksum come from the
     * bytes, never from the JSON. A file row whose file is not in the backup is left out and reported.
     *
     * @param  list<array<string, scalar|null>>  $rows
     * @param  list<string>  $written
     * @return array{list<array<string, scalar|null>>, list<string>}
     */
    private function files(RestoreSource $source, array $rows, string $orgId, array &$written): array
    {
        $byId = [];
        foreach ($source->manifest->files as $name => $file) {
            if ($file['type'] === 'original' && $file['file_id'] !== null) {
                $byId[$file['file_id']] = $name;
            }
        }
        $disk = Storage::disk('local');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $kept = [];
        $missing = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if (! isset($byId[$id])) {
                $missing[] = $id;

                continue;
            }
            $staged = $source->path($byId[$id]);
            $detected = (string) $finfo->file($staged);
            $claimed = (string) ($row['mime'] ?? '');
            if (isset(self::BINARY[$detected])) {
                [$mime, $extension] = [$detected, self::BINARY[$detected]];
            } elseif (isset(self::TEXT[$claimed]) && (str_starts_with($detected, 'text/') || $detected === 'application/x-empty' || $detected === 'inode/x-empty')) {
                [$mime, $extension] = [$claimed, self::TEXT[$claimed]];
            } else {
                throw new RestoreFailed('file_type', ['name' => $byId[$id]]);
            }
            $path = $orgId.'/restored/'.strtolower($id).'.'.$extension;
            $stream = fopen($staged, 'rb');
            if ($stream === false || ! $disk->writeStream($path, $stream)) {
                throw new RestoreFailed('unreadable');
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
            $written[] = $path;
            $kept[] = [...$row, 'path' => $path, 'mime' => $mime, 'size' => (int) filesize($staged), 'sha256' => $source->manifest->files[$byId[$id]]['sha256'], 'thumb_path' => null];
        }

        return [$kept, $missing];
    }
}

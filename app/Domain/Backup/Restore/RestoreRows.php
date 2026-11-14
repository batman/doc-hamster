<?php

declare(strict_types=1);

namespace App\Domain\Backup\Restore;

use App\Domain\Backup\BackupTables;
use Illuminate\Support\Facades\Schema;

/**
 * Reads and checks the JSON rows of a backup (D-023 "Content"): every file is a list of flat records, column names
 * are plain and known to this instance (unknown ones from an older version are dropped, secret ones always), IDs
 * are ULIDs, and every row is put into the organisation of the manifest.
 */
final class RestoreRows
{
    /** Columns that hold an ID (other *_id columns, e.g. a bank's booking_id, are free text). */
    private const array ID_COLUMNS = [
        'id', 'org_id', 'user_id', 'document_id', 'tag_id', 'note_id', 'folder_id', 'parent_id', 'column_id', 'transaction_id',
        'bank_account_id', 'property_id', 'reason_id', 'no_receipt_reason_id', 'suggested_reason_id', 'standing_id', 'org_role_id',
        'uploaded_by', 'decided_by', 'created_by', 'used_by',
    ];

    private const int MAX_STRING = 64 * 1024 * 1024;

    /**
     * @return list<array<string, scalar|null>>
     */
    public static function read(string $table, string $path, string $orgId): array
    {
        $json = file_get_contents($path);
        $rows = $json === false ? null : json_decode($json, true, 4);
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RestoreFailed('content', ['name' => $table]);
        }
        $columns = array_flip(Schema::getColumnListing($table));
        $clean = [];
        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row) && $row !== []) {
                throw new RestoreFailed('content', ['name' => $table]);
            }
            $out = [];
            foreach ($row as $column => $value) {
                if (! is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $column) !== 1) {
                    throw new RestoreFailed('content', ['name' => $table]);
                }
                if (! isset($columns[$column]) || BackupTables::isSecret($column)) {
                    continue;
                }
                if ($value !== null && ! is_scalar($value) || is_string($value) && strlen($value) > self::MAX_STRING) {
                    throw new RestoreFailed('content', ['name' => $table]);
                }
                if ($value !== null && in_array($column, self::ID_COLUMNS, true) && (! is_string($value) || preg_match(Manifest::ULID, $value) !== 1)) {
                    throw new RestoreFailed('content', ['name' => $table]);
                }
                $out[$column] = $value;
            }
            if ($table === 'organizations') {
                if (($out['id'] ?? null) !== $orgId) {
                    throw new RestoreFailed('content', ['name' => $table]);
                }
            } elseif (isset($columns['org_id'])) {
                $out['org_id'] = $orgId;
            }
            $clean[] = $out;
        }

        return $clean;
    }

    /**
     * Folders reference their parent: parents first, so the foreign key holds while inserting.
     *
     * @param  list<array<string, scalar|null>>  $rows
     * @return list<array<string, scalar|null>>
     */
    public static function parentsFirst(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) ($row['id'] ?? '')] = $row;
        }
        $sorted = [];
        $placed = [];
        $place = static function (string $id, int $depth) use (&$place, &$sorted, &$placed, $byId): void {
            if (isset($placed[$id]) || ! isset($byId[$id]) || $depth > 64) {
                return;
            }
            $parent = $byId[$id]['parent_id'] ?? null;
            if (is_string($parent) && $parent !== $id) {
                $place($parent, $depth + 1);
            }
            $placed[$id] = true;
            $sorted[] = $byId[$id];
        };
        foreach (array_keys($byId) as $id) {
            $place((string) $id, 0);
        }
        // A cycle or a parent that isn't there: inserted last, the foreign key then stops the restore.
        foreach ($byId as $id => $row) {
            if (! isset($placed[$id])) {
                $sorted[] = $row;
            }
        }

        return $sorted;
    }
}

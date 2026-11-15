<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables a backup holds, in restore order (parents first). An allowlist: devices, passkeys, device keys,
 * printers, invitations, share links, sessions, jobs and logs are never part of it (point 206.5, AK-4.8.6), and
 * secret-looking columns are dropped from every row as a second line of defence.
 */
final class BackupTables
{
    /** table => [scope, sections it needs] – an empty list: always in. */
    public const array TABLES = [
        'organizations' => ['self', []],
        'users' => ['members', []],
        'roles' => ['org', []],
        'memberships' => ['org', []],
        'tags' => ['org', [BackupOptions::SETTINGS]],
        'properties' => ['org', [BackupOptions::SETTINGS]],
        'palette_colors' => ['org', [BackupOptions::SETTINGS]],
        'sender_hints' => ['org', [BackupOptions::SETTINGS]],
        'ocr_learned' => ['org', [BackupOptions::SETTINGS]],
        'user_dashboards' => ['org', [BackupOptions::SETTINGS]],
        'folders' => ['org', [BackupOptions::DOCUMENTS]],
        'documents' => ['documents', [BackupOptions::DOCUMENTS]],
        'document_files' => ['docs', [BackupOptions::DOCUMENTS]],
        'document_properties' => ['docs', [BackupOptions::DOCUMENTS, BackupOptions::SETTINGS]],
        'document_translations' => ['docs', [BackupOptions::DOCUMENTS]],
        'document_tags' => ['doc_pivot', [BackupOptions::DOCUMENTS, BackupOptions::SETTINGS]],
        'standing_documents' => ['docs', [BackupOptions::DOCUMENTS, BackupOptions::BANK]],
        'bank_accounts' => ['org', [BackupOptions::BANK]],
        'bank_imports' => ['org', [BackupOptions::BANK]],
        'no_receipt_reasons' => ['org', [BackupOptions::BANK]],
        'no_receipt_rules' => ['org', [BackupOptions::BANK]],
        'bank_mcc_tags' => ['org', [BackupOptions::BANK]],
        'transactions' => ['transactions', [BackupOptions::BANK]],
        'matches' => ['tx_docs', [BackupOptions::BANK, BackupOptions::DOCUMENTS]],
        'match_rejections' => ['tx_docs', [BackupOptions::BANK, BackupOptions::DOCUMENTS]],
        // F36 (point 215): the finished Belegpakete – rows and PDFs (BackupWriter, BackupRestorer).
        'receipt_packs' => ['packs', [BackupOptions::BANK, BackupOptions::DOCUMENTS]],
        'note_columns' => ['org', [BackupOptions::NOTES]],
        'notes' => ['org', [BackupOptions::NOTES]],
        'note_documents' => ['note_docs', [BackupOptions::NOTES, BackupOptions::DOCUMENTS]],
        'note_tags' => ['note_pivot', [BackupOptions::NOTES, BackupOptions::SETTINGS]],
    ];

    /** Never exported, whatever the table: hashes, tokens, keys, credentials, remember tokens. */
    private const string SECRET_COLUMN = '/(password|token|secret|credential|private|public_key|api_key|_hash$)/';

    /** Columns that point at a person (restore maps people who have no account here). */
    public const array USER_COLUMNS = ['user_id', 'uploaded_by', 'decided_by', 'created_by', 'used_by'];

    /**
     * The tables this choice holds that exist here.
     *
     * @return list<string>
     */
    public static function chosen(BackupOptions $options): array
    {
        $tables = [];
        foreach (self::TABLES as $table => [, $needs]) {
            if (array_diff($needs, $options->sections) === [] && Schema::hasTable($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    public static function isSecret(string $column): bool
    {
        return preg_match(self::SECRET_COLUMN, $column) === 1;
    }

    /** The rows of one table for this organisation and choice, in a stable order. */
    public static function query(string $table, string $orgId, BackupOptions $options): Builder
    {
        $scope = self::TABLES[$table][0];
        $query = DB::table($table);

        match ($scope) {
            'self' => $query->where('id', $orgId),
            'members' => $query->whereIn('id', DB::table('memberships')->select('user_id')->where('org_id', $orgId)),
            'org' => $query->where('org_id', $orgId),
            'documents' => self::period($query->where('org_id', $orgId), $options),
            'docs' => $query->where('org_id', $orgId)->whereIn('document_id', self::documentIds($orgId, $options)),
            'doc_pivot' => $query->whereIn('document_id', self::documentIds($orgId, $options)),
            'transactions' => self::bookings($query->where('org_id', $orgId), $options),
            'tx_docs' => $query->where('org_id', $orgId)->whereIn('document_id', self::documentIds($orgId, $options))
                ->whereIn('transaction_id', self::bookings(DB::table('transactions')->select('id')->where('org_id', $orgId), $options)),
            'note_docs' => $query->whereIn('note_id', DB::table('notes')->select('id')->where('org_id', $orgId))
                ->whereIn('document_id', self::documentIds($orgId, $options)),
            'note_pivot' => $query->whereIn('note_id', DB::table('notes')->select('id')->where('org_id', $orgId)),
            'packs' => $query->where('org_id', $orgId)->where('status', 'done')->whereNotNull('path'),
        };

        $columns = Schema::getColumnListing($table);
        $order = in_array('id', $columns, true) ? ['id'] : array_values(array_filter($columns, static fn (string $c): bool => str_ends_with($c, '_id')));
        foreach ($order as $column) {
            $query->orderBy($column);
        }

        return $query->select(array_values(array_filter($columns, static fn (string $c): bool => ! self::isSecret($c))));
    }

    /** The documents of the choice (trash included), as a sub-query of ids. */
    public static function documentIds(string $orgId, BackupOptions $options): Builder
    {
        return self::period(DB::table('documents')->select('id')->where('org_id', $orgId), $options);
    }

    /** A document belongs to the period by its document date, else by its upload date (D-023). */
    private static function period(Builder $query, BackupOptions $options): Builder
    {
        if ($options->from === null && $options->to === null) {
            return $query;
        }
        $from = $options->from?->toDateString() ?? '0001-01-01';
        $to = $options->to?->toDateString() ?? '9999-12-31';

        return $query->where(static fn (Builder $q) => $q->whereBetween('doc_date', [$from, $to])
            ->orWhere(static fn (Builder $q) => $q->whereNull('doc_date')->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)));
    }

    private static function bookings(Builder $query, BackupOptions $options): Builder
    {
        if ($options->from !== null) {
            $query->where('booking_date', '>=', $options->from->toDateString());
        }
        if ($options->to !== null) {
            $query->where('booking_date', '<=', $options->to->toDateString());
        }

        return $query;
    }
}

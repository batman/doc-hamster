<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use Illuminate\Support\Facades\DB;

/**
 * The two lists for people (D-023): documents and bookings as CSV – `;`, UTF-8 with BOM, so Excel opens them.
 * The JSON under data/ stays the complete record; these are only a readable view.
 */
final class BackupCsv
{
    public static function documents(string $orgId, BackupOptions $options): string
    {
        $tags = $options->has(BackupOptions::SETTINGS)
            ? DB::table('document_tags as dt')->join('tags as t', 't.id', '=', 'dt.tag_id')->where('t.org_id', $orgId)
                ->whereIn('dt.document_id', BackupTables::documentIds($orgId, $options))->get(['dt.document_id', 't.name'])
                ->groupBy('document_id')->map(static fn ($rows): string => $rows->pluck('name')->implode(', '))->all()
            : [];
        $folders = DB::table('folders')->where('org_id', $orgId)->pluck('name', 'id')->all();

        $rows = [['ID', 'Titel', 'Belegdatum', 'Hochgeladen', 'Ordner', 'Tags', 'Im Papierkorb']];
        foreach (DB::table('documents')->whereIn('id', BackupTables::documentIds($orgId, $options))->orderBy('created_at')->orderBy('id')
            ->cursor() as $doc) {
            $rows[] = [$doc->id, $doc->title, $doc->doc_date, $doc->created_at, $folders[$doc->folder_id ?? ''] ?? '', $tags[$doc->id] ?? '', ($doc->deleted_at ?? null) !== null ? 'ja' : ''];
        }

        return self::csv($rows);
    }

    public static function transactions(string $orgId, BackupOptions $options): string
    {
        $accounts = DB::table('bank_accounts')->where('org_id', $orgId)->pluck('name', 'id')->all();
        $rows = [['ID', 'Konto', 'Buchungstag', 'Betrag', 'Währung', 'Gegenpartei', 'Verwendungszweck']];
        foreach (BackupTables::query('transactions', $orgId, $options)->reorder()->orderBy('booking_date')->orderBy('id')->cursor() as $tx) {
            $rows[] = [$tx->id, $accounts[$tx->bank_account_id] ?? '', $tx->booking_date, self::amount((int) $tx->amount_minor), $tx->currency, $tx->counterparty, $tx->purpose];
        }

        return self::csv($rows);
    }

    /** Minor units as "-12,34" (no float on the way). */
    public static function amount(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, 100).','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'w+b');
        if ($out === false) {
            return '';
        }
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            // A leading = + @ (or - before no digit) would run as a formula in a spreadsheet: a quote keeps it text.
            fputcsv($out, array_map(static fn (mixed $v): string => preg_replace('/^([=+@\t\r]|-(?!\d))/', "'$1", (string) $v) ?? '', $row), ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}

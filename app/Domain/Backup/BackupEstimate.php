<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The expected size before the start (AK-4.8.3, D-023): the stored sizes of the chosen files (they go into the ZIP
 * uncompressed) plus the JSON and CSV – row counts × an average row, documents with their recognised text.
 */
final class BackupEstimate
{
    /** Average JSON row and ZIP entry overhead (bytes). */
    private const int ROW = 450;

    private const int ENTRY = 160;

    /** A thumbnail's typical size; their sizes are not stored. */
    private const int THUMB = 25_000;

    /**
     * @return array{bytes: int, files: int}
     */
    public static function of(string $orgId, BackupOptions $options): array
    {
        $bytes = 4_000;
        $entries = 3;
        foreach (BackupTables::chosen($options) as $table) {
            $bytes += BackupTables::query($table, $orgId, $options)->reorder()->count() * self::ROW + self::ENTRY;
            $entries++;
        }

        if ($options->has(BackupOptions::DOCUMENTS)) {
            $ids = BackupTables::documentIds($orgId, $options);
            $text = array_values(array_filter(['ocr_text', 'ocr_words', 'note'], static fn (string $c): bool => Schema::hasColumn('documents', $c)));
            if ($text !== []) {
                $sum = implode(' + ', array_map(static fn (string $c): string => 'COALESCE(LENGTH('.$c.'), 0)', $text));
                $bytes += (int) DB::table('documents')->whereIn('id', $ids)->sum(DB::raw($sum));
            }
            $files = DB::table('document_files')->where('org_id', $orgId)->whereIn('document_id', $ids);
            $count = (clone $files)->count();
            $bytes += (int) (clone $files)->sum('size') + $count * (self::ENTRY + 60);
            $entries += $count;
            if ($options->derived) {
                $thumbs = (clone $files)->whereNotNull('thumb_path')->count();
                $bytes += $thumbs * (self::THUMB + self::ENTRY);
                $entries += $thumbs;
            }
            // The manifest lists every file once more.
            $bytes += $entries * 160 + $count * 200;
        }

        return ['bytes' => $bytes, 'files' => $entries];
    }
}

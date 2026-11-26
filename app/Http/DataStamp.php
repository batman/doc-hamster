<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Org\CurrentOrg;
use Illuminate\Support\Facades\DB;

/**
 * Point 39 (A100): a short fingerprint of what the windows show – per table the number of rows and the newest change.
 * The badge poll (about once a minute, F25) carries it; when it moves, the open windows load their content again.
 */
final class DataStamp
{
    private const array TABLES = ['documents', 'document_files', 'folders', 'transactions', 'matches', 'assistant_proposals'];

    public static function of(CurrentOrg $org): string
    {
        if (! $org->isSet()) {
            return '';
        }
        // One query for all tables (the badge poll stays small, QueryBudgetTest).
        $query = null;
        foreach (self::TABLES as $table) {
            $part = DB::table($table)->where('org_id', $org->id())->selectRaw('? as t, count(*) as n, max(updated_at) as u', [$table]);
            $query = $query === null ? $part : $query->unionAll($part);
        }
        $parts = [];
        foreach ($query->get() as $row) {
            $parts[] = $row->t.':'.$row->n.':'.$row->u;
        }
        sort($parts);

        return substr(hash('xxh128', implode('|', $parts)), 0, 16);
    }
}

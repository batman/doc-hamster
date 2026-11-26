<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Audit\AuditLogEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Posteingang (point 153): everything that came in and nobody handled yet, from every source.
 *
 * A358: what comes in has no folder (folder_id null) and waits here until it is filed – filed = has a folder = done.
 * It leaves when someone drops it on a folder, picks one with "Ablegen in …" (A360), or marks it (row ✓ and
 * "Alle erledigt" file it into "Dokumente allgemein"). Opening it changes nothing. Unfiled documents show nowhere else
 * in Dokumente: only here and in searches, timeline and calendar (the Dokumente top level lists folders only).
 * The source is the column documents.source (upload|printer|mail|phone|assistant|api, null = upload; point 45: assistant = uploaded by an assistant); before that column
 * exists, printed documents are known by in_print_tray and everything else counts as upload.
 */
final class Inbox
{
    /** Every source, in the order of the rows' icons; "api" = sent by another program. */
    public const array SOURCES = ['upload', 'printer', 'mail', 'phone', 'assistant', 'api'];

    /** The tabs besides "Alle" (A327: Handy = shared from a phone). */
    public const array TABS = ['upload', 'printer', 'mail', 'phone', 'assistant'];

    /** @var array<string, string> Tabler icon per source */
    public const array ICONS = ['upload' => 'upload', 'printer' => 'printer', 'mail' => 'mail', 'phone' => 'device-mobile', 'assistant' => 'plug', 'api' => 'plug'];

    private static ?bool $sourceColumn = null;

    /**
     * What waits in the inbox for this person (PLAN §2.1 visibility), optionally of one source.
     *
     * @return Builder<Document>
     */
    public static function query(string $userId, bool $seesAll, ?string $source = null): Builder
    {
        $query = Document::query()->visibleTo($userId, $seesAll)->whereNull('documents.folder_id');

        return $source !== null ? self::ofSource($query, $source) : $query;
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public static function ofSource(Builder $query, string $source): Builder
    {
        $column = self::hasSourceColumn();

        return match ($source) {
            'printer' => $query->where(static fn (Builder $inner): Builder => $column
                ? $inner->where('documents.in_print_tray', true)->orWhere('documents.source', 'printer')
                : $inner->where('documents.in_print_tray', true)),
            'upload' => $query->where('documents.in_print_tray', false)->when($column, static fn (Builder $inner): Builder => $inner
                ->where(static fn (Builder $or): Builder => $or->whereNull('documents.source')->orWhere('documents.source', 'upload'))),
            default => $column ? $query->where('documents.source', $source) : $query->whereRaw('1 = 0'),
        };
    }

    /** Where this document came from. */
    public static function sourceOf(Document $document): string
    {
        $source = self::hasSourceColumn() ? $document->getAttribute('source') : null;
        if (is_string($source) && in_array($source, self::SOURCES, true)) {
            return $source;
        }

        return $document->in_print_tray ? 'printer' : 'upload';
    }

    /**
     * New since the person last looked, per source (the tabs' red counts). Own browser uploads never count:
     * the person just did that.
     *
     * @param  array<string, Carbon|null>  $seen  source => last visit (null: never)
     * @return array<string, int>
     */
    public static function newCounts(string $userId, bool $seesAll, array $seen): array
    {
        // Point 164.4: one query for all sources (a conditional sum each) instead of one count per source.
        $column = self::hasSourceColumn();
        $query = self::query($userId, $seesAll)->toBase();
        $query->columns = [];
        foreach (self::SOURCES as $source) {
            [$sql, $bindings] = match ($source) {
                'printer' => [$column ? '(documents.in_print_tray = ? or documents.source = ?)' : 'documents.in_print_tray = ?', $column ? [true, 'printer'] : [true]],
                'upload' => ['documents.in_print_tray = ?'.($column ? ' and (documents.source is null or documents.source = ?)' : '')
                    .' and (documents.uploaded_by is null or documents.uploaded_by <> ?)', $column ? [false, 'upload', $userId] : [false, $userId]],
                default => $column ? ['documents.source = ?', [$source]] : ['1 = 0', []],
            };
            $since = $seen[$source] ?? null;
            if ($since !== null) {
                $sql .= ' and documents.created_at > ?';
                $bindings[] = $since;
            }
            $query->selectRaw("sum(case when {$sql} then 1 else 0 end) as n_{$source}", $bindings);
        }
        $row = (array) $query->first();

        $counts = [];
        foreach (self::SOURCES as $source) {
            $counts[$source] = (int) ($row['n_'.$source] ?? 0);
        }

        return $counts;
    }

    /**
     * The folders this person filed into last (A360: on top of "Ablegen in …"), newest first. Read from the audit
     * trail's moves, so nothing new is stored.
     *
     * @param  list<string>|null  $existing  the org's folder ids when the caller has them already (saves a query)
     * @return list<string> folder ids
     */
    public static function recentFolders(string $orgId, string $userId, int $limit = 5, ?array $existing = null): array
    {
        $existing ??= Folder::query()->pluck('id')->all();
        $recent = [];
        $moves = AuditLogEntry::query()->where('org_id', $orgId)->where('user_id', $userId)->where('action', 'document.moved')
            ->latest('created_at')->latest('id')->limit(200)->pluck('meta_json');
        foreach ($moves as $meta) {
            $to = is_array($meta) ? ($meta['to'] ?? null) : null;
            if (is_string($to) && in_array($to, $existing, true) && ! in_array($to, $recent, true)) {
                $recent[] = $to;
                if (count($recent) >= $limit) {
                    break;
                }
            }
        }

        return $recent;
    }

    /** The column documents.source comes with the Android share target; until then the queries go without it. */
    public static function hasSourceColumn(): bool
    {
        return self::$sourceColumn ??= Schema::hasColumn('documents', 'source');
    }

    /** For tests that add the column at run time. */
    public static function forgetSchema(): void
    {
        self::$sourceColumn = null;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Posteingang (point 153): everything that came in and nobody handled yet, from every source.
 *
 * A document waits here while it is still in "Dokumente allgemein", was never opened and not marked "Erledigt".
 * It leaves when someone opens it, moves it into another folder, or marks it (row ✓, "Alle erledigt").
 * The source is the column documents.source (upload|printer|mail|phone|api, null = upload); before that column
 * exists, printed documents are known by in_print_tray and everything else counts as upload.
 */
final class Inbox
{
    /** Every source, in the order of the rows' icons; "api" = sent by another program. */
    public const array SOURCES = ['upload', 'printer', 'mail', 'phone', 'api'];

    /** The tabs besides "Alle" (A327: Handy = shared from a phone). */
    public const array TABS = ['upload', 'printer', 'mail', 'phone'];

    /** @var array<string, string> Tabler icon per source */
    public const array ICONS = ['upload' => 'upload', 'printer' => 'printer', 'mail' => 'mail', 'phone' => 'device-mobile', 'api' => 'plug'];

    private static ?bool $sourceColumn = null;

    /**
     * What waits in the inbox for this person (PLAN §2.1 visibility), optionally of one source.
     *
     * @return Builder<Document>
     */
    public static function query(string $userId, bool $seesAll, ?string $source = null): Builder
    {
        $query = Document::query()->visibleTo($userId, $seesAll)
            ->whereNull('documents.inbox_done_at')
            ->whereNull('documents.last_opened_at')
            ->where(static fn (Builder $inner): Builder => $inner->whereNull('documents.folder_id')
                ->orWhereIn('documents.folder_id', Folder::query()->where('is_default', true)->select('id')));

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
        $counts = [];
        foreach (self::SOURCES as $source) {
            if ($source !== 'printer' && $source !== 'upload' && ! self::hasSourceColumn()) {
                $counts[$source] = 0;

                continue;
            }
            $since = $seen[$source] ?? null;
            $counts[$source] = self::query($userId, $seesAll, $source)
                ->when($since !== null, static fn (Builder $query): Builder => $query->where('documents.created_at', '>', $since))
                ->when($source === 'upload', static fn (Builder $query): Builder => $query->where(static fn (Builder $inner): Builder => $inner
                    ->whereNull('documents.uploaded_by')->orWhere('documents.uploaded_by', '!=', $userId)))
                ->count();
        }

        return $counts;
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

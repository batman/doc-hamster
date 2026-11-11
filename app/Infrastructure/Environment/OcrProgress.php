<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

use App\Domain\Documents\Document;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * How far text recognition is (points 35/43, A21-A24): counts per status,
 * the document being read right now (cache key ocr.current, set by the
 * server job or the browser fallback) and a cheap cached waiting count for
 * the main menu.
 */
final class OcrProgress
{
    public const string CURRENT_KEY = 'ocr.current';

    private const int CURRENT_TTL = 300;

    private const int WAITING_TTL = 15;

    /** Browser passes (point 56): 1 fast, 2 medium, 3 full resolution; the server reads once and sets 3. */
    public const int PASSES = 3;

    public static function start(Document $document): void
    {
        Cache::put(self::CURRENT_KEY, [
            'id' => $document->id,
            'org' => $document->org_id,
            'title' => $document->title,
            'started' => now()->getTimestamp(),
            'percent' => null,
        ], self::CURRENT_TTL);
    }

    /** Browser fallback only: the percent of the current document (tesseract.js logger). */
    public static function percent(Document $document, int $percent): void
    {
        $current = Cache::get(self::CURRENT_KEY);
        if (! is_array($current) || ($current['id'] ?? null) !== $document->id) {
            self::start($document);
            $current = Cache::get(self::CURRENT_KEY);
        }
        if (is_array($current)) {
            $current['percent'] = max(0, min(100, $percent));
            Cache::put(self::CURRENT_KEY, $current, self::CURRENT_TTL);
        }
    }

    public static function finish(string $documentId, ?string $orgId = null): void
    {
        $current = Cache::get(self::CURRENT_KEY);
        if (is_array($current) && ($current['id'] ?? null) === $documentId) {
            Cache::forget(self::CURRENT_KEY);
        }
        if ($orgId !== null) {
            Cache::forget(self::waitingKey($orgId));
        }
    }

    /**
     * @return array{id: string, title: string, started: int, percent: ?int}|null
     */
    public static function current(string $orgId): ?array
    {
        $current = Cache::get(self::CURRENT_KEY);
        if (! is_array($current) || ($current['org'] ?? null) !== $orgId) {
            return null;
        }

        return [
            'id' => (string) $current['id'],
            'title' => (string) ($current['title'] ?? ''),
            'started' => (int) ($current['started'] ?? 0),
            'percent' => isset($current['percent']) ? (int) $current['percent'] : null,
        ];
    }

    /**
     * One grouped query: erkannt / wartet / fehlgeschlagen / nicht möglich.
     *
     * @return array{done: int, waiting: int, failed: int, unavailable: int}
     */
    public static function counts(string $orgId): array
    {
        $counts = ['done' => 0, 'waiting' => 0, 'failed' => 0, 'unavailable' => 0];
        $rows = self::query($orgId)->selectRaw('ocr_status, count(*) as n')->groupBy('ocr_status')->toBase()->get();
        foreach ($rows as $row) {
            $status = is_string($row->ocr_status ?? null) ? $row->ocr_status : 'pending';
            $key = match ($status) {
                'done' => 'done',
                'failed' => 'failed',
                'unavailable' => 'unavailable',
                default => 'waiting',
            };
            $counts[$key] += (int) $row->n;
        }

        return $counts;
    }

    /** Waiting documents (not read at all yet). */
    public static function waiting(string $orgId): int
    {
        return self::open($orgId)['waiting'];
    }

    /**
     * The OCR indicator in the main menu (point 57), cached 15 s: documents still open (waiting, or read by the
     * browser in pass 1 or 2 of 3, point 56), and the passes done of all readable documents for the small bar.
     *
     * @return array{open: int, waiting: int, total: int, passes: int}
     */
    public static function open(string $orgId): array
    {
        return Cache::remember(self::waitingKey($orgId), self::WAITING_TTL, static function () use ($orgId): array {
            $row = self::query($orgId)
                ->where(static fn ($w) => $w->whereNull('ocr_status')->orWhereIn('ocr_status', ['pending', 'done']))
                ->selectRaw("sum(case when ocr_status is null or ocr_status = 'pending' then 1 else 0 end) as waiting")
                ->selectRaw('sum(case when ocr_pass in (1, 2) and ocr_status = ? then 1 else 0 end) as reading', ['done'])
                ->selectRaw('count(*) as total')
                ->selectRaw("sum(case when ocr_status is null or ocr_status = 'pending' then 0 when ocr_pass in (1, 2) then ocr_pass else ".self::PASSES.' end) as passes')
                ->toBase()->first();
            $waiting = (int) ($row->waiting ?? 0);

            return [
                'open' => $waiting + (int) ($row->reading ?? 0),
                'waiting' => $waiting,
                'total' => (int) ($row->total ?? 0),
                'passes' => (int) ($row->passes ?? 0),
            ];
        });
    }

    public static function forgetWaiting(string $orgId): void
    {
        Cache::forget(self::waitingKey($orgId));
    }

    /** @return Builder<Document> */
    private static function query(string $orgId): Builder
    {
        return Document::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId);
    }

    private static function waitingKey(string $orgId): string
    {
        // Holds the open counts of point 57 (was a single waiting count under ocr.waiting.*).
        return 'ocr.open.'.$orgId;
    }
}

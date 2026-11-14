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

    /**
     * Point 130/A275: every document is read once at full resolution; ocr_pass keeps this value for a read document
     * (the old browser passes 1 and 2 are gone).
     */
    public const int FULL = 3;

    /** Point 124/A259: who reads (server or browser); page and pages follow as the reading goes on. */
    public static function start(Document $document, string $engine = 'server'): void
    {
        Cache::memo()->put(self::CURRENT_KEY, [
            'id' => $document->id,
            'org' => $document->org_id,
            'title' => $document->title,
            'started' => now()->getTimestamp(),
            'percent' => null,
            'engine' => $engine === 'browser' ? 'browser' : 'server',
            'page' => null,
            'pages' => null,
        ], self::CURRENT_TTL);
    }

    /** Browser fallback only: the percent of the current page (tesseract.js logger), with page and pages when known. */
    public static function percent(Document $document, int $percent, ?int $page = null, ?int $pages = null): void
    {
        $current = Cache::memo()->get(self::CURRENT_KEY);
        if (! is_array($current) || ($current['id'] ?? null) !== $document->id) {
            self::start($document, 'browser');
            $current = Cache::memo()->get(self::CURRENT_KEY);
        }
        if (is_array($current)) {
            $current['percent'] = max(0, min(100, $percent));
            if ($page !== null) {
                $current['page'] = max(1, $page);
                $current['pages'] = $pages !== null ? max(1, $pages) : ($current['pages'] ?? null);
            }
            Cache::memo()->put(self::CURRENT_KEY, $current, self::CURRENT_TTL);
        }
    }

    /** Point 124: the server job reached page `$page` (of `$pages`, when known) of the current document. */
    public static function page(string $documentId, int $page, ?int $pages = null): void
    {
        $current = Cache::memo()->get(self::CURRENT_KEY);
        if (! is_array($current) || ($current['id'] ?? null) !== $documentId) {
            return;
        }
        $current['page'] = max(1, $page);
        $current['pages'] = $pages !== null ? max(1, $pages) : ($current['pages'] ?? null);
        $current['percent'] = null;
        Cache::memo()->put(self::CURRENT_KEY, $current, self::CURRENT_TTL);
    }

    public static function finish(string $documentId, ?string $orgId = null): void
    {
        $current = Cache::memo()->get(self::CURRENT_KEY);
        if (is_array($current) && ($current['id'] ?? null) === $documentId) {
            Cache::memo()->forget(self::CURRENT_KEY);
        }
        if ($orgId !== null) {
            Cache::memo()->forget(self::waitingKey($orgId));
        }
    }

    /**
     * @return array{id: string, title: string, started: int, percent: ?int, engine: string, page: ?int, pages: ?int, elapsed: int}|null
     */
    public static function current(string $orgId): ?array
    {
        $current = Cache::memo()->get(self::CURRENT_KEY);
        if (! is_array($current) || ($current['org'] ?? null) !== $orgId) {
            return null;
        }
        $started = (int) ($current['started'] ?? 0);

        return [
            'id' => (string) $current['id'],
            'title' => (string) ($current['title'] ?? ''),
            'started' => $started,
            'percent' => isset($current['percent']) ? (int) $current['percent'] : null,
            'engine' => ($current['engine'] ?? 'server') === 'browser' ? 'browser' : 'server',
            'page' => isset($current['page']) ? (int) $current['page'] : null,
            'pages' => isset($current['pages']) ? (int) $current['pages'] : null,
            'elapsed' => $started > 0 ? max(0, now()->getTimestamp() - $started) : 0,
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
     * The OCR indicator in the main menu (point 57), cached 15 s: documents still open (waiting) and the documents
     * read of all readable ones for the small bar (point 130: one read per document).
     *
     * @return array{open: int, waiting: int, total: int, done: int}
     */
    public static function open(string $orgId): array
    {
        return Cache::memo()->remember(self::waitingKey($orgId), self::WAITING_TTL, static function () use ($orgId): array {
            $row = self::query($orgId)
                ->where(static fn ($w) => $w->whereNull('ocr_status')->orWhereIn('ocr_status', ['pending', 'done']))
                ->selectRaw("sum(case when ocr_status is null or ocr_status = 'pending' then 1 else 0 end) as waiting")
                ->selectRaw('count(*) as total')
                ->toBase()->first();
            $waiting = (int) ($row->waiting ?? 0);
            $total = (int) ($row->total ?? 0);

            return ['open' => $waiting, 'waiting' => $waiting, 'total' => $total, 'done' => $total - $waiting];
        });
    }

    public static function forgetWaiting(string $orgId): void
    {
        Cache::memo()->forget(self::waitingKey($orgId));
    }

    /** @return Builder<Document> */
    private static function query(string $orgId): Builder
    {
        return Document::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId);
    }

    public static function waitingKey(string $orgId): string
    {
        // Holds the open counts of point 57; v2 since point 130 (done instead of passes).
        return 'ocr.open.v2.'.$orgId;
    }
}

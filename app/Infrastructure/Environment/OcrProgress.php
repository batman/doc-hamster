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

    /** Waiting documents for the main menu, cached 15 s. */
    public static function waiting(string $orgId): int
    {
        return Cache::remember(self::waitingKey($orgId), self::WAITING_TTL, static fn (): int => self::query($orgId)
            ->where(static fn ($w) => $w->whereNull('ocr_status')->orWhere('ocr_status', 'pending'))->count());
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
        return 'ocr.waiting.'.$orgId;
    }
}

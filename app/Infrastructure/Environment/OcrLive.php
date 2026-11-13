<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

use App\Domain\Documents\Document;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\OrgScope;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\PropertyValues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The "Live" card on the Texterkennung page (point 124, A257/A258): figures from the OCR log (documents per hour,
 * seconds per page, the estimated time left for the queue) and the last finished documents with their result and
 * the amount or sender found. Everything comes from the log, cheap enough for the 3 s poll (figures cached 20 s).
 */
final class OcrLive
{
    /** Log events that end one document's read (unavailable is not a read). */
    private const array FINISHED = ['recognized', 'text_layer', 'failed', 'timeout'];

    private const array OK = ['recognized', 'text_layer'];

    /** Recent reads the averages are taken from. */
    private const int SAMPLE = 50;

    private const int FIGURES_TTL = 20;

    /**
     * @return array{per_hour: int, seconds_per_page: ?float, seconds_per_document: ?float, remaining_seconds: ?int}
     */
    public static function figures(string $orgId, int $open): array
    {
        $base = Cache::remember('ocr.live.figures.'.$orgId, self::FIGURES_TTL, static function () use ($orgId): array {
            $perHour = self::log($orgId)->whereIn('event', self::FINISHED)->where('created_at', '>=', now()->subHour())->count();
            $rows = self::log($orgId)->whereIn('event', self::OK)->whereNotNull('duration_ms')
                ->orderByDesc('created_at')->limit(self::SAMPLE)->get(['duration_ms', 'pages']);
            $ms = 0;
            $pages = 0;
            foreach ($rows as $row) {
                $ms += (int) $row->duration_ms;
                $pages += max(1, (int) ($row->pages ?? 1));
            }

            return [
                'per_hour' => $perHour,
                'seconds_per_page' => $pages > 0 ? round($ms / $pages / 1000, 1) : null,
                'seconds_per_document' => $rows->count() > 0 ? round($ms / $rows->count() / 1000, 1) : null,
            ];
        });
        $perDocument = $base['seconds_per_document'];

        return [
            ...$base,
            'remaining_seconds' => $open === 0 ? 0 : ($perDocument !== null ? (int) ceil($open * $perDocument) : null),
        ];
    }

    /**
     * The last finished documents (newest first, one entry per document) the person may see.
     *
     * @param  Builder<Document>  $visible
     * @return list<array{id: string, title: string, ok: bool, event: string, at: string, file: ?string, thumb: ?bool, mime: ?string, value: ?string}>
     */
    public static function recent(string $orgId, Builder $visible, int $limit = 10): array
    {
        $rows = self::log($orgId)->whereNotNull('document_id')->whereIn('event', self::FINISHED)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit * 5)->get(['document_id', 'event', 'created_at']);
        $last = [];
        foreach ($rows as $row) {
            $last[(string) $row->document_id] ??= $row;
        }
        if ($last === []) {
            return [];
        }
        $documents = $visible->whereIn('id', array_keys($last))
            ->with(['files' => static fn ($q) => $q->orderBy('page_no')])->get()->keyBy('id');
        $values = self::values($orgId, array_values(array_map(strval(...), $documents->keys()->all())));

        $out = [];
        foreach ($last as $id => $row) {
            $document = $documents->get($id);
            if (! $document instanceof Document) {
                continue;
            }
            $file = $document->files->first();
            $out[] = [
                'id' => $document->id,
                'title' => (string) $document->title,
                'ok' => in_array($row->event, self::OK, true),
                'event' => (string) $row->event,
                'at' => $row->created_at->toIso8601String(),
                'file' => $file?->id,
                'thumb' => $file !== null ? $file->thumb_path !== null : null,
                'mime' => $file?->mime,
                'value' => $values[$document->id] ?? null,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * A258: the amount found, else the sender, per document.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private static function values(string $orgId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DocumentProperty::query()->withoutGlobalScope(OrgScope::class)
            ->with(['property' => static fn ($q) => $q->withoutGlobalScope(OrgScope::class)])
            ->where('org_id', $orgId)->whereIn('document_id', $ids)
            ->whereHas('property', static fn ($q) => $q->withoutGlobalScope(OrgScope::class)->whereIn('key', ['invoice_amount', 'sender']))
            ->get();
        $reader = app(PropertyValues::class);
        $found = [];
        foreach ($rows as $value) {
            $key = $value->property->key;
            if ($key === 'invoice_amount' || ! isset($found[$value->document_id])) {
                $text = $reader->display($value);
                if ($text !== '') {
                    $found[$value->document_id] = $key === 'invoice_amount' ? $text : ($found[$value->document_id] ?? $text);
                }
            }
        }

        return $found;
    }

    /** @return Builder<OcrLog> */
    private static function log(string $orgId): Builder
    {
        return OcrLog::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId);
    }
}

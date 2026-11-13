<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Log\LogEntry;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\CurrentOrg;
use App\Http\Badges;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Einstellungen → Log (point 115, A224-A234): every logbook in one list – "Allgemein" and "Updates" (log_entries) and
 * "Texterkennung" (ocr_log) – with filters for logbooks, level, period, text and document; export as CSV and reset of
 * whole logbooks. The filter lives in the address (A230), so "all errors of all logs" can be kept as a link.
 */
final class LogController
{
    /** OCR events by level (A229): errors red, "nicht möglich" a warning, everything else an event. */
    private const array OCR_WARNINGS = ['unavailable'];

    public function index(Request $request, CurrentOrg $org, ?string $book = null): View
    {
        $filters = $this->filters($request, $book);
        $page = $this->query($org->id(), $filters)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(LogEntry::PER_PAGE)->withQueryString();
        $page->withPath(route('log.index'));
        $rows = $this->hydrate(collect($page->items()));
        Badges::markSeen($org, Badges::LOG);

        return view('settings.log', [
            'page' => $page,
            'rows' => $rows,
            'filters' => $filters,
            'books' => LogEntry::BOOKS,
            'document' => $filters['doc'] !== '' ? Document::query()->withTrashed()->whereKey($filters['doc'])->value('title') : null,
            'summary' => $filters['books'] === ['ocr'] ? OcrLog::unavailableSummary($org->id()) : [],
        ]);
    }

    /** A224, A232: exactly what the filter shows, as CSV (semicolons and UTF-8 with BOM, so Excel opens it as it is). */
    public function export(Request $request, CurrentOrg $org): StreamedResponse
    {
        $filters = $this->filters($request, null);
        $query = $this->query($org->id(), $filters)->orderByDesc('created_at')->orderByDesc('id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\u{FEFF}");
            fputcsv($out, [__('log.columns.time'), __('log.columns.book'), __('log.columns.level'), __('log.columns.message'), __('log.columns.person'), __('log.columns.document')], ';');
            $query->chunk(500, function (Collection $chunk) use ($out): void {
                foreach ($this->hydrate($chunk) as $row) {
                    fputcsv($out, [
                        $row['time']->timezone((string) config('app.timezone'))->format('d.m.Y H:i:s'),
                        __('log.books.'.$row['book']),
                        __('log.levels.'.$row['level']),
                        $row['text'],
                        $row['person'] ?? '',
                        $row['document'] ?? '',
                    ], ';');
                }
            });
            fclose($out);
        }, 'log-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A225, A233: empties the ticked logbooks after a confirm; "Allgemein" then says who emptied which and when. */
    public function reset(Request $request, CurrentOrg $org): RedirectResponse
    {
        $books = array_values(array_intersect(LogEntry::BOOKS, (array) $request->input('books', [])));
        if ($books === []) {
            return back();
        }
        if (in_array('ocr', $books, true)) {
            OcrLog::query()->where('org_id', $org->id())->delete();
        }
        DB::table('log_entries')->whereIn('book', $books)
            ->where(static fn (Builder $q) => $q->where('org_id', $org->id())->orWhereNull('org_id'))->delete();
        $names = implode(', ', array_map(static fn (string $b): string => (string) __('log.books.'.$b), $books));
        LogEntry::write('general', 'event', (string) __('log.actions.log.cleared', ['books' => $names]), 'log.cleared', $org->id());

        return redirect()->route('log.index')->with('status', __('log.cleared', ['books' => $names]));
    }

    /** Point 115: errors of the browser (window.onerror, failed promises, module errors) – short, at most a few per page. */
    public function client(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'where' => ['nullable', 'string', 'max:300'],
        ]);
        $where = is_string($data['where'] ?? null) && $data['where'] !== '' ? ' ('.$data['where'].')' : '';
        LogEntry::write('general', 'error', (string) __('log.browser', ['message' => $data['message'].$where]), 'browser.error');

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{books: list<string>, level: string, from: string, to: string, q: string, doc: string}
     */
    private function filters(Request $request, ?string $book): array
    {
        $wanted = $request->query('books');
        $books = $book !== null ? [$book] : array_values(array_intersect(LogEntry::BOOKS, is_array($wanted) ? $wanted : []));
        $date = static fn (mixed $v): string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';

        return [
            'books' => $books === [] ? LogEntry::BOOKS : $books,
            'level' => in_array($request->query('level'), ['errors', 'problems'], true) ? (string) $request->query('level') : '',
            'from' => $date($request->query('from')),
            'to' => $date($request->query('to')),
            'q' => is_string($request->query('q')) ? mb_substr(trim((string) $request->query('q')), 0, 100) : '',
            'doc' => is_string($request->query('doc')) ? mb_substr((string) $request->query('doc'), 0, 26) : '',
        ];
    }

    /**
     * One query over both stores with the same columns: id, created_at, book, level, kind (log|ocr).
     *
     * @param  array{books: list<string>, level: string, from: string, to: string, q: string, doc: string}  $filters
     */
    private function query(string $orgId, array $filters): Builder
    {
        $levels = match ($filters['level']) {
            'errors' => ['error'],
            'problems' => ['error', 'warning'],
            default => LogEntry::LEVELS,
        };
        $like = '%'.addcslashes($filters['q'], '\\%_').'%';
        $parts = [];
        $own = array_values(array_diff($filters['books'], ['ocr']));
        if ($own !== []) {
            $parts[] = DB::table('log_entries')
                ->select(['id', 'created_at', 'book', 'level', DB::raw("'log' as kind")])
                ->where(static fn (Builder $q) => $q->where('org_id', $orgId)->orWhereNull('org_id'))
                ->whereIn('book', $own)->whereIn('level', $levels)
                ->when($filters['q'] !== '', static fn (Builder $q) => $q->where('message', 'like', $like))
                ->when($filters['doc'] !== '', static fn (Builder $q) => $q->where('document_id', $filters['doc']))
                ->when($filters['from'] !== '', static fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
                ->when($filters['to'] !== '', static fn (Builder $q) => $q->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay()));
        }
        if (in_array('ocr', $filters['books'], true)) {
            $errors = "'".implode("','", OcrLog::ERRORS)."'";
            $warnings = "'".implode("','", self::OCR_WARNINGS)."'";
            $level = "CASE WHEN event IN ($errors) THEN 'error' WHEN event IN ($warnings) THEN 'warning' ELSE 'event' END";
            $parts[] = DB::table('ocr_log')
                ->select(['id', 'created_at', DB::raw("'ocr' as book"), DB::raw("$level as level"), DB::raw("'ocr' as kind")])
                ->where('org_id', $orgId)
                ->when($levels !== LogEntry::LEVELS, static fn (Builder $q) => $q->whereIn('event', [
                    ...OcrLog::ERRORS, ...(in_array('warning', $levels, true) ? self::OCR_WARNINGS : []),
                ]))
                ->when($filters['q'] !== '', static fn (Builder $q) => $q->where(static fn (Builder $w) => $w->where('message', 'like', $like)
                    ->orWhereIn('document_id', DB::table('documents')->select('id')->where('title', 'like', $like))))
                ->when($filters['doc'] !== '', static fn (Builder $q) => $q->where('document_id', $filters['doc']))
                ->when($filters['from'] !== '', static fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
                ->when($filters['to'] !== '', static fn (Builder $q) => $q->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay()));
        }
        if ($parts === []) {
            return DB::table('log_entries')->select(['id', 'created_at', 'book', 'level', DB::raw("'log' as kind")])->whereRaw('1 = 0');
        }
        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return DB::query()->fromSub($union, 'l');
    }

    /**
     * The page's rows with their text, person and document, one shape for both stores (view and export).
     *
     * @param  Collection<int, stdClass>  $items
     * @return list<array{id: string, kind: string, book: string, level: string, time: Carbon, text: string, details: list<string>, person: ?string, document: ?string, document_id: ?string, ocr: ?OcrLog}>
     */
    private function hydrate(Collection $items): array
    {
        $ids = static fn (string $kind): array => $items->filter(static fn (stdClass $i): bool => $i->kind === $kind)->pluck('id')->all();
        $own = LogEntry::query()->with(['user:id,display_name', 'document' => static fn ($d) => $d->withTrashed()->select(['id', 'title'])])
            ->whereKey($ids('log'))->get()->keyBy('id');
        $ocr = OcrLog::query()->withoutGlobalScopes()->with(['user:id,display_name', 'document' => static fn ($d) => $d->withTrashed()->select(['id', 'title'])])
            ->whereKey($ids('ocr'))->get()->keyBy('id');
        $rows = [];
        foreach ($items as $item) {
            if ($item->kind === 'log' && ($entry = $own->get($item->id)) instanceof LogEntry) {
                $rows[] = ['id' => $entry->id, 'kind' => 'log', 'book' => $entry->book, 'level' => $entry->level, 'time' => $entry->created_at,
                    'text' => $entry->message, 'details' => [], 'person' => $entry->user?->display_name,
                    'document' => $entry->document?->title, 'document_id' => $entry->document_id, 'ocr' => null];
            } elseif ($item->kind === 'ocr' && ($entry = $ocr->get($item->id)) instanceof OcrLog) {
                $key = 'ocr.log.event.'.$entry->event;
                $text = Lang::has($key) ? rtrim((string) __($key, ['chars' => (int) $entry->chars, 'message' => $entry->message ?? '']), ' ·:') : $entry->event;
                $rows[] = ['id' => $entry->id, 'kind' => 'ocr', 'book' => 'ocr', 'level' => (string) $item->level, 'time' => $entry->created_at,
                    'text' => $text, 'details' => array_values(array_filter([
                        (string) __('ocr.log.source.'.$entry->source),
                        $entry->pass !== null ? (string) __('ocr.log.pass', ['pass' => $entry->pass]) : null,
                        $entry->duration_ms !== null ? (string) __('ocr.log.seconds', ['seconds' => number_format($entry->duration_ms / 1000, 1, ',', '.')]) : null,
                        $entry->file_type,
                        $entry->size !== null ? self::size($entry->size) : null,
                        $entry->pages !== null ? trans_choice('ocr.log.pages', $entry->pages, ['count' => $entry->pages]) : null,
                        $entry->agent,
                    ])), 'person' => $entry->user?->display_name,
                    'document' => $entry->document?->title, 'document_id' => $entry->document_id, 'ocr' => $entry];
            }
        }

        return $rows;
    }

    private static function size(int $bytes): string
    {
        return $bytes < 1024 * 1024
            ? number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.').' KB'
            : number_format($bytes / 1024 / 1024, 1, ',', '.').' MB';
    }
}

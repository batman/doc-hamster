<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrProgress;
use App\Jobs\Local\RecognizeText;
use App\Jobs\Net\SetUpOcr;
use App\Queue\CronClock;
use App\Setup\CronLine;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Text recognition set-up and the browser fallback (point 18, A16/A17).
 * The browser reads one image or PDF (point 34, page by page with pdf.js) at a
 * time and posts the text back; only for receipts the user may edit
 * (documents.edit) or uploaded themself.
 */
final class OcrController extends Controller
{
    private const array SKIP_MIMES = LocalOcr::BROWSER_SKIP_MIMES;

    /** Einstellungen → Texterkennung (point 35): tabs "Übersicht" (mode, counts, progress) and "Protokoll" (points 61/69). */
    public function show(Request $request, LocalOcr $ocr, CurrentOrg $org): View
    {
        self::watch($org->id(), $ocr);
        $tab = $request->query('tab') === 'log' ? 'log' : 'overview';
        // A110: filters by result, engine, document and a text search over document name and message.
        $filters = [
            'result' => in_array($request->query('result'), array_keys(OcrLog::RESULTS), true) ? (string) $request->query('result') : '',
            'engine' => in_array($request->query('engine'), OcrLog::SOURCES, true) ? (string) $request->query('engine') : '',
            'doc' => is_string($request->query('doc')) ? mb_substr((string) $request->query('doc'), 0, 26) : '',
            'q' => is_string($request->query('q')) ? mb_substr(trim((string) $request->query('q')), 0, 100) : '',
        ];
        $log = null;
        $summary = [];
        $filterDocument = null;
        if ($tab === 'log') {
            $like = '%'.addcslashes($filters['q'], '\\%_').'%';
            $log = OcrLog::query()
                ->with(['document' => static fn ($d) => $d->withTrashed()->select(['id', 'title']), 'user:id,display_name'])
                ->when($filters['result'] !== '', static fn ($q) => $q->whereIn('event', OcrLog::RESULTS[$filters['result']] ?? []))
                ->when($filters['engine'] !== '', static fn ($q) => $q->where('source', $filters['engine']))
                ->when($filters['doc'] !== '', static fn ($q) => $q->where('document_id', $filters['doc']))
                ->when($filters['q'] !== '', static fn ($q) => $q->where(static fn ($w) => $w->where('message', 'like', $like)
                    ->orWhereHas('document', static fn ($d) => $d->withTrashed()->where('title', 'like', $like))))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(OcrLog::PER_PAGE)->withQueryString();
            $summary = OcrLog::unavailableSummary($org->id());
            if ($filters['doc'] !== '') {
                $filterDocument = Document::query()->withTrashed()->whereKey($filters['doc'])->value('title');
            }
        }

        return view('settings.ocr', [
            'tab' => $tab,
            'log' => $log,
            'filters' => $filters,
            'filterDocument' => is_string($filterDocument) ? $filterDocument : null,
            'summary' => $summary,
            'mode' => $ocr->mode(),
            'pdfReady' => $ocr->serverReadsPdf(),
            'status' => $ocr->status(),
            'counts' => OcrProgress::counts($org->id()),
            'current' => OcrProgress::current($org->id()),
            'engineError' => $ocr->browserError(),
            'cronLast' => CronClock::lastRun(),
            'cronRunning' => CronClock::running(),
            'cronLine' => '* * * * * '.CronLine::command(PHP_BINARY, base_path('artisan')),
        ]);
    }

    private const string PDF = 'application/pdf';

    /** Browser time limit per pass in seconds (public/js/modules/ocr-browser.js PASSES), for the log message. */
    private const array BROWSER_LIMITS = [1 => 120, 2 => 300, 3 => 600];

    /** Point 58/A63: browser reads that fail this often mark the document failed. */
    private const int MAX_FAILURES = 3;

    /**
     * A65/A68: "Erkennung auf dem Server einrichten" runs right in this request (no cron needed); the page shows a
     * progress dialog meanwhile. The download lives in the net job (D-020), run synchronously.
     */
    public function setup(Request $request, LocalOcr $ocr): RedirectResponse|JsonResponse
    {
        set_time_limit(300);
        ignore_user_abort(true);
        $orgId = app(CurrentOrg::class)->id();
        $userId = self::userId($request);
        OcrLog::record($orgId, 'server-setup', 'setup_started', ['user_id' => $userId]);
        $started = hrtime(true);
        $ocr->markRunning();
        SetUpOcr::dispatchSync();
        $status = $ocr->status();
        $ok = ($status['state'] ?? null) === 'ok';
        // A111: the outcome with its reason, including the PDF converter (A113).
        $pdf = match ($status['pdf'] ?? null) {
            'ok' => OcrLog::text('ocr.log.pdf_ok'),
            'failed' => OcrLog::text('ocr.log.pdf_failed', ['reason' => (string) ($status['pdf_reason'] ?? '?')]),
            default => null,
        };
        OcrLog::record($orgId, 'server-setup', $ok ? 'setup_ok' : 'setup_failed', [
            'user_id' => $userId,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'message' => $ok
                ? implode(' · ', array_filter([OcrLog::text('ocr.mode.'.$ocr->mode()), $pdf]))
                : (is_string($status['reason'] ?? null) ? $status['reason'] : null),
        ]);

        return $request->expectsJson() ? response()->json(['state' => $status['state'] ?? null, 'mode' => $ocr->mode()]) : back();
    }

    /** A61: the browser engine did not start; the reason is shown on the settings page and in the menu. */
    public function engineError(Request $request, LocalOcr $ocr): Response
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $ocr->reportBrowserError((string) $data['reason']);
        OcrLog::record(app(CurrentOrg::class)->id(), 'browser', 'engine_failed', ['message' => (string) $data['reason'], 'user_id' => self::userId($request), 'agent' => OcrLog::agent($request->userAgent())]);

        return response()->noContent();
    }

    /** Point 61: browser events without a document – engine started (with its start time), paused, resumed. */
    public function log(Request $request, CurrentOrg $org): Response
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'in:engine_started,paused,resumed'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:3600000'],
        ]);
        OcrLog::record($org->id(), 'browser', (string) $data['event'], [
            'duration_ms' => isset($data['duration']) ? (int) $data['duration'] : null,
            'user_id' => self::userId($request),
            'agent' => OcrLog::agent($request->userAgent()),
        ]);

        return response()->noContent();
    }

    /** "Alle neu erkennen": every document of the organisation waits again; the server or the browser reads them one by one. */
    public function rerun(Request $request, CurrentOrg $org): RedirectResponse
    {
        $count = Document::query()->toBase()->update(['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0]);
        // A111: who started it and for how many documents.
        OcrLog::record($org->id(), 'system', 'rerun', ['message' => (string) $count, 'user_id' => self::userId($request)]);
        Document::query()->select('id')->eachById(static fn (Document $d) => RecognizeText::dispatch($d->id));
        OcrProgress::forgetWaiting($org->id());

        return back()->with('status', __('ocr.rerun_started'));
    }

    /** "Neu erkennen" on one document (A23). */
    public function redo(Request $request, string $id): RedirectResponse
    {
        $document = $this->editable($request)->whereKey($id)->firstOrFail();
        $document->forceFill(['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0])->save();
        RecognizeText::dispatch($document->id);
        OcrProgress::forgetWaiting($document->org_id);

        return back();
    }

    /**
     * Small JSON for polling (A21/A22, point 57): the open count and bar for everyone, the details only for settings.manage.
     */
    public function status(LocalOcr $ocr, CurrentOrg $org): JsonResponse
    {
        self::watch($org->id(), $ocr);
        $open = OcrProgress::open($org->id());
        $nav = ['waiting' => $open['waiting'], 'open' => $open['open'], 'total' => $open['total'], 'passes' => $open['passes']];
        // A61: why the browser engine does not start (menu tooltip); absent when it works.
        $engineError = $ocr->browserLoop() ? $ocr->browserError() : null;
        if ($engineError !== null) {
            $nav['engine_error'] = $engineError;
        }
        if (! Gate::allows(Permission::ManageSettings->value)) {
            return response()->json($nav);
        }
        $counts = OcrProgress::counts($org->id());

        return response()->json([
            ...$nav,
            'waiting' => $counts['waiting'],
            'mode' => $ocr->mode(),
            'counts' => $counts,
            'current' => OcrProgress::current($org->id()),
        ]);
    }

    /** Browser fallback: percent of the document being read (A24), every 2 s at most. */
    public function progress(Request $request, string $id): Response
    {
        $data = $request->validate(['percent' => ['required', 'integer', 'min:0', 'max:100']]);
        $document = $this->editable($request)->whereKey($id)->firstOrFail();
        OcrProgress::percent($document, (int) $data['percent']);

        return response()->noContent();
    }

    public function next(Request $request, LocalOcr $ocr): JsonResponse|Response
    {
        if (! $ocr->browserLoop()) {
            return response()->noContent();
        }
        // Point 69: in mode app the server reads images; the browser only gets the PDFs the server cannot read.
        $onlyPdf = $ocr->mode() !== 'browser';
        $readable = $onlyPdf ? self::pdfs(...) : self::readable(...);
        $skip = array_slice(array_filter((array) $request->query('skip', []), 'is_string'), 0, 100);

        // Point 56: three passes, the lowest first: every unread document fast, then all again slower, then in full.
        // A77: nothing is reserved; a random document of the lowest open pass (and fewest recent failures) is handed
        // out, so several windows mostly read different documents. A78: the second engine asks with only=1 for pass 1.
        $level = "case when ocr_status = 'done' then ocr_pass else 0 end";
        $open = fn (): Builder => $this->editable($request)
            ->where(static fn ($w) => $w->whereNull('ocr_status')->orWhereIn('ocr_status', ['pending', 'unavailable'])
                ->orWhere(static fn ($d) => $d->where('ocr_status', 'done')->whereIn('ocr_pass', [1, 2])))
            ->whereNotIn('id', $skip)
            ->whereHas('files', $readable);
        // A document that failed lately goes behind the others of its pass, so one bad file does not block the rest.
        $lowest = $open()->selectRaw($level.' as lvl, ocr_failures')
            ->orderByRaw($level)->orderBy('ocr_failures')->toBase()->first();
        $document = null;
        if ($lowest !== null && ! ($request->boolean('only') && (int) $lowest->lvl > 0)) {
            $document = $open()->whereRaw($level.' = ?', [(int) $lowest->lvl])
                ->where('ocr_failures', (int) $lowest->ocr_failures)
                ->inRandomOrder()->first();
        }
        if ($document === null) {
            return response()->noContent();
        }
        $pass = $document->ocr_status === 'done' ? (int) $document->ocr_pass + 1 : 1;
        $file = ($onlyPdf ? self::pdfs(DocumentFile::query()->where('document_id', $document->id)) : self::readable(DocumentFile::query()->where('document_id', $document->id)))->orderBy('page_no')->firstOrFail();
        OcrProgress::start($document);

        return response()->json([
            'id' => $document->id,
            'file' => route('ocr.image', $file->id),
            'type' => $file->mime,
            'pass' => $pass,
            'save' => route('ocr.store', $document->id),
            'progress' => route('ocr.progress', $document->id),
        ]);
    }

    /** Serves the image or PDF without touching last_opened_at (unlike documents.file). */
    public function image(Request $request, string $id): StreamedResponse
    {
        $file = self::readable(DocumentFile::query()->whereKey($id))->firstOrFail();
        $this->editable($request)->whereKey($file->document_id)->firstOrFail();

        return Storage::disk('local')->response($file->path, null, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, LocalOcr $ocr, string $id): Response|JsonResponse
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:200000'],
            'failed' => ['nullable', 'boolean'],
            'pass' => ['nullable', 'integer', 'min:1', 'max:'.OcrProgress::PASSES],
            'duration' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            'reason' => ['nullable', 'string', 'max:300'],
            'pages' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'layer' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $document = $this->editable($request)->whereKey($id)->firstOrFail();
        $pass = (int) ($data['pass'] ?? OcrProgress::PASSES);
        // A84: another window or the server already reached this pass – only the first result of a pass counts.
        if ($document->ocr_status === 'done' && (int) $document->ocr_pass >= $pass) {
            OcrProgress::finish($document->id, $document->org_id);

            return response()->json(['ignored' => true]);
        }
        $read = $document->ocr_status === 'done' && $document->ocr_text !== null;
        $duration = isset($data['duration']) ? (int) $data['duration'] : null;
        // A106/A109: file details, who read it and in which browser.
        $file = DocumentFile::query()->where('document_id', $document->id)->orderBy('page_no')->first();
        $pages = isset($data['pages']) ? (int) $data['pages'] : null;
        $log = [
            ...OcrLog::file($file, $pages),
            'document_id' => $document->id, 'pass' => $pass, 'duration_ms' => $duration,
            'user_id' => self::userId($request), 'agent' => OcrLog::agent($request->userAgent()),
        ];
        if ($data['failed'] ?? false) {
            // Point 58/A63: time limit or error. It comes again; after three failures it stops – a later pass
            // keeps the text of the earlier one, a document without text is marked failed.
            $failures = (int) $document->ocr_failures + 1;
            $values = ['ocr_failures' => min(255, $failures)];
            if ($failures >= self::MAX_FAILURES) {
                $values += $read ? ['ocr_pass' => OcrProgress::PASSES] : ['ocr_status' => 'failed', 'ocr_pass' => null];
            }
            $document->forceFill($values)->save();
            $reason = is_string($data['reason'] ?? null) ? $data['reason'] : null;
            $message = $reason === 'timeout'
                ? OcrLog::text('ocr.reason.timeout', ['seconds' => self::BROWSER_LIMITS[$pass] ?? 0])
                : ($reason !== null ? mb_substr($reason, 0, 160) : null);
            OcrLog::record($document->org_id, 'browser', $reason === 'timeout' ? 'timeout' : 'failed', [...$log, 'message' => $message]);
        } else {
            // Each pass replaces the text of the one before (point 56).
            $text = trim((string) ($data['text'] ?? ''));
            $document->forceFill(['ocr_text' => $text, 'ocr_status' => 'done', 'ocr_pass' => $pass, 'ocr_failures' => 0])->save();
            $ocr->clearBrowserError();
            // A114: every page came from the PDF's own text layer.
            $layer = isset($data['layer']) ? (int) $data['layer'] : 0;
            $event = $pages !== null && $pages > 0 && $layer >= $pages ? 'text_layer' : 'recognized';
            OcrLog::record($document->org_id, 'browser', $event, [...$log, 'chars' => mb_strlen($text)]);
        }
        OcrProgress::finish($document->id, $document->org_id);

        return response()->noContent();
    }

    /**
     * Point 61/A111: cron going missing or coming back (with its last run) and a switch of the engine are logged
     * once per change (checked on the status poll and the page).
     */
    private static function watch(string $orgId, LocalOcr $ocr): void
    {
        $running = CronClock::running();
        $key = 'ocr.cron.'.$orgId;
        $before = Cache::get($key);
        if ($before !== $running) {
            Cache::forever($key, $running);
            if ($before !== null || ! $running) {
                $last = CronClock::lastRun();
                OcrLog::record($orgId, 'system', $running ? 'cron_back' : 'cron_missing', [
                    'message' => $last !== null
                        ? OcrLog::text('ocr.log.cron_last', ['time' => Carbon::createFromTimestamp($last, (string) config('app.timezone'))->format('d.m.Y H:i')])
                        : OcrLog::text('ocr.log.cron_never'),
                ]);
            }
        }

        // The engine as people see it: system, app (+ PDF converter), browser, none.
        $mode = $ocr->mode();
        if ($mode === 'app' && $ocr->pdfAvailable()) {
            $mode .= '+pdf';
        }
        $modeKey = 'ocr.mode.seen.'.$orgId;
        $seen = Cache::get($modeKey);
        if ($seen !== $mode) {
            Cache::forever($modeKey, $mode);
            if (is_string($seen)) {
                OcrLog::record($orgId, 'system', 'mode_changed', ['message' => self::modeName($seen).' → '.self::modeName($mode)]);
            }
        }
    }

    private static function modeName(string $mode): string
    {
        $pdf = str_ends_with($mode, '+pdf');
        $name = OcrLog::text('ocr.mode.'.($pdf ? substr($mode, 0, -4) : $mode));

        return $pdf ? OcrLog::text('ocr.log.with_pdf', ['mode' => $name]) : $name;
    }

    private static function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }

    /**
     * Point 69: in mode app the server reads the images; the browser gets only the PDFs.
     *
     * @template TQuery of Builder<DocumentFile>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private static function pdfs(Builder $query): Builder
    {
        return $query->where('mime', self::PDF);
    }

    /**
     * Files the browser can read: images it decodes, and PDFs.
     *
     * @template TQuery of Builder<DocumentFile>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private static function readable(Builder $query): Builder
    {
        return $query->where(static fn (Builder $w) => $w
            ->where(static fn (Builder $i) => $i->where('mime', 'like', 'image/%')->whereNotIn('mime', self::SKIP_MIMES))
            ->orWhere('mime', self::PDF));
    }

    /**
     * Org-scoped by the global scope; editors see what they may view, others only their own uploads.
     *
     * @return Builder<Document>
     */
    private function editable(Request $request): Builder
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $query = Document::query()->visibleTo($userId, Gate::allows(Permission::ViewAllDocuments->value));

        return Gate::allows(Permission::EditDocuments->value) ? $query : $query->where('uploaded_by', $userId);
    }
}

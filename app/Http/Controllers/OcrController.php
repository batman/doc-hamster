<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Ocr\OcrFixes;
use App\Domain\Ocr\OcrLog;
use App\Domain\Ocr\OcrWords;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Events\TextRecognized;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrLive;
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

    /** Einstellungen → Texterkennung (point 35): mode, counts, progress. Its log is now Einstellungen → Log (point 116). */
    public function show(Request $request, LocalOcr $ocr, CurrentOrg $org): View|RedirectResponse
    {
        if ($request->query('tab') === 'log') {
            return redirect()->route('log.ocr', array_filter(['doc' => $request->query('doc'), 'q' => $request->query('q')], static fn ($v) => is_string($v) && $v !== ''));
        }
        self::watch($org->id(), $ocr);

        // Point 123: from the OCR widget the page opens as a module of its own, without the settings columns.
        return view($request->routeIs('ocr.live') ? 'ocr.page' : 'settings.ocr', [
            'mode' => $ocr->mode(),
            'pdfReady' => $ocr->serverReadsPdf(),
            'status' => $ocr->status(),
            'counts' => OcrProgress::counts($org->id()),
            'current' => OcrProgress::current($org->id()),
            'engineError' => $ocr->browserError(),
            'cronLast' => CronClock::lastRun(),
            'cronRunning' => CronClock::running(),
            'cronLine' => '* * * * * '.CronLine::command(PHP_BINARY, base_path('artisan')),
            'live' => $this->live($request, $org->id()),
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
        $count = Document::query()->toBase()->update(['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0, 'ocr_rescan' => false]);
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
        $document->forceFill(['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0, 'ocr_rescan' => false])->save();
        RecognizeText::dispatch($document->id);
        OcrProgress::forgetWaiting($document->org_id);

        return back();
    }

    /**
     * Small JSON for polling (A21/A22, point 57): the open count and bar for everyone, the details only for settings.manage.
     */
    public function status(Request $request, LocalOcr $ocr, CurrentOrg $org): JsonResponse
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

        $data = [
            ...$nav,
            'waiting' => $counts['waiting'],
            'mode' => $ocr->mode(),
            'counts' => $counts,
            'current' => OcrProgress::current($org->id()),
        ];
        // Point 124: the Live card asks with live=1 for the details of the current document, figures and the last ten.
        if ($request->boolean('live')) {
            $data = [...$data, ...$this->live($request, $org->id(), $open['open'])];
        }

        return response()->json($data);
    }

    /**
     * Point 124 (A255-A259): what the Live card shows – the current document with its file, page and pass, the
     * figures from the OCR log and the last ten finished documents with result and amount or sender.
     *
     * @return array{current: array<string, mixed>|null, figures: array<string, int|float|null>, recent: list<array<string, mixed>>}
     */
    private function live(Request $request, string $orgId, ?int $open = null): array
    {
        $current = OcrProgress::current($orgId);
        if ($current !== null) {
            $file = DocumentFile::query()->where('document_id', $current['id'])->orderBy('page_no')->first();
            $current += [
                'url' => route('documents.show', $current['id']),
                'file' => $file !== null && self::readable(DocumentFile::query()->whereKey($file->id))->exists() ? route('ocr.image', $file->id) : null,
                'type' => $file?->mime,
                'thumb' => $file?->thumb_path !== null ? route('documents.thumb', $file->id) : null,
            ];
        }
        $recent = array_map(static fn (array $row): array => [
            ...$row,
            'url' => route('documents.show', $row['id']),
            'words' => route('documents.words', $row['id']),
            'thumb' => $row['thumb'] === true && $row['file'] !== null ? route('documents.thumb', $row['file']) : null,
            // Through ocr.image, which leaves last_opened_at alone (unlike documents.file).
            'file' => $row['file'] !== null && $row['mime'] !== null && ((str_starts_with($row['mime'], 'image/') && ! in_array($row['mime'], self::SKIP_MIMES, true)) || $row['mime'] === self::PDF) ? route('ocr.image', $row['file']) : null,
        ], OcrLive::recent($orgId, $this->visible($request)));

        return [
            'current' => $current,
            'figures' => OcrLive::figures($orgId, $open ?? OcrProgress::open($orgId)['open']),
            'recent' => $recent,
        ];
    }

    /** Browser fallback: percent of the page being read (A24), every 2 s at most; with page and pages (point 124). */
    public function progress(Request $request, string $id): Response
    {
        $data = $request->validate([
            'percent' => ['required', 'integer', 'min:0', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'pages' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);
        $document = $this->editable($request)->whereKey($id)->firstOrFail();
        OcrProgress::percent($document, (int) $data['percent'], isset($data['page']) ? (int) $data['page'] : null, isset($data['pages']) ? (int) $data['pages'] : null);

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
        // F17: documents marked for a re-read (word positions) come after every new one.
        $open = fn (): Builder => $this->editable($request)
            ->where(static fn ($w) => $w->whereNull('ocr_status')->orWhereIn('ocr_status', ['pending', 'unavailable'])
                ->orWhere(static fn ($d) => $d->where('ocr_status', 'done')->whereIn('ocr_pass', [1, 2]))
                ->orWhere('ocr_rescan', true))
            ->whereNotIn('id', $skip)
            ->whereHas('files', $readable);
        // A document that failed lately goes behind the others of its pass, so one bad file does not block the rest.
        $lowest = $open()->selectRaw($level.' as lvl, ocr_failures, ocr_rescan')
            ->orderBy('ocr_rescan')->orderByRaw($level)->orderBy('ocr_failures')->toBase()->first();
        $document = null;
        if ($lowest !== null && ! ($request->boolean('only') && ((int) $lowest->lvl > 0 || (bool) $lowest->ocr_rescan))) {
            $document = $open()->where('ocr_rescan', (bool) $lowest->ocr_rescan)
                ->whereRaw($level.' = ?', [(int) $lowest->lvl])
                ->where('ocr_failures', (int) $lowest->ocr_failures)
                ->inRandomOrder()->first();
        }
        if ($document === null) {
            return response()->noContent();
        }
        // A re-read of a read document goes straight to full resolution (its earlier text stays until then).
        $pass = match (true) {
            $document->ocr_status === 'done' && (bool) $document->getAttribute('ocr_rescan') => OcrProgress::PASSES,
            $document->ocr_status === 'done' => (int) $document->ocr_pass + 1,
            default => 1,
        };
        $file = ($onlyPdf ? self::pdfs(DocumentFile::query()->where('document_id', $document->id)) : self::readable(DocumentFile::query()->where('document_id', $document->id)))->orderBy('page_no')->firstOrFail();
        OcrProgress::start($document, 'browser', $pass);

        return response()->json([
            'id' => $document->id,
            // Point 124: the Live card names the document this tab reads.
            'title' => $document->title,
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
            // Point 119: word positions as JSON (OcrWords shape, 0..1), checked and cleaned by OcrWords::fromBrowser().
            'words' => ['nullable', 'string', 'max:2097152'],
        ]);
        $document = $this->editable($request)->whereKey($id)->firstOrFail();
        $pass = (int) ($data['pass'] ?? OcrProgress::PASSES);
        $rescan = (bool) $document->getAttribute('ocr_rescan');
        // A84: another window or the server already reached this pass – only the first result of a pass counts
        // (a document marked for a re-read takes its new full pass, F17).
        if ($document->ocr_status === 'done' && (int) $document->ocr_pass >= $pass && ! $rescan) {
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
                $values['ocr_rescan'] = false;
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
            $words = is_string($data['words'] ?? null) ? OcrWords::fromBrowser($data['words']) : null;
            $document->forceFill([
                'ocr_text' => OcrFixes::reapply($document, $text, $words), 'ocr_words' => OcrWords::encode($words), 'ocr_status' => 'done', 'ocr_pass' => $pass,
                'ocr_failures' => 0, 'ocr_rescan' => false,
            ])->save();
            $ocr->clearBrowserError();
            TextRecognized::dispatch($document->id);
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
     * What this person may view (org-scoped by the global scope).
     *
     * @return Builder<Document>
     */
    private function visible(Request $request): Builder
    {
        return Document::query()->visibleTo((string) $request->user()?->getAuthIdentifier(), Gate::allows(Permission::ViewAllDocuments->value));
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

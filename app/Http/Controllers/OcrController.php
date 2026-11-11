<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
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

    /** Einstellungen → Texterkennung (point 35): mode, counts, progress. */
    public function show(LocalOcr $ocr, CurrentOrg $org): View
    {
        return view('settings.ocr', [
            'mode' => $ocr->mode(),
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
        $ocr->markRunning();
        SetUpOcr::dispatchSync();
        $status = $ocr->status();

        return $request->expectsJson() ? response()->json(['state' => $status['state'] ?? null, 'mode' => $ocr->mode()]) : back();
    }

    /** A61: the browser engine did not start; the reason is shown on the settings page and in the menu. */
    public function engineError(Request $request, LocalOcr $ocr): Response
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $ocr->reportBrowserError((string) $data['reason']);

        return response()->noContent();
    }

    /** "Alle neu erkennen": every document of the organisation waits again; the server or the browser reads them one by one. */
    public function rerun(CurrentOrg $org): RedirectResponse
    {
        Document::query()->toBase()->update(['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0]);
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
        $open = OcrProgress::open($org->id());
        $nav = ['waiting' => $open['waiting'], 'open' => $open['open'], 'total' => $open['total'], 'passes' => $open['passes']];
        // A61: why the browser engine does not start (menu tooltip); absent when it works.
        $engineError = $ocr->mode() === 'browser' ? $ocr->browserError() : null;
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
        if ($ocr->mode() !== 'browser') {
            return response()->noContent();
        }
        $skip = array_slice(array_filter((array) $request->query('skip', []), 'is_string'), 0, 100);

        // Point 56: three passes, the lowest first: every unread document fast, then all again slower, then in full.
        $document = $this->editable($request)
            ->where(static fn ($w) => $w->whereNull('ocr_status')->orWhereIn('ocr_status', ['pending', 'unavailable'])
                ->orWhere(static fn ($d) => $d->where('ocr_status', 'done')->whereIn('ocr_pass', [1, 2])))
            ->whereNotIn('id', $skip)
            ->whereHas('files', self::readable(...))
            ->orderByRaw("case when ocr_status = 'done' then ocr_pass else 0 end")
            // A document that failed lately goes behind the others of its pass, so one bad file does not block the rest.
            ->orderBy('ocr_failures')
            ->latest('created_at')->first();
        if ($document === null) {
            return response()->noContent();
        }
        $pass = $document->ocr_status === 'done' ? (int) $document->ocr_pass + 1 : 1;
        $file = self::readable(DocumentFile::query()->where('document_id', $document->id))->orderBy('page_no')->firstOrFail();
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

    public function store(Request $request, LocalOcr $ocr, string $id): Response
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:200000'],
            'failed' => ['nullable', 'boolean'],
            'pass' => ['nullable', 'integer', 'min:1', 'max:'.OcrProgress::PASSES],
        ]);
        $document = $this->editable($request)->whereKey($id)->firstOrFail();
        $pass = (int) ($data['pass'] ?? OcrProgress::PASSES);
        $read = $document->ocr_status === 'done' && $document->ocr_text !== null;
        if ($data['failed'] ?? false) {
            // Point 58/A63: time limit or error. It comes again; after three failures it stops – a later pass
            // keeps the text of the earlier one, a document without text is marked failed.
            $failures = (int) $document->ocr_failures + 1;
            $values = ['ocr_failures' => min(255, $failures)];
            if ($failures >= self::MAX_FAILURES) {
                $values += $read ? ['ocr_pass' => OcrProgress::PASSES] : ['ocr_status' => 'failed', 'ocr_pass' => null];
            }
            $document->forceFill($values)->save();
        } else {
            // Each pass replaces the text of the one before (point 56).
            $document->forceFill(['ocr_text' => trim((string) ($data['text'] ?? '')), 'ocr_status' => 'done', 'ocr_pass' => $pass, 'ocr_failures' => 0])->save();
            $ocr->clearBrowserError();
        }
        OcrProgress::finish($document->id, $document->org_id);

        return response()->noContent();
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

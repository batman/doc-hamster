<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\CurrentOrg;
use App\Http\OcrAccess;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrProgress;
use App\Jobs\Local\RecognizeText;
use App\Jobs\Net\SetUpOcr;
use App\Queue\CronClock;
use App\Setup\CronLine;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Einstellungen → Texterkennung (point 18/35): the page, setting up the server engine and reading again. The status
 * poll lives in OcrStatusController, the browser fallback in OcrBrowserController.
 */
final class OcrController extends Controller
{
    /** Einstellungen → Texterkennung (point 35): mode, counts, progress. Its log is now Einstellungen → Log (point 116). */
    public function show(Request $request, LocalOcr $ocr, CurrentOrg $org): View|RedirectResponse
    {
        if ($request->query('tab') === 'log') {
            return redirect()->route('log.ocr', array_filter(['doc' => $request->query('doc'), 'q' => $request->query('q')], static fn ($v) => is_string($v) && $v !== ''));
        }
        OcrStatusController::watch($org->id(), $ocr);

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
            'live' => OcrStatusController::live($request, $org->id()),
        ]);
    }

    /**
     * A65/A68: "Erkennung auf dem Server einrichten" runs right in this request (no cron needed); the page shows a
     * progress dialog meanwhile. The download lives in the net job (D-020), run synchronously.
     */
    public function setup(Request $request, LocalOcr $ocr): RedirectResponse|JsonResponse
    {
        set_time_limit(300);
        ignore_user_abort(true);
        $orgId = app(CurrentOrg::class)->id();
        $userId = OcrAccess::userId($request);
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

    /** "Alle neu erkennen": every document of the organisation waits again; the server or the browser reads them one by one. */
    public function rerun(Request $request, CurrentOrg $org): RedirectResponse
    {
        $count = Document::query()->toBase()->update(['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0, 'ocr_rescan' => false]);
        // A111: who started it and for how many documents.
        OcrLog::record($org->id(), 'system', 'rerun', ['message' => (string) $count, 'user_id' => OcrAccess::userId($request)]);
        Document::query()->select('id')->eachById(static fn (Document $d) => RecognizeText::dispatch($d->id));
        OcrProgress::forgetWaiting($org->id());

        return back()->with('status', __('ocr.rerun_started'));
    }

    /** "Neu erkennen" on one document (A23). */
    public function redo(Request $request, string $id): RedirectResponse
    {
        $document = OcrAccess::editable($request)->whereKey($id)->firstOrFail();
        $document->forceFill(['ocr_status' => 'pending', 'ocr_pass' => null, 'ocr_failures' => 0, 'ocr_rescan' => false])->save();
        RecognizeText::dispatch($document->id);
        OcrProgress::forgetWaiting($document->org_id);

        return back();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentFile;
use App\Domain\Ocr\OcrFixes;
use App\Domain\Ocr\OcrLog;
use App\Domain\Ocr\OcrWords;
use App\Events\TextRecognized;
use App\Http\OcrAccess;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The browser fallback (point 18, A16/A17): the browser reads one image or PDF (point 34, page by page with pdf.js)
 * at a time and posts the text back; only for receipts the user may edit (documents.edit) or uploaded themself.
 * Point 130/A275: one read at full resolution, 10 minutes per document (A276: the browser retries once at 2000 px
 * when that runs over); three failures mark the document failed.
 */
final class OcrBrowserController extends Controller
{
    /** Browser time limit per read in seconds (public/js/modules/ocr-browser.js LIMIT_MS), for the log message. */
    private const int BROWSER_LIMIT = 600;

    /** Point 58/A63: browser reads that fail this often mark the document failed. */
    private const int MAX_FAILURES = 3;

    public function next(Request $request, LocalOcr $ocr): JsonResponse|Response
    {
        if (! $ocr->browserLoop()) {
            return response()->noContent();
        }
        // Point 69: in mode app the server reads images; the browser only gets the PDFs the server cannot read.
        $onlyPdf = $ocr->mode() !== 'browser';
        $readable = $onlyPdf ? OcrAccess::pdfs(...) : OcrAccess::readable(...);
        $skip = array_slice(array_filter((array) $request->query('skip', []), 'is_string'), 0, 100);

        // A77: nothing is reserved; a random open document (with the fewest recent failures) is handed out, so several
        // windows mostly read different documents. F17: documents marked for a re-read come after every new one.
        $open = fn (): Builder => OcrAccess::editable($request)
            ->where(static fn ($w) => $w->whereNull('ocr_status')->orWhereIn('ocr_status', ['pending', 'unavailable'])->orWhere('ocr_rescan', true))
            ->whereNotIn('id', $skip)
            ->whereHas('files', $readable);
        // Documents without text before re-reads of read ones; a document that failed lately goes behind the others,
        // so one bad file does not block the rest.
        $read = "case when ocr_status = 'done' then 1 else 0 end";
        $first = $open()->selectRaw($read.' as is_read, ocr_failures, ocr_rescan')
            ->orderBy('ocr_rescan')->orderByRaw($read)->orderBy('ocr_failures')->toBase()->first();
        $document = $first === null ? null : $open()->where('ocr_rescan', (bool) $first->ocr_rescan)
            ->whereRaw($read.' = ?', [(int) $first->is_read])
            ->where('ocr_failures', (int) $first->ocr_failures)->inRandomOrder()->first();
        if ($document === null) {
            return response()->noContent();
        }
        $file = ($onlyPdf ? OcrAccess::pdfs(DocumentFile::query()->where('document_id', $document->id)) : OcrAccess::readable(DocumentFile::query()->where('document_id', $document->id)))->orderBy('page_no')->firstOrFail();
        OcrProgress::start($document, 'browser');

        return response()->json([
            'id' => $document->id,
            // Point 124: the Live card names the document this tab reads.
            'title' => $document->title,
            'file' => route('ocr.image', $file->id),
            'type' => $file->mime,
            'save' => route('ocr.store', $document->id),
            'progress' => route('ocr.progress', $document->id),
        ]);
    }

    /** Serves the image or PDF without touching last_opened_at (unlike documents.file). */
    public function image(Request $request, string $id): StreamedResponse
    {
        $file = OcrAccess::readable(DocumentFile::query()->whereKey($id))->firstOrFail();
        OcrAccess::editable($request)->whereKey($file->document_id)->firstOrFail();

        return Storage::disk('local')->response($file->path, null, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store']);
    }

    /** Browser fallback: percent of the page being read (A24), every 2 s at most; with page and pages (point 124). */
    public function progress(Request $request, string $id): Response
    {
        $data = $request->validate([
            'percent' => ['required', 'integer', 'min:0', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'pages' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);
        $document = OcrAccess::editable($request)->whereKey($id)->firstOrFail();
        OcrProgress::percent($document, (int) $data['percent'], isset($data['page']) ? (int) $data['page'] : null, isset($data['pages']) ? (int) $data['pages'] : null);

        return response()->noContent();
    }

    public function store(Request $request, LocalOcr $ocr, string $id): Response|JsonResponse
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:200000'],
            'failed' => ['nullable', 'boolean'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            'reason' => ['nullable', 'string', 'max:300'],
            'pages' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'layer' => ['nullable', 'integer', 'min:0', 'max:65535'],
            // Point 119: word positions as JSON (OcrWords shape, 0..1), checked and cleaned by OcrWords::fromBrowser().
            'words' => ['nullable', 'string', 'max:2097152'],
        ]);
        $document = OcrAccess::editable($request)->whereKey($id)->firstOrFail();
        $rescan = (bool) $document->getAttribute('ocr_rescan');
        // A84: another window or the server already read it – only the first result counts (a document marked for a
        // re-read takes its new read, F17).
        if ($document->ocr_status === 'done' && ! $rescan) {
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
            'document_id' => $document->id, 'duration_ms' => $duration,
            'user_id' => OcrAccess::userId($request), 'agent' => OcrLog::agent($request->userAgent()),
        ];
        if ($data['failed'] ?? false) {
            // Point 58/A63: time limit or error. It comes again; after three failures it stops – a re-read keeps its
            // earlier text, a document without text is marked failed.
            $failures = (int) $document->ocr_failures + 1;
            $values = ['ocr_failures' => min(255, $failures)];
            if ($failures >= self::MAX_FAILURES) {
                $values += $read ? [] : ['ocr_status' => 'failed', 'ocr_pass' => null];
                $values['ocr_rescan'] = false;
            }
            $document->forceFill($values)->save();
            $reason = is_string($data['reason'] ?? null) ? $data['reason'] : null;
            $message = $reason === 'timeout'
                ? OcrLog::text('ocr.reason.timeout', ['seconds' => self::BROWSER_LIMIT])
                : ($reason !== null ? mb_substr($reason, 0, 160) : null);
            OcrLog::record($document->org_id, 'browser', $reason === 'timeout' ? 'timeout' : 'failed', [...$log, 'message' => $message]);
        } else {
            $text = trim((string) ($data['text'] ?? ''));
            $words = is_string($data['words'] ?? null) ? OcrWords::fromBrowser($data['words']) : null;
            $document->forceFill([
                'ocr_text' => OcrFixes::reapply($document, $text, $words), 'ocr_words' => OcrWords::encode($words), 'ocr_status' => 'done',
                'ocr_pass' => OcrProgress::FULL, 'ocr_failures' => 0, 'ocr_rescan' => false,
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
}

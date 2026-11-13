<?php

declare(strict_types=1);

namespace App\Jobs\Local;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\RichText;
use App\Domain\Documents\TextDocument;
use App\Domain\Ocr\OcrFixes;
use App\Domain\Ocr\OcrLog;
use App\Domain\Ocr\OcrWords;
use App\Domain\Org\OrgScope;
use App\Events\TextRecognized;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrProgress;
use App\Jobs\Local\Ocr\Deadline;
use App\Jobs\Local\Ocr\ImageReader;
use App\Jobs\Local\Ocr\OcrTimeout;
use App\Jobs\Local\Ocr\OcrTools;
use App\Jobs\Local\Ocr\PdfReader;
use App\Queue\QueueName;
use Closure;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Reads the text of a receipt with the OCR add-on (tesseract, ocrmypdf) or
 * the app-owned portable Tesseract (point 18), so the search finds it. Runs
 * on the local queue (no network, D-020), one document per job and only one
 * OCR at a time, at low priority with one thread; external programs only
 * with argument arrays (PLAN §11.2).
 *
 * Point 69: PDFs with a text layer give that text (A114); without ocrmypdf the
 * app-owned Xpdf tools turn pages into temporary images for Tesseract (A113,
 * at most 50 pages). Every outcome goes to the OCR log with file details and a
 * plain reason (A106-A108).
 *
 * Point 130 (A275/A276): one read at full resolution with 10 minutes per document; only when that runs over the
 * limit, one single retry scaled down to about 2000 px. The programs live in App\Jobs\Local\Ocr.
 */
final class RecognizeText implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Point 130/A275: time limit of one read of a document (full resolution, and again for the A276 retry). */
    public const int DOCUMENT_SECONDS = 600;

    /** Waiting for the OCR lock releases the job; real errors fail it at once. */
    public int $maxExceptions = 1;

    /** Both reads plus a margin; below the queue's retry_after (config/queue.php). */
    public int $timeout = 2 * self::DOCUMENT_SECONDS + 60;

    /** @param  bool  $rescan  F17: a re-read for word positions, on the low queue behind new uploads */
    public function __construct(public readonly string $documentId, public readonly bool $rescan = false)
    {
        $this->onQueue(($rescan ? QueueName::LocalLow : QueueName::Local)->value);
    }

    /** F17: one low-priority job per document marked for a re-read. */
    public static function queueRescans(): void
    {
        Document::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('ocr_rescan', true)
            ->select('id')->eachById(static fn (Document $d) => self::dispatch($d->id, true));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('ocr'))->releaseAfter(30)->expireAfter(2 * self::DOCUMENT_SECONDS + 100)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(LocalOcr $ocr): void
    {
        $document = Document::query()->withoutGlobalScopes()->find($this->documentId);
        if ($document === null) {
            return;
        }
        // F17: a low-priority re-read whose document was read again meanwhile (browser, "Neu erkennen").
        $rescan = (bool) $document->getAttribute('ocr_rescan');
        if ($this->rescan && ! $rescan) {
            return;
        }
        // A re-read keeps the text it has until a new one replaces it (search keeps working).
        $hasText = $rescan && $document->getAttribute('ocr_status') === 'done';
        $file = DocumentFile::query()->withoutGlobalScope(OrgScope::class)
            ->where('document_id', $document->id)->orderBy('page_no')->first();
        // A text document written in the app: its text is known, nothing to read.
        if ($file !== null && TextDocument::isText($file)) {
            $display = TextDocument::display($file);
            $plain = TextDocument::isRich($file) ? RichText::toPlainText($display) : $display;
            self::finish($document, ['ocr_text' => $plain, 'ocr_words' => null, 'ocr_status' => 'done', 'ocr_pass' => OcrProgress::FULL, 'ocr_rescan' => false]);
            TextRecognized::dispatch($document->id);

            return;
        }

        $system = $ocr->systemAvailable();
        $app = ! $system && $ocr->appAvailable();
        $source = $system ? 'system' : 'app';
        $route = self::route($file, $ocr, $system, $app);
        if ($route === 'browser') {
            // The browser picks the document up itself (images in mode browser, PDFs whenever the server can't read them);
            // a re-read stays "done" with its flag, the browser hands it out after the new documents.
            if (! $hasText) {
                self::finish($document, ['ocr_status' => 'pending']);
            }

            return;
        }
        if ($file === null || ! in_array($route, ['image', 'pdf'], true)) {
            OcrLog::unavailable($document->org_id, $document->id, $system || $app ? $source : 'system', $route, OcrLog::file($file));
            self::finish($document, $hasText ? ['ocr_rescan' => false] : ['ocr_status' => 'unavailable', 'ocr_rescan' => false]);

            return;
        }

        OcrProgress::start($document);
        $started = hrtime(true);
        $text = null;
        $words = null;
        $event = 'recognized';
        $pages = $file->isImage() ? 1 : null;
        $error = null;
        try {
            $path = Storage::disk('local')->path($file->path);
            if (! is_file($path)) {
                throw new RuntimeException(OcrLog::text('ocr.reason.file_missing'));
            }
            $tools = new OcrTools($ocr, $app);
            if ($route === 'image') {
                $reader = new ImageReader($tools);
                [$text, $words] = self::withRetry(
                    static fn (Deadline $d): array => $reader->read($path, $document->id, $d),
                    ImageReader::canShrink($path) ? static fn (Deadline $d): array => $reader->readShrunk($path, $document->id, $d) : null,
                );
            } else {
                $reader = new PdfReader($tools, $system);
                // ocrmypdf picks its own resolution: no smaller retry there.
                [$text, $event, $pages, $words] = self::withRetry(
                    static fn (Deadline $d): array => $reader->read($path, $document->id, $d),
                    $system ? null : static fn (Deadline $d): array => $reader->read($path, $document->id, $d, PdfReader::RETRY_DPI),
                );
            }
        } catch (OcrTimeout $e) {
            $error = ['timeout', OcrLog::text('ocr.reason.timeout', ['seconds' => $e->seconds])];
        } catch (Throwable $e) {
            $error = ['failed', OcrTools::short($e->getMessage())];
        } finally {
            OcrProgress::finish($document->id);
        }

        // Point 61/69: the OCR log, without the text itself.
        $log = [...OcrLog::file($file, $pages), 'document_id' => $document->id, 'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000)];
        if ($error === null && $text !== null) {
            OcrLog::record($document->org_id, $source, $event, [...$log, 'chars' => mb_strlen(trim($text))]);
        } else {
            OcrLog::record($document->org_id, $source, $error[0] ?? 'failed', [...$log, 'message' => $error[1] ?? null]);
        }

        if ($text === null && $hasText) {
            // A failed re-read keeps the earlier text.
            self::finish($document, ['ocr_rescan' => false]);

            return;
        }
        self::finish($document, [
            // Point 122.7: the document's corrections go into the new text again.
            'ocr_text' => $text !== null ? OcrFixes::reapply($document, trim($text), $words) : null,
            'ocr_words' => $text !== null ? OcrWords::encode($words) : null,
            'ocr_status' => $text !== null ? 'done' : 'failed',
            // Point 130: one read at full resolution.
            'ocr_pass' => $text !== null ? OcrProgress::FULL : null,
            'ocr_rescan' => false,
        ]);
        if ($text !== null) {
            TextRecognized::dispatch($document->id);
        }
    }

    /**
     * Who reads the file: "image" or "pdf" (the server), "browser" (it waits for the browser), or the plain reason
     * why nobody can (A107).
     */
    private static function route(?DocumentFile $file, LocalOcr $ocr, bool $system, bool $app): string
    {
        if ($file === null) {
            return OcrLog::text('ocr.reason.no_file');
        }
        if (in_array($file->mime, LocalOcr::BROWSER_SKIP_MIMES, true)) {
            return OcrLog::text($system || $app ? 'ocr.reason.heic_server' : 'ocr.reason.heic_browser');
        }
        if ($file->isImage()) {
            return match (true) {
                $system || $app => 'image',
                $ocr->mode() === 'browser' => 'browser',
                default => OcrLog::text('ocr.reason.none'),
            };
        }
        if ($file->mime === 'application/pdf') {
            return match (true) {
                $system, $app && $ocr->pdfAvailable() => 'pdf',
                $ocr->browserLoop() => 'browser',
                $app => OcrLog::text('ocr.reason.pdf_no_tool'),
                default => OcrLog::text('ocr.reason.none'),
            };
        }

        return OcrLog::text('ocr.reason.type', ['type' => OcrLog::type($file->mime)]);
    }

    /** @param array<string, mixed> $values */
    private static function finish(Document $document, array $values): void
    {
        $document->forceFill($values)->save();
        OcrProgress::forgetWaiting($document->org_id);
    }

    /**
     * A276: the full read, and only when it runs over its time limit one single retry (with a time limit of its own).
     *
     * @template T
     *
     * @param  Closure(Deadline): T  $full
     * @param  (Closure(Deadline): T)|null  $retry
     * @return T
     */
    private static function withRetry(Closure $full, ?Closure $retry): mixed
    {
        try {
            return $full(new Deadline(self::DOCUMENT_SECONDS));
        } catch (OcrTimeout $e) {
            if ($retry === null) {
                throw $e;
            }

            return $retry(new Deadline(self::DOCUMENT_SECONDS));
        }
    }
}

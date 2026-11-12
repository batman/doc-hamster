<?php

declare(strict_types=1);

namespace App\Jobs\Local;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\RichText;
use App\Domain\Documents\TextDocument;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\OrgScope;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrProgress;
use App\Queue\QueueName;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads the text of a receipt with the OCR add-on (tesseract, ocrmypdf) or
 * the app-owned portable Tesseract (point 18), so the search finds it. Runs
 * on the local queue (no network, D-020), one document per job and only one
 * OCR at a time, at low priority with one thread; external programs only
 * with argument arrays (PLAN §11.2).
 */
final class RecognizeText implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int TIMEOUT_SECONDS = 120;

    /** Waiting for the OCR lock releases the job; real errors fail it at once. */
    public int $maxExceptions = 1;

    public int $timeout = 150;

    public function __construct(public readonly string $documentId)
    {
        $this->onQueue(QueueName::Local->value);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('ocr'))->releaseAfter(30)->expireAfter(300)];
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
        $file = DocumentFile::query()->withoutGlobalScope(OrgScope::class)
            ->where('document_id', $document->id)->orderBy('page_no')->first();
        // A text document written in the app: its text is known, nothing to read.
        if ($file !== null && TextDocument::isText($file)) {
            $display = TextDocument::display($file);
            $plain = TextDocument::isRich($file) ? RichText::toPlainText($display) : $display;
            self::finish($document, ['ocr_text' => $plain, 'ocr_status' => 'done', 'ocr_pass' => OcrProgress::PASSES]);

            return;
        }

        $system = $ocr->systemAvailable();
        if (! $system && ! $ocr->appAvailable()) {
            // The browser fallback picks up readable images and PDFs (pdf.js) itself; they keep waiting for it (point 68).
            $browser = $file !== null && $ocr->mode() === 'browser'
                && ($file->mime === 'application/pdf' || ($file->isImage() && ! in_array($file->mime, LocalOcr::BROWSER_SKIP_MIMES, true)));
            self::finish($document, ['ocr_status' => $browser ? 'pending' : 'unavailable']);

            return;
        }

        // The portable binary reads images only; PDFs need ocrmypdf.
        if ($file !== null && ! $system && ! $file->isImage()) {
            self::finish($document, ['ocr_status' => 'unavailable']);

            return;
        }

        OcrProgress::start($document);
        $started = hrtime(true);
        $timedOut = false;
        try {
            $text = $file !== null ? self::recognize($file, $ocr, ! $system) : null;
        } catch (ProcessTimedOutException) {
            $text = null;
            $timedOut = true;
        } finally {
            OcrProgress::finish($document->id);
        }
        // Point 61: the OCR log, without the text itself.
        $log = ['document_id' => $document->id, 'pass' => OcrProgress::PASSES, 'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000)];
        OcrLog::record($document->org_id, $system ? 'system' : 'app', $text !== null ? 'recognized' : ($timedOut ? 'timeout' : 'failed'), $text !== null
            ? [...$log, 'chars' => mb_strlen(trim($text))]
            : [...$log, 'message' => $file === null ? 'no file' : ($timedOut ? null : 'tesseract/ocrmypdf error')]);

        self::finish($document, [
            'ocr_text' => $text !== null ? trim($text) : null,
            'ocr_status' => $text !== null ? 'done' : 'failed',
            // The server reads once at full resolution: the last pass (point 56).
            'ocr_pass' => $text !== null ? OcrProgress::PASSES : null,
        ]);
    }

    /** @param array<string, mixed> $values */
    private static function finish(Document $document, array $values): void
    {
        $document->forceFill($values)->save();
        OcrProgress::forgetWaiting($document->org_id);
    }

    private static function recognize(DocumentFile $file, LocalOcr $ocr, bool $app): ?string
    {
        $path = Storage::disk('local')->path($file->path);
        if (! is_file($path)) {
            return null;
        }

        try {
            if ($file->isImage()) {
                $tesseract = $app ? [] : ['tesseract'];

                return self::run($ocr->command([...$tesseract, $path, 'stdout', '-l', 'deu+eng'], $app), $ocr->env($app));
            }
            if ($file->mime === 'application/pdf') {
                return self::pdf($path, $ocr);
            }
        } catch (ProcessTimedOutException $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** --skip-text keeps pages that already have text and still writes their text to the sidecar. */
    private static function pdf(string $path, LocalOcr $ocr): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'ocr');
        if ($base === false) {
            return null;
        }
        $sidecar = $base.'.txt';
        $out = $base.'.pdf';

        try {
            $command = $ocr->niced(['ocrmypdf', '--jobs', '1', '--sidecar', $sidecar, '--skip-text', '-l', 'deu+eng', '--quiet', $path, $out]);
            $ok = self::run($command, $ocr->env(false)) !== null;
            $text = $ok && is_file($sidecar) ? file_get_contents($sidecar) : false;

            return $text === false ? null : $text;
        } finally {
            foreach ([$base, $sidecar, $out] as $tmp) {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private static function run(array $command, array $env): ?string
    {
        $process = new Process($command, null, $env, null, self::TIMEOUT_SECONDS);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}

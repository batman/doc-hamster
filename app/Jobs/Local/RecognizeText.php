<?php

declare(strict_types=1);

namespace App\Jobs\Local;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\RichText;
use App\Domain\Documents\TextDocument;
use App\Domain\Ocr\OcrLog;
use App\Domain\Ocr\OcrWords;
use App\Domain\Org\OrgScope;
use App\Events\TextRecognized;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrProgress;
use App\Queue\QueueName;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
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
 */
final class RecognizeText implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Per program run (one image, one PDF page, ocrmypdf on a whole PDF). */
    public const int TIMEOUT_SECONDS = 120;

    /** A whole PDF read page by page; stays below the queue's retry_after (600 s). */
    public const int PDF_SECONDS = 480;

    /** A page with at least this many characters (without spaces) in its text layer needs no OCR (same rule as the browser). */
    private const int LAYER_CHARS = 20;

    /** Timeouts are thrown as this code with the limit in seconds as message. */
    private const int TIME_LIMIT = 408;

    /** Waiting for the OCR lock releases the job; real errors fail it at once. */
    public int $maxExceptions = 1;

    public int $timeout = 540;

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
        return [(new WithoutOverlapping('ocr'))->releaseAfter(30)->expireAfter(600)];
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
            self::finish($document, ['ocr_text' => $plain, 'ocr_words' => null, 'ocr_status' => 'done', 'ocr_pass' => OcrProgress::PASSES, 'ocr_rescan' => false]);
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
            if ($route === 'image') {
                [$text, $page] = self::tesseract($path, 1, $ocr, $app);
                $words = OcrWords::make('tesseract', [$page]);
            } else {
                [$text, $event, $pages, $words] = self::pdf($path, $ocr, $system);
            }
        } catch (ProcessTimedOutException $e) {
            $error = ['timeout', OcrLog::text('ocr.reason.timeout', ['seconds' => (int) $e->getProcess()->getTimeout()])];
        } catch (Throwable $e) {
            $error = $e->getCode() === self::TIME_LIMIT
                ? ['timeout', OcrLog::text('ocr.reason.timeout', ['seconds' => (int) $e->getMessage()])]
                : ['failed', self::short($e->getMessage())];
        } finally {
            OcrProgress::finish($document->id);
        }

        // Point 61/69: the OCR log, without the text itself.
        $log = [...OcrLog::file($file, $pages), 'document_id' => $document->id, 'pass' => OcrProgress::PASSES, 'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000)];
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
            'ocr_text' => $text !== null ? trim($text) : null,
            'ocr_words' => $text !== null ? OcrWords::encode($words) : null,
            'ocr_status' => $text !== null ? 'done' : 'failed',
            // The server reads once at full resolution: the last pass (point 56).
            'ocr_pass' => $text !== null ? OcrProgress::PASSES : null,
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
     * A114 first: a text layer on every page is taken as it is. Otherwise ocrmypdf (system) or, A113, each page
     * without text as a temporary image through Tesseract (app), at most 50 pages, deleted right after.
     *
     * Word positions (point 119): text-layer pages from `pdftotext -bbox-layout` (poppler); the app-owned Xpdf
     * pdftotext has no bbox output, so there a text-layer page is rendered and read by Tesseract for its positions
     * only (its text stays the layer's), best effort within the time limit. ocrmypdf: the boxes of its output PDF.
     *
     * @return array{0: string, 1: string, 2: ?int, 3: ?array<string, mixed>} text, event, pages, words
     */
    private static function pdf(string $path, LocalOcr $ocr, bool $system): array
    {
        $layer = self::layer($path, $ocr);
        $pages = $layer !== null ? count($layer) : null;
        $withText = $layer !== null ? count(array_filter($layer, self::hasText(...))) : 0;
        $layerWords = $withText > 0 ? self::boxes($path, $ocr) : null;
        $allLayer = $layer !== null && $layer !== [] && $withText === count($layer);
        if ($allLayer && ($layerWords !== null || $system)) {
            return [implode("\n\n", array_map('trim', $layer)), 'text_layer', $pages, OcrWords::make('layer', $layerWords ?? [])];
        }
        if ($system) {
            [$text, $words] = self::ocrmypdf($path, $ocr);

            return [$text, 'recognized', $pages, $words];
        }

        $deadline = time() + self::PDF_SECONDS;
        // Page images of a run that was killed (request or worker ended) are dropped after an hour.
        foreach (glob(storage_path('app/ocr/tmp/*'), GLOB_ONLYDIR) ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                File::deleteDirectory($old);
            }
        }
        $dir = storage_path('app/ocr/tmp/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($dir);
        try {
            $texts = [];
            $found = [];
            $fromLayer = true;
            $byPage = [];
            foreach ($layerWords ?? [] as $page) {
                $byPage[$page['n']] = $page;
            }
            $last = min($pages ?? LocalOcr::PDF_MAX_PAGES, LocalOcr::PDF_MAX_PAGES);
            for ($n = 1; $n <= $last; $n++) {
                $own = $layer[$n - 1] ?? '';
                $isLayer = self::hasText($own);
                if ($isLayer && isset($byPage[$n])) {
                    $texts[] = trim($own);
                    $found[] = $byPage[$n];

                    continue;
                }
                $left = $deadline - time();
                if ($left <= 5) {
                    if ($isLayer) {
                        // Only the positions are missing: the text is there, the rest of the time is not.
                        $texts[] = trim($own);

                        continue;
                    }
                    throw new RuntimeException((string) self::PDF_SECONDS, self::TIME_LIMIT);
                }
                $fromLayer = false;
                $root = $dir.'/p';
                try {
                    self::run($ocr->niced([$ocr->pdfTool('pdftopng'), '-f', (string) $n, '-l', (string) $n, '-r', '300', '-gray', $path, $root]), [], min(self::TIMEOUT_SECONDS, $left));
                } catch (Throwable $e) {
                    if (! $isLayer) {
                        throw $e;
                    }
                }
                $images = glob($root.'-*.png') ?: [];
                if ($images === []) {
                    if ($isLayer) {
                        $texts[] = trim($own);

                        continue;
                    }
                    // Without a text layer the page count is unknown: no image means the PDF has ended.
                    if ($pages === null) {
                        $pages = $n - 1;
                        break;
                    }

                    continue;
                }
                try {
                    $left = $deadline - time();
                    try {
                        [$read, $page] = self::tesseract($images[0], $n, $ocr, true, min(self::TIMEOUT_SECONDS, max(1, $left)));
                    } catch (Throwable $e) {
                        if (! $isLayer) {
                            throw $e;
                        }
                        [$read, $page] = ['', null];
                    }
                    // A text-layer page keeps its own text; Tesseract gave only the positions.
                    $texts[] = $isLayer ? trim($own) : trim($read);
                    $found[] = $page;
                } finally {
                    array_map('unlink', $images);
                }
            }
            $allLayer = $layer !== null && $withText === count($layer) && $withText > 0;

            return [
                implode("\n\n", array_filter($texts, static fn (string $t): bool => $t !== '')),
                $allLayer ? 'text_layer' : 'recognized',
                $pages ?? $last,
                OcrWords::make($fromLayer ? 'layer' : 'tesseract', $found),
            ];
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * The text layer per page (pdftotext ends every page with a form feed), or null without pdftotext or on error.
     *
     * @return list<string>|null
     */
    private static function layer(string $path, LocalOcr $ocr): ?array
    {
        $tool = $ocr->pdfTextTool();
        if ($tool === null) {
            return null;
        }
        try {
            $out = self::run($ocr->niced([$tool, '-enc', 'UTF-8', $path, '-']), []);
        } catch (Throwable) {
            return null;
        }
        $pages = explode("\f", $out);
        if (count($pages) > 1 && trim((string) end($pages)) === '') {
            array_pop($pages);
        }

        return $pages;
    }

    /**
     * Word boxes of the text layer from `pdftotext -bbox-layout` (first 50 pages): a system poppler pdftotext first,
     * then the app's own. Null when none has bbox output (Xpdf 4.06 has none) or all fail.
     *
     * @return list<array{n: int, words: list<array<string, mixed>>}>|null
     */
    private static function boxes(string $path, LocalOcr $ocr): ?array
    {
        $tools = array_unique(array_filter([(new ExecutableFinder)->find('pdftotext'), $ocr->pdfTextTool()]));
        foreach ($tools as $tool) {
            try {
                $pages = OcrWords::pagesFromBboxLayout(self::run($ocr->niced([$tool, '-bbox-layout', '-enc', 'UTF-8', '-l', (string) LocalOcr::PDF_MAX_PAGES, $path, '-']), []));
            } catch (Throwable) {
                continue;
            }
            if ($pages !== null) {
                return $pages;
            }
        }

        return null;
    }

    /**
     * One image through Tesseract in one run: the plain text (as before) and the TSV with the word boxes.
     *
     * @return array{0: string, 1: array{n: int, words: list<array<string, mixed>>}|null} text, the page's words
     */
    private static function tesseract(string $image, int $n, LocalOcr $ocr, bool $app, int $timeout = self::TIMEOUT_SECONDS): array
    {
        $base = tempnam(sys_get_temp_dir(), 'tes');
        if ($base === false) {
            throw new RuntimeException('tempnam');
        }
        try {
            self::run($ocr->command([...($app ? [] : ['tesseract']), $image, $base, '-l', 'deu+eng', '-c', 'tessedit_create_txt=1', '-c', 'tessedit_create_tsv=1'], $app), $ocr->env($app), $timeout);
            $text = is_file($base.'.txt') ? file_get_contents($base.'.txt') : false;
            if ($text === false) {
                throw new RuntimeException('tesseract: keine Textdatei');
            }
            $tsv = is_file($base.'.tsv') ? file_get_contents($base.'.tsv') : false;

            return [$text, $tsv !== false ? OcrWords::pageFromTsv($tsv, $n) : null];
        } finally {
            foreach ([$base, $base.'.txt', $base.'.tsv'] as $tmp) {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    private static function hasText(string $text): bool
    {
        return mb_strlen((string) preg_replace('/\s+/u', '', $text)) >= self::LAYER_CHARS;
    }

    /**
     * --skip-text keeps pages that already have text and still writes their text to the sidecar. The word positions
     * come from the text layer of ocrmypdf's output PDF (poppler pdftotext -bbox-layout), when that tool is there.
     *
     * @return array{0: string, 1: ?array<string, mixed>} text, words
     */
    private static function ocrmypdf(string $path, LocalOcr $ocr): array
    {
        $base = tempnam(sys_get_temp_dir(), 'ocr');
        if ($base === false) {
            throw new RuntimeException('tempnam');
        }
        $sidecar = $base.'.txt';
        $out = $base.'.pdf';

        try {
            self::run($ocr->niced(['ocrmypdf', '--jobs', '1', '--sidecar', $sidecar, '--skip-text', '-l', 'deu+eng', '--quiet', $path, $out]), $ocr->env(false));
            $text = is_file($sidecar) ? file_get_contents($sidecar) : false;
            if ($text === false) {
                throw new RuntimeException('ocrmypdf: keine Textdatei');
            }
            $boxes = is_file($out) ? self::boxes($out, $ocr) : null;

            return [$text, OcrWords::make('tesseract', $boxes ?? [])];
        } finally {
            foreach ([$base, $sidecar, $out] as $tmp) {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    /**
     * Runs a program and returns its output; a failure throws with the program's own short message (A107).
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private static function run(array $command, array $env, int $timeout = self::TIMEOUT_SECONDS): string
    {
        $process = new Process($command, null, $env, null, $timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(self::tool($command).': '.(trim($process->getErrorOutput()) ?: 'Exit '.$process->getExitCode()));
        }

        return $process->getOutput();
    }

    /**
     * The program's short name for messages (tesseract, pdftopng, ocrmypdf …), skipping nice.
     *
     * @param  list<string>  $command
     */
    private static function tool(array $command): string
    {
        foreach ($command as $part) {
            $name = basename($part);
            if (in_array($name, ['nice', '-n', '19'], true)) {
                continue;
            }

            return $name === 'AppRun' ? 'tesseract' : $name;
        }

        return 'tool';
    }

    /** The last meaningful line of an error message, short (A107). */
    private static function short(string $message): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $message) ?: []), static fn (string $l): bool => $l !== ''));
        if ($lines === []) {
            return '?';
        }
        $prefix = str_contains($lines[0], ': ') ? strstr($lines[0], ': ', true).': ' : '';
        $last = end($lines);
        $text = count($lines) > 1 && $prefix !== '' && ! str_starts_with($last, $prefix) ? $prefix.$last : $last;

        return mb_substr($text, 0, 160);
    }
}

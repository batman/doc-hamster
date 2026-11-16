<?php

declare(strict_types=1);

namespace App\Jobs\Local\Ocr;

use App\Domain\Ocr\OcrWords;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrProgress;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Reads a PDF on the server (point 69). A114 first: a text layer on every page is taken as it is. Otherwise, A113,
 * each page without text as a temporary image through Tesseract (app pdftopng, or 186.5 a system pdftoppm), at most 50
 * pages, deleted right after – with the same steps as the browser (OcrTools::tesseract). ocrmypdf only when the
 * system has no page renderer.
 *
 * Word positions (point 119): text-layer pages from `pdftotext -bbox-layout` (poppler); the app-owned Xpdf pdftotext
 * has no bbox output, so there a text-layer page is rendered and read by Tesseract for its positions only (its text
 * stays the layer's), best effort within the time limit. ocrmypdf: the boxes of its output PDF.
 */
final class PdfReader
{
    /** Full resolution for the page images. */
    public const int DPI = 300;

    /** A276: the single retry after a timeout – about 2000 px on the long side of an A4 page. */
    public const int RETRY_DPI = 170;

    /** A page with at least this many characters (without spaces) in its text layer needs no OCR (same rule as the browser). */
    private const int LAYER_CHARS = 20;

    /** @param  PageShaper|null  $shaper  point 238: pages read turned/cropped as shown, text-layer boxes moved onto them */
    public function __construct(private readonly OcrTools $tools, private readonly bool $system, private readonly ?PageShaper $shaper = null) {}

    /**
     * @return array{0: string, 1: string, 2: ?int, 3: array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null} text, event, pages, words
     */
    public function read(string $path, string $documentId, Deadline $deadline, int $dpi = self::DPI): array
    {
        $layer = $this->tools->layer($path, $deadline);
        $pages = $layer !== null ? count($layer) : null;
        // Point 124: the live card shows "Seite x von y" while the server reads.
        OcrProgress::page($documentId, 1, $pages);
        $withText = $layer !== null ? count(array_filter($layer, self::hasText(...))) : 0;
        $layerWords = $withText > 0 ? $this->tools->boxes($path, $deadline) : null;
        $allLayer = $layer !== null && $layer !== [] && $withText === count($layer);
        if ($allLayer && ($layerWords !== null || $this->system)) {
            return [implode("\n\n", array_map('trim', $layer)), 'text_layer', $pages, OcrWords::make('layer', $layerWords ?? [])];
        }
        // 186.5: ocrmypdf only when no tool turns pages into images – it has no line removal and no safety net.
        if ($this->system && $this->tools->ocr()->renderTool() === null) {
            [$text, $words] = $this->tools->ocrmypdf($path, $deadline);

            return [$text, 'recognized', $pages, $words === null ? null : OcrWords::make($words['source'], $this->shown($words['pages']))];
        }

        // Page images of a run that was killed (request or worker ended) are dropped after an hour.
        foreach (glob(storage_path('app/ocr/tmp/*'), GLOB_ONLYDIR) ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                File::deleteDirectory($old);
            }
        }
        $dir = storage_path('app/ocr/tmp/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($dir);
        try {
            return $this->pages($path, $documentId, $deadline, $dpi, $dir, $layer, $layerWords);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Page by page with the app-owned pdftopng and Tesseract.
     *
     * @param  list<string>|null  $layer
     * @param  list<array{n: int, words: list<array<string, mixed>>}>|null  $layerWords
     * @return array{0: string, 1: string, 2: ?int, 3: array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null}
     */
    private function pages(string $path, string $documentId, Deadline $deadline, int $dpi, string $dir, ?array $layer, ?array $layerWords): array
    {
        $ocr = $this->tools->ocr();
        $pages = $layer !== null ? count($layer) : null;
        $texts = [];
        $found = [];
        $fromLayer = true;
        $byPage = [];
        foreach ($layerWords ?? [] as $page) {
            $byPage[$page['n']] = $page;
        }
        $last = min($pages ?? LocalOcr::PDF_MAX_PAGES, LocalOcr::PDF_MAX_PAGES);
        for ($n = 1; $n <= $last; $n++) {
            OcrProgress::page($documentId, $n, $pages);
            $own = $layer[$n - 1] ?? '';
            $isLayer = self::hasText($own);
            if ($isLayer && isset($byPage[$n])) {
                $texts[] = trim($own);
                $found[] = $this->shaper?->fromOriginal($byPage[$n]) ?? $byPage[$n];

                continue;
            }
            if ($deadline->passed(5)) {
                if ($isLayer) {
                    // Only the positions are missing: the text is there, the rest of the time is not.
                    $texts[] = trim($own);

                    continue;
                }
                throw new OcrTimeout($deadline->seconds);
            }
            $fromLayer = false;
            $root = $dir.'/p';
            try {
                [$tool, $kind] = $ocr->renderTool() ?? throw new RuntimeException('pdftopng: fehlt');
                $this->tools->run($ocr->niced([$tool, '-f', (string) $n, '-l', (string) $n, '-r', (string) $dpi, '-gray', ...($kind === 'pdftoppm' ? ['-png'] : []), $path, $root]), [], $deadline);
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
                try {
                    [$read, $page] = $this->tools->tesseract($this->shaper?->input($images[0], $n, false, $deadline) ?? $images[0], $n, $deadline);
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
        $withText = $layer !== null ? count(array_filter($layer, self::hasText(...))) : 0;
        $allLayer = $layer !== null && $withText === count($layer) && $withText > 0;

        return [
            implode("\n\n", array_filter($texts, static fn (string $t): bool => $t !== '')),
            $allLayer ? 'text_layer' : 'recognized',
            $pages ?? $last,
            OcrWords::make($fromLayer ? 'layer' : 'tesseract', $found),
        ];
    }

    /**
     * @param  list<array{n: int, words: list<array<string, mixed>>}>  $pages
     * @return list<array{n: int, words: list<array<string, mixed>>}>
     */
    private function shown(array $pages): array
    {
        return $this->shaper?->allFromOriginal($pages) ?? $pages;
    }

    private static function hasText(string $text): bool
    {
        return mb_strlen((string) preg_replace('/\s+/u', '', $text)) >= self::LAYER_CHARS;
    }
}

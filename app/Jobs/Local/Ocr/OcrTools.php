<?php

declare(strict_types=1);

namespace App\Jobs\Local\Ocr;

use App\Domain\Ocr\OcrWords;
use App\Infrastructure\Environment\LocalOcr;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The OCR programs on the server (tesseract, ocrmypdf, pdftotext): always with argument arrays (PLAN §11.2), at low
 * priority, and within the document's time limit (point 130).
 */
final class OcrTools
{
    public function __construct(private readonly LocalOcr $ocr, private readonly bool $app) {}

    public function ocr(): LocalOcr
    {
        return $this->ocr;
    }

    /**
     * One image through Tesseract in one run: the plain text and the TSV with the word boxes.
     *
     * @return array{0: string, 1: array{n: int, words: list<array<string, mixed>>}|null} text, the page's words
     */
    public function tesseract(string $image, int $n, Deadline $deadline, ?bool $app = null): array
    {
        $app ??= $this->app;
        $base = tempnam(sys_get_temp_dir(), 'tes');
        if ($base === false) {
            throw new RuntimeException('tempnam');
        }
        try {
            $this->run($this->ocr->command([...($app ? [] : ['tesseract']), $image, $base, '-l', 'deu+eng', '-c', 'tessedit_create_txt=1', '-c', 'tessedit_create_tsv=1'], $app), $this->ocr->env($app), $deadline);
            $text = is_file($base.'.txt') ? file_get_contents($base.'.txt') : false;
            if ($text === false) {
                throw new RuntimeException('tesseract: keine Textdatei');
            }
            $tsv = is_file($base.'.tsv') ? file_get_contents($base.'.tsv') : false;

            return [$text, $tsv !== false ? OcrWords::pageFromTsv($tsv, $n) : null];
        } finally {
            self::unlink([$base, $base.'.txt', $base.'.tsv']);
        }
    }

    /**
     * --skip-text keeps pages that already have text and still writes their text to the sidecar. The word positions
     * come from the text layer of ocrmypdf's output PDF (poppler pdftotext -bbox-layout), when that tool is there.
     *
     * @return array{0: string, 1: array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null} text, words
     */
    public function ocrmypdf(string $path, Deadline $deadline): array
    {
        $base = tempnam(sys_get_temp_dir(), 'ocr');
        if ($base === false) {
            throw new RuntimeException('tempnam');
        }
        $sidecar = $base.'.txt';
        $out = $base.'.pdf';

        try {
            $this->run($this->ocr->niced(['ocrmypdf', '--jobs', '1', '--sidecar', $sidecar, '--skip-text', '-l', 'deu+eng', '--quiet', $path, $out]), $this->ocr->env(false), $deadline);
            $text = is_file($sidecar) ? file_get_contents($sidecar) : false;
            if ($text === false) {
                throw new RuntimeException('ocrmypdf: keine Textdatei');
            }
            $boxes = is_file($out) ? $this->boxes($out, $deadline) : null;

            return [$text, OcrWords::make('tesseract', $boxes ?? [])];
        } finally {
            self::unlink([$base, $sidecar, $out]);
        }
    }

    /**
     * The text layer per page (pdftotext ends every page with a form feed), or null without pdftotext or on error.
     *
     * @return list<string>|null
     */
    public function layer(string $path, Deadline $deadline): ?array
    {
        $tool = $this->ocr->pdfTextTool();
        if ($tool === null) {
            return null;
        }
        try {
            $out = $this->run($this->ocr->niced([$tool, '-enc', 'UTF-8', $path, '-']), [], $deadline);
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
    public function boxes(string $path, Deadline $deadline): ?array
    {
        $tools = array_unique(array_filter([(new ExecutableFinder)->find('pdftotext'), $this->ocr->pdfTextTool()]));
        foreach ($tools as $tool) {
            try {
                $pages = OcrWords::pagesFromBboxLayout($this->run($this->ocr->niced([$tool, '-bbox-layout', '-enc', 'UTF-8', '-l', (string) LocalOcr::PDF_MAX_PAGES, $path, '-']), [], $deadline));
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
     * Runs a program and returns its output; a failure throws with the program's own short message (A107), running
     * over the document's time limit throws OcrTimeout.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function run(array $command, array $env, Deadline $deadline): string
    {
        $process = new Process($command, null, $env, null, $deadline->left());
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new OcrTimeout($deadline->seconds);
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException(self::tool($command).': '.(trim($process->getErrorOutput()) ?: 'Exit '.$process->getExitCode()));
        }

        return $process->getOutput();
    }

    /** The last meaningful line of an error message, short (A107). */
    public static function short(string $message): string
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

    /** @param list<string> $files */
    public static function unlink(array $files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
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
}

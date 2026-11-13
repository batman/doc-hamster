<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use App\Domain\Documents\Document;
use DOMDocument;
use DOMElement;

/**
 * Word positions of the recognised text (points 119, 120.5): every recognition path (server Tesseract, the PDF text
 * layer, the browser) is normalised into one JSON stored in documents.ocr_words:
 *
 *   {"v":1,"source":"tesseract|layer|browser","pages":[{"n":1,"words":[{"t":"Rechnung","x":0.1234,"y":0.0456,
 *    "w":0.0812,"h":0.0121,"c":93,"l":17,"b":3}]}]}
 *
 * x/y/w/h relative to the page (0..1, origin top-left), c = confidence 0-100 (text layer = 100), l = line index and
 * b = block index per page (0-based), words in reading order. Corrections (documents.ocr_fixes) are applied by
 * position when reading, so they survive a new OCR run.
 */
final class OcrWords
{
    public const int VERSION = 1;

    /** Upper bounds against oversized input (browser posts, broken tool output). */
    private const int MAX_PAGES = 100;

    private const int MAX_WORDS = 20000;

    private const int MAX_WORD_CHARS = 200;

    /**
     * The decoded words of a document with its corrections applied, null when it has none.
     *
     * @return array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null
     */
    public static function forDocument(Document $d): ?array
    {
        $words = self::raw($d);
        if ($words === null) {
            return null;
        }
        $fixes = self::fixes($d);

        return $fixes !== [] ? self::applyFixes($words, $fixes) : $words;
    }

    /**
     * The decoded words as recognised, without corrections (null when none).
     *
     * @return array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null
     */
    public static function raw(Document $d): ?array
    {
        $raw = $d->getAttribute('ocr_words');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return self::sanitise(json_decode($raw, true), null);
    }

    /**
     * The stored corrections (documents.ocr_fixes), malformed entries dropped.
     *
     * @return list<array{p: int, x: float, y: float, w: float, h: float, t: string, o: string}>
     */
    public static function fixes(Document $d): array
    {
        $fixes = $d->getAttribute('ocr_fixes');
        $fixes = is_string($fixes) ? json_decode($fixes, true) : $fixes;
        $out = [];
        foreach (is_array($fixes) ? $fixes : [] as $fix) {
            if (is_array($fix) && is_string($fix['t'] ?? null)) {
                $out[] = [
                    'p' => (int) ($fix['p'] ?? 0), 'x' => self::num($fix['x'] ?? null), 'y' => self::num($fix['y'] ?? null),
                    'w' => self::num($fix['w'] ?? null), 'h' => self::num($fix['h'] ?? null), 't' => $fix['t'], 'o' => (string) ($fix['o'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * A fix replaces the text of the word on page p whose centre lies in the fix box.
     *
     * @param  array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}  $words
     * @param  array<mixed>  $fixes
     * @return array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}
     */
    public static function applyFixes(array $words, array $fixes): array
    {
        foreach ($fixes as $fix) {
            if (! is_array($fix) || ! is_string($fix['t'] ?? null)) {
                continue;
            }
            $p = (int) ($fix['p'] ?? 0);
            [$fx, $fy, $fw, $fh] = [self::num($fix['x'] ?? null), self::num($fix['y'] ?? null), self::num($fix['w'] ?? null), self::num($fix['h'] ?? null)];
            foreach ($words['pages'] as $pi => $page) {
                if ($page['n'] !== $p) {
                    continue;
                }
                foreach ($page['words'] as $wi => $word) {
                    $cx = (float) $word['x'] + (float) $word['w'] / 2;
                    $cy = (float) $word['y'] + (float) $word['h'] / 2;
                    if ($cx >= $fx && $cx <= $fx + $fw && $cy >= $fy && $cy <= $fy + $fh) {
                        $words['pages'][$pi]['words'][$wi]['t'] = $fix['t'];
                    }
                }
            }
        }

        return $words;
    }

    /**
     * Words grouped by line (l), each line sorted by x, lines in order of their first word.
     *
     * @param  array{n?: int, words: list<array<string, mixed>>}  $page
     * @return list<list<array<string, mixed>>>
     */
    public static function lines(array $page): array
    {
        $lines = [];
        foreach ($page['words'] as $word) {
            $lines[(int) ($word['l'] ?? 0)][] = $word;
        }
        $out = [];
        foreach ($lines as $line) {
            usort($line, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Text rebuilt from the words: words joined by a space, lines by \n, pages by \f.
     *
     * @param  array{pages: list<array{n?: int, words: list<array<string, mixed>>}>}  $words
     */
    public static function plainText(array $words): string
    {
        $pages = [];
        foreach ($words['pages'] as $page) {
            $pages[] = implode("\n", array_map(
                static fn (array $line): string => implode(' ', array_map(static fn (array $w): string => (string) $w['t'], $line)),
                self::lines($page),
            ));
        }

        return implode("\f", $pages);
    }

    /**
     * Lines for the reading view with the spaces before each word: gaps has one entry per word – gaps[0] is the
     * indent from the leftmost word of the page, gaps[i] the spaces between word i-1 and word i (at least 1).
     * Measured in the page's median character width.
     *
     * @param  array{n?: int, words: list<array<string, mixed>>}  $page
     * @return list<array{words: list<array<string, mixed>>, gaps: list<int>}>
     */
    public static function layoutText(array $page): array
    {
        $widths = [];
        $left = 1.0;
        foreach ($page['words'] as $word) {
            $chars = mb_strlen((string) $word['t']);
            if ($chars > 0 && (float) $word['w'] > 0) {
                $widths[] = (float) $word['w'] / $chars;
            }
            $left = min($left, (float) $word['x']);
        }
        sort($widths);
        $char = $widths !== [] ? $widths[intdiv(count($widths), 2)] : 0.01;
        $char = max($char, 0.001);

        $out = [];
        foreach (self::lines($page) as $line) {
            $gaps = [];
            $end = null;
            foreach ($line as $word) {
                $x = (float) $word['x'];
                $gaps[] = $end === null
                    ? min(200, max(0, (int) round(($x - $left) / $char)))
                    : min(200, max(1, (int) round(($x - $end) / $char)));
                $end = $x + (float) $word['w'];
            }
            $out[] = ['words' => $line, 'gaps' => $gaps];
        }

        return $out;
    }

    /**
     * The whole JSON from pages; null when no page has a word.
     *
     * @param  list<array{n: int, words: list<array<string, mixed>>}|null>  $pages
     * @return array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null
     */
    public static function make(string $source, array $pages): ?array
    {
        $pages = array_values(array_filter($pages, static fn (?array $p): bool => $p !== null && $p['words'] !== []));

        return $pages === [] ? null : ['v' => self::VERSION, 'source' => $source, 'pages' => $pages];
    }

    /** @param  array<string, mixed>|null  $words */
    public static function encode(?array $words): ?string
    {
        if ($words === null) {
            return null;
        }
        $json = json_encode($words, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }

    /**
     * One page from Tesseract's TSV output (level 1 = page size in px, level 5 = words).
     *
     * @return array{n: int, words: list<array<string, mixed>>}|null
     */
    public static function pageFromTsv(string $tsv, int $n): ?array
    {
        $width = 0;
        $height = 0;
        $words = [];
        $lineIds = [];
        $blockIds = [];
        foreach (preg_split('/\R/', $tsv) ?: [] as $row) {
            $cols = explode("\t", $row);
            if (count($cols) < 11 || ! ctype_digit($cols[0])) {
                continue;
            }
            $level = (int) $cols[0];
            if ($level === 1) {
                $width = max($width, (int) $cols[8]);
                $height = max($height, (int) $cols[9]);

                continue;
            }
            $text = trim($cols[11] ?? '');
            if ($level !== 5 || $text === '' || $width <= 0 || $height <= 0) {
                continue;
            }
            $block = $cols[2];
            $line = $cols[2].'.'.$cols[3].'.'.$cols[4];
            $blockIds[$block] ??= count($blockIds);
            $lineIds[$line] ??= count($lineIds);
            $words[] = self::word($text, (int) $cols[6] / $width, (int) $cols[7] / $height, (int) $cols[8] / $width, (int) $cols[9] / $height, (float) $cols[10], $lineIds[$line], $blockIds[$block]);
            if (count($words) >= self::MAX_WORDS) {
                break;
            }
        }

        return $words === [] ? null : ['n' => $n, 'words' => $words];
    }

    /**
     * Pages from `pdftotext -bbox-layout` (poppler): page size in pt, blocks, lines, words with xMin/yMin/xMax/yMax.
     * Page numbers start at $first (the -f option).
     *
     * @return list<array{n: int, words: list<array<string, mixed>>}>|null null when the output is no bbox layout
     */
    public static function pagesFromBboxLayout(string $xhtml, int $first = 1): ?array
    {
        if (! str_contains($xhtml, '<page')) {
            return null;
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadXML($xhtml, LIBXML_NONET | LIBXML_COMPACT)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $pages = [];
        $n = $first;
        foreach ($dom->getElementsByTagName('page') as $page) {
            $width = (float) $page->getAttribute('width');
            $height = (float) $page->getAttribute('height');
            $words = [];
            $l = 0;
            $b = 0;
            if ($width > 0 && $height > 0) {
                foreach ($page->getElementsByTagName('block') as $block) {
                    foreach ($block->getElementsByTagName('line') as $line) {
                        foreach ($line->getElementsByTagName('word') as $word) {
                            $words[] = self::bboxWord($word, $width, $height, $l, $b);
                        }
                        $l++;
                    }
                    $b++;
                }
            }
            $pages[] = ['n' => $n, 'words' => array_values(array_filter($words))];
            $n++;
        }

        return $pages;
    }

    /**
     * The browser's words (public/js/modules/ocr-browser.js), already 0..1: checked and cleaned; malformed words are
     * dropped, a malformed whole gives null.
     *
     * @return array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null
     */
    public static function fromBrowser(string $json): ?array
    {
        return self::sanitise(json_decode($json, true), 'browser');
    }

    /**
     * @return array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null
     */
    private static function sanitise(mixed $data, ?string $defaultSource): ?array
    {
        if (! is_array($data) || ! is_array($data['pages'] ?? null)) {
            return null;
        }
        $source = in_array($data['source'] ?? null, ['tesseract', 'layer', 'browser'], true) ? $data['source'] : ($defaultSource ?? 'tesseract');
        $pages = [];
        $count = 0;
        foreach (array_slice($data['pages'], 0, self::MAX_PAGES) as $page) {
            if (! is_array($page) || ! is_array($page['words'] ?? null)) {
                continue;
            }
            $n = (int) ($page['n'] ?? 0);
            if ($n < 1 || $n > 65535) {
                continue;
            }
            $words = [];
            foreach ($page['words'] as $w) {
                if (! is_array($w) || ! is_string($w['t'] ?? null) || trim($w['t']) === '' || $count >= self::MAX_WORDS) {
                    continue;
                }
                $words[] = self::word($w['t'], self::num($w['x'] ?? null), self::num($w['y'] ?? null), self::num($w['w'] ?? null), self::num($w['h'] ?? null), self::num($w['c'] ?? 100), (int) self::num($w['l'] ?? 0), (int) self::num($w['b'] ?? 0));
                $count++;
            }
            $pages[] = ['n' => $n, 'words' => $words];
        }

        return self::make($source, $pages);
    }

    /** @return array<string, mixed>|null */
    private static function bboxWord(DOMElement $word, float $width, float $height, int $l, int $b): ?array
    {
        $text = trim($word->textContent);
        if ($text === '') {
            return null;
        }
        $x0 = (float) $word->getAttribute('xMin');
        $y0 = (float) $word->getAttribute('yMin');
        $x1 = (float) $word->getAttribute('xMax');
        $y1 = (float) $word->getAttribute('yMax');

        return self::word($text, $x0 / $width, $y0 / $height, ($x1 - $x0) / $width, ($y1 - $y0) / $height, 100, $l, $b);
    }

    /** @return array{t: string, x: float, y: float, w: float, h: float, c: int, l: int, b: int} */
    private static function word(string $text, float $x, float $y, float $w, float $h, float $c, int $l, int $b): array
    {
        $x = self::unit($x);
        $y = self::unit($y);

        return [
            't' => mb_substr(trim($text), 0, self::MAX_WORD_CHARS),
            'x' => $x,
            'y' => $y,
            'w' => min(self::unit($w), round(1 - $x, 4)),
            'h' => min(self::unit($h), round(1 - $y, 4)),
            'c' => (int) max(0, min(100, round($c))),
            'l' => max(0, $l),
            'b' => max(0, $b),
        ];
    }

    private static function unit(float $v): float
    {
        return is_finite($v) ? round(max(0.0, min(1.0, $v)), 4) : 0.0;
    }

    private static function num(mixed $v): float
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)) ? (float) $v : 0.0;
    }
}

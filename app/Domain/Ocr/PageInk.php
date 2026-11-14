<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use Closure;
use GdImage;
use RuntimeException;

/**
 * The ink of one page image before and after Tesseract (point 184, the same steps as public/js/modules/ocr-ink.js):
 *
 * - removeLines(): long ruling lines (horizontal dark runs over 25 % of the width, vertical over 3 % of the height,
 *   both thin) are painted white – Tesseract's layout analysis misses text inside a thin box otherwise.
 * - uncovered(): ink areas (row bands split at wide gaps) that no recognised word overlaps; they get a second read
 *   with PSM 11 (sparse text) on a copy where everything else is white (keepOnly()).
 *
 * Works on the image turned gray (one byte per pixel) and a black/white mask held as one '0'/'1' string per row, so a
 * page at 300 dpi stays well inside the PHP memory limit; fits() says when an image is too big for it. Whole rows are
 * handled with string functions (preg, bitwise AND/OR on strings) instead of a PHP loop per pixel.
 */
final class PageInk
{
    /** Darker than this (0-255 luminance) counts as ink. */
    public const int DARK = 160;

    /** A horizontal line spans at least this share of the width, a vertical one this share of the height. */
    public const float H_LINE = 0.25;

    public const float V_LINE = 0.03;

    /** Above this many pixels nothing is done (memory). */
    public const int MAX_PIXELS = 40_000_000;

    public readonly int $width;

    public readonly int $height;

    /** @var array<int, string> */
    private array $rows = [];

    private readonly int $white;

    public function __construct(private readonly GdImage $image)
    {
        $this->width = imagesx($image);
        $this->height = imagesy($image);
        // Gray with one byte per pixel: what Tesseract reads anyway, and the mask comes from the raw bytes.
        if (imageistruecolor($image)) {
            imagefilter($image, IMG_FILTER_GRAYSCALE);
            imagetruecolortopalette($image, false, 256);
        }
        $white = imagecolorexact($image, 255, 255, 255);
        if ($white < 0) {
            $white = imagecolorallocate($image, 255, 255, 255);
        }
        $this->white = $white !== false ? $white : imagecolorclosest($image, 255, 255, 255);
        $this->readMask();
    }

    /** The page image from a file, or null when GD can't read it or it is too big for the memory left. */
    public static function load(string $path): ?self
    {
        $size = @getimagesize($path);
        if ($size === false || ! self::fits($size[0], $size[1])) {
            return null;
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            return null;
        }
        // Transparent PNGs (photos never are) go onto white first, so a clear background is no ink.
        if (imageistruecolor($image) && self::hasAlpha($path)) {
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $image = $flat;
        }

        return new self($image);
    }

    /** Whether an image of this size fits: GD's copy (4 bytes per pixel at most), its gray copy, the raw bytes and the two masks. */
    public static function fits(int $width, int $height): bool
    {
        $pixels = $width * $height;
        if ($pixels <= 0 || $pixels > self::MAX_PIXELS) {
            return false;
        }
        $limit = self::bytes((string) ini_get('memory_limit'));

        return $limit <= 0 || $limit - memory_get_usage() > $pixels * 10 + 16_000_000;
    }

    public function image(): GdImage
    {
        return $this->image;
    }

    /** Writes the (changed) image as PNG. */
    public function save(string $path): void
    {
        imagepng($this->image, $path, 1);
    }

    /**
     * Paints the ruling lines white (and their light anti-aliased fringe) and returns how many runs went.
     * A dark area thicker than a line (a filled bar, a shadow) stays.
     */
    public function removeLines(): int
    {
        $thick = max(3, (int) round(min($this->width, $this->height) * 0.004));
        $hMin = max(20, (int) ceil($this->width * self::H_LINE));
        $vMin = max(20, (int) ceil($this->height * self::V_LINE));
        $horizontal = [];
        foreach ($this->rows as $y => $row) {
            foreach (self::runs($row, $hMin) as [$x0, $x1]) {
                if ($this->thickness(fn (int $x): int => $this->spanV($x, $y), $x0, $x1) <= $thick) {
                    $horizontal[] = [$y, $x0, $x1];
                }
            }
        }
        $vertical = [];
        foreach ($this->tallColumns($vMin, $thick) as $x) {
            foreach (self::runs($this->column($x), $vMin) as [$y0, $y1]) {
                if ($this->thickness(fn (int $y): int => $this->spanH($x, $y), $y0, $y1) <= $thick) {
                    $vertical[] = [$x, $y0, $y1];
                }
            }
        }
        foreach ($horizontal as [$y, $x0, $x1]) {
            foreach ([$y - 1, $y + 1] as $edge) {
                $this->paintFringe($edge, $x0, $x1, true);
            }
            imageline($this->image, $x0, $y, $x1, $y, $this->white);
            $this->clear($y, $x0, $x1, true);
        }
        foreach ($vertical as [$x, $y0, $y1]) {
            foreach ([$x - 1, $x + 1] as $edge) {
                $this->paintFringe($edge, $y0, $y1, false);
            }
            imageline($this->image, $x, $y0, $x, $y1, $this->white);
            $this->clear($x, $y0, $y1, false);
        }

        return count($horizontal) + count($vertical);
    }

    /**
     * Ink areas in px [x0, y0, x1, y1] (inclusive): rows with ink grouped into bands, each band split where the gap is
     * wider than about one and a half line heights. Specks are dropped.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public function regions(): array
    {
        $regions = [];
        $start = null;
        $last = 0;
        foreach ($this->rows as $y => $row) {
            if (substr_count($row, '1') < 2) {
                continue;
            }
            if ($start !== null && $y - $last > 2) {
                array_push($regions, ...$this->band($start, $last));
                $start = null;
            }
            $start ??= $y;
            $last = $y;
        }
        if ($start !== null) {
            array_push($regions, ...$this->band($start, (int) $last));
        }

        return $regions;
    }

    /**
     * The ink areas no word of the page overlaps (words relative 0..1 as in OcrWords), with a small margin.
     *
     * @param  array{words: list<array<string, mixed>>}|null  $page
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public function uncovered(?array $page): array
    {
        $boxes = [];
        foreach ($page['words'] ?? [] as $w) {
            $boxes[] = [
                (float) $w['x'] * $this->width, (float) $w['y'] * $this->height,
                ((float) $w['x'] + (float) $w['w']) * $this->width, ((float) $w['y'] + (float) $w['h']) * $this->height,
            ];
        }
        $minHeight = max(8, (int) round($this->height * 0.006));
        $out = [];
        foreach ($this->regions() as $r) {
            if ($minHeight > $r[3] - $r[1] + 1 || $minHeight > $r[2] - $r[0] + 1) {
                continue;
            }
            foreach ($boxes as $b) {
                if ($b[0] <= $r[2] + 2 && $b[2] >= $r[0] - 2 && $b[1] <= $r[3] + 2 && $b[3] >= $r[1] - 2) {
                    continue 2;
                }
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Paints everything outside the given areas (plus a margin) white, for the second read.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $regions
     */
    public function keepOnly(array $regions, int $margin = 6): void
    {
        $keep = array_map(fn (array $r): array => [
            max(0, $r[0] - $margin), max(0, $r[1] - $margin), min($this->width - 1, $r[2] + $margin), min($this->height - 1, $r[3] + $margin),
        ], $regions);
        // Horizontal slabs between the areas' top and bottom edges; within each, the gaps between kept spans go white.
        $edges = [0, $this->height];
        foreach ($keep as $r) {
            $edges[] = $r[1];
            $edges[] = $r[3] + 1;
        }
        $edges = array_values(array_unique($edges));
        sort($edges);
        for ($i = 0; $i < count($edges) - 1; $i++) {
            [$y0, $y1] = [$edges[$i], $edges[$i + 1] - 1];
            if ($y1 < $y0) {
                continue;
            }
            $spans = array_values(array_filter($keep, static fn (array $r): bool => $r[1] <= $y0 && $r[3] >= $y1));
            usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $x = 0;
            foreach ($spans as $s) {
                if ($s[0] > $x) {
                    imagefilledrectangle($this->image, $x, $y0, $s[0] - 1, $y1, $this->white);
                }
                $x = max($x, $s[2] + 1);
            }
            if ($x < $this->width) {
                imagefilledrectangle($this->image, $x, $y0, $this->width - 1, $y1, $this->white);
            }
        }
    }

    /** Whether the pixel is ink in the current mask (tests). */
    public function ink(int $x, int $y): bool
    {
        return ($this->rows[$y][$x] ?? '0') === '1';
    }

    /** Ink pixels inside a box (tests). */
    public function inkIn(int $x0, int $y0, int $x1, int $y1): int
    {
        $count = 0;
        for ($y = max(0, $y0); $y <= min($this->height - 1, $y1); $y++) {
            $count += substr_count(substr($this->rows[$y], max(0, $x0), $x1 - $x0 + 1), '1');
        }

        return $count;
    }

    /**
     * The mask without a PHP loop per pixel: the palette image's raw bytes (GD's own format, one byte per pixel) are
     * mapped to '0'/'1' with strtr.
     */
    private function readMask(): void
    {
        $from = '';
        $to = '';
        $total = imagecolorstotal($this->image);
        for ($i = 0; $i < 256; $i++) {
            $dark = false;
            if ($i < $total) {
                $c = imagecolorsforindex($this->image, $i);
                $dark = ($c['red'] * 299 + $c['green'] * 587 + $c['blue'] * 114) / 1000 < self::DARK && $c['alpha'] < 64;
            }
            $from .= chr($i);
            $to .= $dark ? '1' : '0';
        }
        ob_start();
        imagegd($this->image);
        // A palette .gd is a header (its length differs between libgd builds: bundled vs system) followed by one byte
        // per pixel – so the pixels are the last width × height bytes.
        $raw = (string) ob_get_clean();
        $size = $this->width * $this->height;
        if (strlen($raw) < $size + 11) {
            throw new RuntimeException('gd: raw size');
        }
        $pixels = substr($raw, -$size);
        $this->rows = str_split(strtr($pixels, $from, $to), $this->width);
    }

    /**
     * Columns that hold a vertical ink run of at least $min pixels, without reading every column: the AND of $min
     * rows in a row (built by doubling) has '1' exactly there. Groups of such columns wider than a line are no line.
     *
     * @return list<int>
     */
    private function tallColumns(int $min, int $thick): array
    {
        $acc = $this->rows;
        $p = 1;
        while ($p * 2 <= $min) {
            $next = [];
            for ($y = 0, $n = count($acc) - $p; $y < $n; $y++) {
                $next[] = $acc[$y] & $acc[$y + $p];
            }
            $acc = $next;
            $p *= 2;
        }
        $any = str_repeat('0', $this->width);
        for ($y = 0, $n = count($acc) - ($min - $p); $y < $n; $y++) {
            $any |= $acc[$y] & $acc[$y + $min - $p];
        }
        if (preg_match_all('/1+/', $any, $m, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }
        $columns = [];
        foreach ($m[0] as [$group, $offset]) {
            if (strlen($group) <= $thick) {
                array_push($columns, ...range((int) $offset, (int) $offset + strlen($group) - 1));
            }
        }

        return $columns;
    }

    private function column(int $x): string
    {
        $col = '';
        foreach ($this->rows as $row) {
            $col .= $row[$x];
        }

        return $col;
    }

    /**
     * Runs of ink at least $min long in one row or column, as [start, end] (inclusive).
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function runs(string $line, int $min): array
    {
        if (substr_count($line, '1') < $min || preg_match_all('/1{'.$min.',}/', $line, $m, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        return array_map(static fn (array $hit): array => [(int) $hit[1], (int) $hit[1] + strlen($hit[0]) - 1], $m[0]);
    }

    /**
     * The median thickness of a run across it, sampled at a quarter, half and three quarters of its length.
     *
     * @param  Closure(int): int  $span  the ink across the run at one point along it
     */
    private function thickness(Closure $span, int $from, int $to): int
    {
        $samples = [$span((int) round($from + ($to - $from) * 0.25)), $span((int) round(($from + $to) / 2)), $span((int) round($from + ($to - $from) * 0.75))];
        sort($samples);

        return $samples[1];
    }

    /** Vertical extent of the ink through (x, y), capped at 100. */
    private function spanV(int $x, int $y): int
    {
        $n = 1;
        for ($i = $y - 1; $i >= 0 && $this->rows[$i][$x] === '1' && $n < 100; $i--) {
            $n++;
        }
        for ($i = $y + 1; $i < $this->height && $this->rows[$i][$x] === '1' && $n < 100; $i++) {
            $n++;
        }

        return $n;
    }

    /** Horizontal extent of the ink through (x, y), capped at 100. */
    private function spanH(int $x, int $y): int
    {
        $row = $this->rows[$y];
        $n = 1;
        for ($i = $x - 1; $i >= 0 && $row[$i] === '1' && $n < 100; $i--) {
            $n++;
        }
        for ($i = $x + 1; $i < $this->width && $row[$i] === '1' && $n < 100; $i++) {
            $n++;
        }

        return $n;
    }

    /** The light pixels next to a removed line go white too (never ink: text touching the line stays). */
    private function paintFringe(int $at, int $from, int $to, bool $row): void
    {
        if ($at < 0 || $at >= ($row ? $this->height : $this->width)) {
            return;
        }
        $line = $row ? $this->rows[$at] : $this->column($at);
        if (preg_match_all('/0+/', substr($line, $from, $to - $from + 1), $m, PREG_OFFSET_CAPTURE) < 1) {
            return;
        }
        foreach ($m[0] as [$gap, $offset]) {
            $a = $from + (int) $offset;
            $b = $a + strlen($gap) - 1;
            $row ? imageline($this->image, $a, $at, $b, $at, $this->white) : imageline($this->image, $at, $a, $at, $b, $this->white);
        }
    }

    /** Marks a removed run as no ink in the mask. */
    private function clear(int $at, int $from, int $to, bool $row): void
    {
        if ($row) {
            $this->rows[$at] = substr_replace($this->rows[$at], str_repeat('0', $to - $from + 1), $from, $to - $from + 1);

            return;
        }
        for ($y = $from; $y <= $to; $y++) {
            $this->rows[$y][$at] = '0';
        }
    }

    /**
     * One band of rows split into areas at gaps wider than 1.5 band heights (at least 1 % of the width).
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private function band(int $y0, int $y1): array
    {
        $projection = $this->rows[$y0];
        for ($y = $y0 + 1; $y <= $y1; $y++) {
            $projection |= $this->rows[$y];
        }
        if (preg_match_all('/1+/', $projection, $m, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }
        $gap = max((int) round(($y1 - $y0 + 1) * 1.5), (int) round($this->width * 0.01));
        $out = [];
        $current = null;
        foreach ($m[0] as [$ink, $offset]) {
            $a = (int) $offset;
            $b = $a + strlen($ink) - 1;
            if ($current !== null && $a - $current[2] - 1 <= $gap) {
                $current[2] = $b;

                continue;
            }
            if ($current !== null) {
                $out[] = $current;
            }
            $current = [$a, $y0, $b, $y1];
        }
        if ($current !== null) {
            $out[] = $current;
        }

        return $out;
    }

    private static function hasAlpha(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 26);

        return str_starts_with($head, "\x89PNG") && strlen($head) === 26 && in_array(ord($head[25]), [4, 6], true);
    }

    private static function bytes(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '' || $limit === '-1') {
            return -1;
        }
        $n = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use App\Domain\Documents\PageTransform;

/**
 * Point 238 (A415): OCR word boxes and corrections live in shown page coordinates (after crop and turn, see
 * PageTransform), so the word overlay lines up with the shown page and the invoice readers see upright lines.
 * When a page gets another rotation/crop, its boxes move from the old shown page to the new one; words whose
 * centre falls off the new page (cropped away) are dropped. A text layer read from the original page comes in with
 * $from = none.
 */
final class ShownWords
{
    /**
     * @param  array{n: int, words: list<array<string, mixed>>}  $page
     * @return array{n: int, words: list<array<string, mixed>>}
     */
    public static function page(array $page, PageTransform $from, PageTransform $to): array
    {
        $words = [];
        foreach ($page['words'] as $word) {
            $box = self::move($word, $from, $to);
            if ($box !== null) {
                $words[] = [...$word, ...$box];
            }
        }

        return [...$page, 'words' => $words];
    }

    /**
     * Page $n of a whole OcrWords structure moved; the other pages stay as they are.
     *
     * @param  array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null  $words
     * @return array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null
     */
    public static function words(?array $words, int $n, PageTransform $from, PageTransform $to): ?array
    {
        if ($words === null) {
            return null;
        }
        foreach ($words['pages'] as $i => $page) {
            if ($page['n'] === $n) {
                $words['pages'][$i] = self::page($page, $from, $to);
            }
        }

        return $words;
    }

    /**
     * The corrections of page $n moved along (dropped when cropped away).
     *
     * @param  list<array<string, mixed>>  $fixes
     * @return list<array<string, mixed>>
     */
    public static function fixes(array $fixes, int $n, PageTransform $from, PageTransform $to): array
    {
        $out = [];
        foreach ($fixes as $fix) {
            if ((int) ($fix['p'] ?? 0) !== $n) {
                $out[] = $fix;

                continue;
            }
            $box = self::move($fix, $from, $to);
            if ($box !== null) {
                $out[] = [...$fix, ...$box];
            }
        }

        return $out;
    }

    /**
     * One box from the old shown page to the new one, null when its centre is off the new page.
     *
     * @param  array<string, mixed>  $item  with x, y, w, h
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    public static function move(array $item, PageTransform $from, PageTransform $to): ?array
    {
        $box = ['x' => self::num($item['x'] ?? 0), 'y' => self::num($item['y'] ?? 0), 'w' => self::num($item['w'] ?? 0), 'h' => self::num($item['h'] ?? 0)];
        $box = $to->boxToShown($from->boxToOriginal($box));
        $cx = $box['x'] + $box['w'] / 2;
        $cy = $box['y'] + $box['h'] / 2;
        if ($cx < 0 || $cx > 1 || $cy < 0 || $cy > 1) {
            return null;
        }
        $x = max(0.0, $box['x']);
        $y = max(0.0, $box['y']);

        return [
            'x' => round($x, 5), 'y' => round($y, 5),
            'w' => round(min(1, $box['x'] + $box['w']) - $x, 5), 'h' => round(min(1, $box['y'] + $box['h']) - $y, 5),
        ];
    }

    private static function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}

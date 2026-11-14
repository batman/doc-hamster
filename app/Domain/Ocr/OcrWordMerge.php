<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

/**
 * Point 184 (b): the words of the second read (PSM 11 on the ink areas the first read left without words) go into the
 * page by position – a word that overlaps one already there is a duplicate and dropped, letterless ones too, and lines
 * that are unsure as a whole. The new words form lines of their own, placed in reading order before the first line that
 * lies below them; line and block indexes are numbered again. Same rules as merge() in public/js/modules/ocr-ink.js.
 */
final class OcrWordMerge
{
    /** New lines whose mean confidence is below this are left out (PSM 11 reads noise as letters). */
    public const int MIN_CONFIDENCE = 50;

    /**
     * @param  array{n: int, words: list<array<string, mixed>>}|null  $page  the first read
     * @param  array{n: int, words: list<array<string, mixed>>}|null  $extra  the second read
     * @return array{0: array{n: int, words: list<array<string, mixed>>}|null, 1: int} the page, the number of words added
     */
    public static function merge(?array $page, ?array $extra): array
    {
        $new = [];
        foreach ($extra['words'] ?? [] as $w) {
            if (preg_match('/[\p{L}\p{N}]/u', (string) $w['t']) !== 1) {
                continue;
            }
            foreach ([...($page['words'] ?? []), ...$new] as $old) {
                if (self::overlap($w, $old) > 0.3) {
                    continue 2;
                }
            }
            $new[] = $w;
        }
        // An unsure word inside a sure line stays (an IBAN's "DE00" often reads at 40); a line unsure as a whole is noise.
        $groups = array_values(array_filter(self::group($new), static fn (array $line): bool => array_sum(array_map(static fn (array $w): int => (int) ($w['c'] ?? 0), $line)) / count($line) >= self::MIN_CONFIDENCE));
        if ($groups === []) {
            return [$page, 0];
        }
        $n = $page['n'] ?? $extra['n'] ?? 1;

        // The first read's lines in their order, then each new line before the first old line that starts below it.
        $lines = [];
        foreach ($page['words'] ?? [] as $w) {
            $lines[(int) $w['l']][] = $w;
        }
        $lines = array_values($lines);
        foreach ($groups as $line) {
            $mid = self::middle($line);
            $at = count($lines);
            foreach ($lines as $i => $old) {
                if (self::middle($old) > $mid) {
                    $at = $i;
                    break;
                }
            }
            array_splice($lines, $at, 0, [array_map(static fn (array $w): array => [...$w, 'b' => -1], $line)]);
        }

        $words = [];
        $blocks = [];
        $maxBlock = max([-1, ...array_map(static fn (array $w): int => (int) $w['b'], $page['words'] ?? [])]);
        foreach ($lines as $l => $line) {
            foreach ($line as $w) {
                $b = (int) $w['b'];
                if ($b < 0) {
                    $b = $blocks[$l] ??= ++$maxBlock;
                }
                $words[] = [...$w, 'l' => $l, 'b' => $b];
            }
        }

        return [['n' => $n, 'words' => $words], array_sum(array_map('count', $groups))];
    }

    /**
     * The new words as lines: words whose vertical middles lie within half a word height of each other, sorted by x.
     *
     * @param  list<array<string, mixed>>  $words
     * @return list<list<array<string, mixed>>>
     */
    private static function group(array $words): array
    {
        usort($words, static fn (array $a, array $b): int => self::middle([$a]) <=> self::middle([$b]));
        $lines = [];
        foreach ($words as $w) {
            $last = array_key_last($lines);
            if ($last !== null && abs(self::middle($lines[$last]) - self::middle([$w])) < max(self::height($lines[$last]), (float) $w['h']) / 2) {
                $lines[$last][] = $w;

                continue;
            }
            $lines[] = [$w];
        }
        foreach ($lines as &$line) {
            usort($line, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
        }

        return $lines;
    }

    /**
     * Intersection over the smaller box.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function overlap(array $a, array $b): float
    {
        $w = min((float) $a['x'] + (float) $a['w'], (float) $b['x'] + (float) $b['w']) - max((float) $a['x'], (float) $b['x']);
        $h = min((float) $a['y'] + (float) $a['h'], (float) $b['y'] + (float) $b['h']) - max((float) $a['y'], (float) $b['y']);
        $small = min((float) $a['w'] * (float) $a['h'], (float) $b['w'] * (float) $b['h']);

        return $w <= 0 || $h <= 0 || $small <= 0 ? 0.0 : $w * $h / $small;
    }

    /** @param list<array<string, mixed>> $line */
    private static function middle(array $line): float
    {
        return array_sum(array_map(static fn (array $w): float => (float) $w['y'] + (float) $w['h'] / 2, $line)) / max(1, count($line));
    }

    /** @param list<array<string, mixed>> $line */
    private static function height(array $line): float
    {
        return max([0.0, ...array_map(static fn (array $w): float => (float) $w['h'], $line)]);
    }
}

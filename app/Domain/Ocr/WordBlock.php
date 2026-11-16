<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use App\Domain\Ocr\Invoice\AmountParser;
use App\Domain\Ocr\Invoice\IbanReader;
use App\Domain\Properties\PropertyValues;

/**
 * Several OCR words taken as one value (point 242, A425-A430): the text of a block cleaned for the property type it is
 * marked as (IBAN without spaces and checked, "1 234,56" as an amount), a single word grown to the whole IBAN or amount
 * on its line (A429), and the combined box that is stored as the value's place (A430).
 */
final class WordBlock
{
    /** A429: at most this many words are added behind the marked ones. */
    private const int MAX_GROW = 10;

    private const string SPACED_AMOUNT = '/^[-−]?\d{1,3}(?: \d{3})+[.,]\d{2}(?: ?(?:€|EUR))?$/iu';

    /**
     * A428: the block's text as the property type wants it; unknown types keep it with single spaces.
     */
    public static function clean(string $type, string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        switch ($type) {
            case 'iban':
                $compact = (string) preg_replace('/^IBAN:?/', '', strtoupper((string) preg_replace('/[\s\-.]+/u', '', $text)));
                if (PropertyValues::parseIban($compact) !== null) {
                    return $compact;
                }

                return preg_match('/^[A-Z]{2}[0-9A-Z]{2}[A-Z0-9|]{8,40}$/', $compact) === 1 ? (IbanReader::repair($compact) ?? $compact) : $compact;
            case 'amount':
                $parsed = $text === '' ? null : PropertyValues::parseAmount($text);
                if ($parsed !== null) {
                    return PropertyValues::formatAmount($parsed[0], $parsed[1]);
                }
                $found = AmountParser::scan($text);

                return $found === [] ? $text : PropertyValues::formatAmount($found[count($found) - 1]['minor'], $found[count($found) - 1]['currency'] ?? 'EUR');
            case 'date':
                return (string) preg_replace('/\s*([.\/-])\s*/u', '$1', $text);
            default:
                return $text;
        }
    }

    /**
     * A429: the marked words of one page grown by the following words of the first word's line while they form a
     * valid IBAN or a spaced amount ("1 234,56 €"); null when nothing is to be added (or the type does not grow).
     *
     * @param  list<array<string, mixed>>  $words  the page's words (OcrWords)
     * @param  list<int>  $picked  indices of the marked words
     * @return array{words: list<int>, text: string, box: array{x: float, y: float, w: float, h: float}}|null
     */
    public static function extend(string $type, array $words, array $picked): ?array
    {
        if (! in_array($type, ['iban', 'amount'], true) || $picked === [] || ! isset($words[$picked[0]])) {
            return null;
        }
        $first = $picked[0];
        $line = [];
        foreach ($words as $i => $word) {
            if (($word['l'] ?? null) === ($words[$first]['l'] ?? null)) {
                $line[] = $i;
            }
        }
        usort($line, static fn (int $a, int $b): int => (float) ($words[$a]['x'] ?? 0) <=> (float) ($words[$b]['x'] ?? 0));
        $start = (int) array_search($first, $line, true);
        $have = $start + count(array_intersect($line, $picked)) - 1;
        $best = null;
        for ($end = $start; $end < count($line) && $end <= $have + self::MAX_GROW; $end++) {
            $texts = array_map(static fn (int $i): string => (string) ($words[$i]['t'] ?? ''), array_slice($line, $start, $end - $start + 1));
            if (self::valid($type, $texts)) {
                $best = $end;
            }
        }
        if ($best === null || $best <= $have) {
            return null;
        }
        $taken = array_slice($line, $start, $best - $start + 1);
        $texts = array_map(static fn (int $i): string => (string) ($words[$i]['t'] ?? ''), $taken);

        return [
            'words' => $taken,
            'text' => self::clean($type, implode(' ', $texts)),
            'box' => self::box(array_map(static fn (int $i): array => $words[$i], $taken)) ?? ['x' => 0.0, 'y' => 0.0, 'w' => 0.0, 'h' => 0.0],
        ];
    }

    /**
     * A430: the box around all words (page-relative, rounded like the stored places); null for no words.
     *
     * @param  list<array<string, mixed>>  $words
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    public static function box(array $words): ?array
    {
        if ($words === []) {
            return null;
        }
        $x = $y = INF;
        $r = $b = -INF;
        foreach ($words as $word) {
            $wx = (float) ($word['x'] ?? 0);
            $wy = (float) ($word['y'] ?? 0);
            $x = min($x, $wx);
            $y = min($y, $wy);
            $r = max($r, $wx + (float) ($word['w'] ?? 0));
            $b = max($b, $wy + (float) ($word['h'] ?? 0));
        }

        return ['x' => round($x, 4), 'y' => round($y, 4), 'w' => round($r - $x, 4), 'h' => round($b - $y, 4)];
    }

    /** @param list<string> $texts */
    private static function valid(string $type, array $texts): bool
    {
        if ($type === 'iban') {
            return PropertyValues::parseIban(implode('', $texts)) !== null;
        }

        return count($texts) > 1 && preg_match(self::SPACED_AMOUNT, implode(' ', $texts)) === 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

/**
 * Finds the amounts on one line (points 235, A398): "1.234,56 €", "1234,56 EUR", "€ 1.234,56", "1.234,- €",
 * "€1,234.56", "$1,234.56", "CHF 1'234.56", "1 234,56 €", "1234.56"; the currency as symbol or code in front or behind
 * (any case, € read as E); negatives "-12,50", "12,50-", "(12.50)"; OCR slips (O for 0, a space in the number). Two
 * digits behind the last separator make it the decimal one; three are thousands – unless the document writes its
 * decimals with that separator (then it is a unit price like 1,789 €/l and no amount). A whole number counts only with
 * a currency next to it, a percentage never.
 *
 * @phpstan-import-type Line from InvoiceLines
 *
 * @phpstan-type Amount array{minor: int, raw: string, s: int, e: int, currency: string|null, fixed: bool}
 */
final class AmountParser
{
    private const NUMBER = '/(?<![\d.,\'’])(?:(?<![\p{L}\d])([-−])\s?)?(\d{1,3}(?:\.\d{3})+,(?:\d{1,2}|-{1,2})|\d{1,3}(?:,\d{3})+\.\d{1,2}|\d{1,3}(?:[\'’]\d{3})+(?:[.,]\d{1,2})?|\d+[.,](?:\d{1,2}|-{1,2})|\d{1,3}(?:[.,]\d{3})+|\d+)(-(?!\d))?(?!\d|[.,\'’]\d)(?!\s*%)/u';

    private const SPACED = '/(?<![\d.,])\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+[.,]\d{2}(?!\d)/u';

    private const SYMBOL = '(?:€|\$|£|(?<!\p{L})(?:eur|euro|usd|chf|gbp)(?!\p{L}))';

    /**
     * The decimal separator most amounts with two decimals use, null when none has.
     *
     * @param  list<Line>  $lines
     */
    public static function decimalStyle(array $lines): ?string
    {
        $count = [',' => 0, '.' => 0];
        foreach ($lines as $line) {
            if (preg_match_all('/\d([.,])\d{2}(?![.,]?\d)/u', $line['text'], $m) !== false) {
                foreach ($m[1] as $sep) {
                    $count[$sep]++;
                }
            }
        }
        if ($count[','] === $count['.']) {
            return null;
        }

        return $count[','] > $count['.'] ? ',' : '.';
    }

    /**
     * @return list<Amount>
     */
    public static function scan(string $text, ?string $decimal = null): array
    {
        $t = InvoiceLines::digits($text);
        // "12 ,50 €" / "12, 50 €": the stray space moves behind the number (same length, offsets stay).
        $t = (string) preg_replace('/(\d)([.,]) (\d{2})(?=\s?(?:€|\$|£|eur|usd|chf|gbp)|\s*$)/iu', '$1$2$3 ', $t);
        $t = (string) preg_replace('/(\d) ([.,]\d{2})(?=\s?(?:€|\$|£|eur|usd|chf|gbp)|\s*$)/iu', '$1$2 ', $t);
        $out = [];
        // Thousands with spaces only next to a currency ("1 234,56 €"); "1 500.00 500.00" are quantity and price.
        if (preg_match_all(self::SPACED, $t, $spaced, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($spaced[0] as [$raw, $s]) {
                $e = $s + strlen($raw);
                $currency = self::currency($t, $s, $e);
                $value = self::value((string) preg_replace('/[ \x{00A0}\x{202F}]/u', '', $raw), $decimal);
                if ($currency !== null && $value !== null) {
                    $out[] = self::amount($text, $t, $s, $e, $currency, false, $value[0]);
                    $t = substr_replace($t, str_repeat('#', $e - $s), $s, $e - $s);
                }
            }
        }
        if (preg_match_all(self::NUMBER, $t, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches as $m) {
                $s = $m[0][1];
                $e = $s + strlen($m[0][0]);
                $number = $m[2][0];
                $currency = self::currency($t, $s, $e);
                $before = substr($t, max(0, $s - 1), $s > 0 ? 1 : 0);
                if ($before !== '' && preg_match('/\p{L}/u', $before) === 1 && preg_match('/(?:eur|usd|chf|gbp)$/iu', substr($t, max(0, $s - 3), min(3, $s))) !== 1) {
                    continue;
                }
                $value = self::value($number, $decimal);
                if ($value === null || ($value[1] && $currency === null)) {
                    continue;
                }
                $negative = $m[1][0] !== '' || ($m[3][0] ?? '') !== ''
                    || (preg_match('/\(\s*(?:€|\$|£)?\s*$/u', substr($t, max(0, $s - 4), min(4, $s))) === 1 && preg_match('/^\s*(?:€|\$|£)?\s*\)/u', substr($t, $e, 4)) === 1);
                $out[] = self::amount($text, $t, $s, $e, $currency, $negative, $value[0]);
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['s'] <=> $b['s']);

        return $out;
    }

    /**
     * Minor units of a number and whether it needs a currency next to it to count (a whole number, one decimal).
     *
     * @return array{int, bool}|null
     */
    private static function value(string $number, ?string $decimal): ?array
    {
        $number = str_replace(['\'', '’'], '', $number);
        if (preg_match('/^(.*)[.,]-{1,2}$/', $number, $dash) === 1) {
            return [(int) str_replace(['.', ','], '', $dash[1]) * 100, false];
        }
        if (preg_match('/^(.*)([.,])(\d+)$/', $number, $m) !== 1) {
            return [(int) $number * 100, true];
        }
        [, $int, $sep, $frac] = $m;
        if (strlen($frac) === 3) {
            if ($decimal === $sep && ! str_contains($int, '.') && ! str_contains($int, ',')) {
                return null;
            }

            return [(int) str_replace(['.', ','], '', $int.$frac) * 100, true];
        }

        return [(int) str_replace(['.', ','], '', $int) * 100 + (int) str_pad($frac, 2, '0'), strlen($frac) === 1];
    }

    /** The currency right in front of or behind an amount – a symbol behind another number belongs to that one. */
    private static function currency(string $t, int $s, int $e): ?string
    {
        $after = substr($t, $e, 8);
        if (preg_match('/^\s{0,2}('.self::SYMBOL.')/iu', $after, $m) === 1) {
            return self::code($m[1]);
        }
        if (preg_match('/^\s{0,2}[EC](?![\p{L}\d\-])/u', $after) === 1) {
            return 'EUR';
        }
        $before = substr($t, max(0, $s - 8), $s - max(0, $s - 8));
        if (preg_match('/('.self::SYMBOL.')\s?[-−(]?\s?$/iu', $before, $m, PREG_OFFSET_CAPTURE) === 1) {
            $rest = rtrim(substr($before, 0, $m[1][1]));
            if ($rest === '' || preg_match('/[\d\-]$/u', $rest) !== 1) {
                return self::code($m[1][0]);
            }
        }

        return null;
    }

    private static function code(string $symbol): string
    {
        return match (mb_strtolower($symbol)) {
            '$', 'usd' => 'USD',
            '£', 'gbp' => 'GBP',
            'chf' => 'CHF',
            default => 'EUR',
        };
    }

    /**
     * @return Amount
     */
    private static function amount(string $text, string $t, int $s, int $e, ?string $currency, bool $negative, int $minor): array
    {
        return ['minor' => $negative ? -$minor : $minor, 'raw' => substr($text, $s, $e - $s), 's' => $s, 'e' => $e, 'currency' => $currency,
            'fixed' => substr($text, $s, $e - $s) !== substr($t, $s, $e - $s)];
    }
}

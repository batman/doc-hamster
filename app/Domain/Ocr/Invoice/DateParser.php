<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use Carbon\CarbonImmutable;

/**
 * Finds the dates on one line (points 234, A398): 06.02.2026, 6.2.26, "06. 02. 2026", 06/02/2026 and 02/06/2026
 * (both readings kept), 06-02-2026, 2026-02-06 (also with / or . and an ISO time), month names day first
 * ("6. Februar 2026", "6 Feb 2026", "06-Feb-2026", "6FEB26", "6 de febrero de 2026", "1er février 2026") and month
 * first ("Feb 6, 2026", "February 6th, 2026") in German, English, French, Spanish, Italian, Dutch and Portuguese. OCR
 * slips (O for 0, l for 1, a comma for a dot, stray spaces) are forgiven. Month and year alone, and digit runs like
 * 20260206 are no date; a date that is part of a range ("01.01.–31.01.2026") is marked.
 *
 * @phpstan-type Found array{d: string|null, m: string|null, s: int, e: int, slash: bool, range: bool, time: bool}
 */
final class DateParser
{
    private const MONTHS = [
        1 => ['januar', 'jänner', 'jan', 'jän', 'january', 'janvier', 'janv', 'enero', 'ene', 'gennaio', 'gen', 'januari', 'janeiro'],
        2 => ['februar', 'feb', 'february', 'février', 'fevrier', 'févr', 'fevr', 'febrero', 'febbraio', 'februari', 'fevereiro', 'fev'],
        3 => ['märz', 'maerz', 'marz', 'mär', 'mrz', 'march', 'mar', 'mars', 'marzo', 'maart', 'mrt', 'março', 'marco'],
        4 => ['april', 'apr', 'avril', 'avr', 'abril', 'abr', 'aprile'],
        5 => ['mai', 'may', 'mayo', 'maggio', 'mag', 'mei', 'maio'],
        6 => ['juni', 'jun', 'june', 'juin', 'junio', 'giugno', 'giu', 'junho'],
        7 => ['juli', 'jul', 'july', 'juillet', 'juil', 'julio', 'luglio', 'lug', 'julho'],
        8 => ['august', 'aug', 'août', 'aout', 'agosto', 'ago', 'augustus'],
        9 => ['september', 'sep', 'sept', 'septembre', 'septiembre', 'setiembre', 'settembre', 'set', 'setembro'],
        10 => ['oktober', 'okt', 'october', 'oct', 'octobre', 'octubre', 'ottobre', 'ott', 'outubro', 'out'],
        11 => ['november', 'nov', 'novembre', 'noviembre', 'novembro'],
        12 => ['dezember', 'dez', 'december', 'dec', 'décembre', 'decembre', 'déc', 'diciembre', 'dic', 'dicembre', 'dezembro'],
    ];

    /** @var array<string, int>|null */
    private static ?array $byName = null;

    /** @var array<string, string>|null */
    private static ?array $patterns = null;

    /**
     * The dates on a line with their byte offsets: `d` the day-first reading, `m` the month-first one (equal unless
     * the date is written with / or -, null when that reading is no real date).
     *
     * @return list<Found>
     */
    public static function scan(string $text): array
    {
        $t = InvoiceLines::digits($text);
        $found = [];
        foreach (self::patterns() as $kind => $pattern) {
            if (preg_match_all($pattern, $t, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($matches as $m) {
                $s = $m[0][1];
                $e = $s + strlen($m[0][0]);
                foreach ($found as $f) {
                    if ($s < $f['e'] && $e > $f['s']) {
                        continue 2;
                    }
                }
                [$d, $mo] = self::readings($kind, array_map(static fn (array $g): string => $g[0], $m));
                if ($d === null && $mo === null) {
                    continue;
                }
                $found[] = ['d' => $d, 'm' => $mo, 's' => $s, 'e' => $e, 'slash' => $kind === 'slash',
                    'range' => self::inRange($t, $s, $e), 'time' => self::withTime($t, $s, $e)];
            }
        }
        usort($found, static fn (array $a, array $b): int => $a['s'] <=> $b['s']);

        return $found;
    }

    /**
     * @param  array<int|string, string>  $g
     * @return array{string|null, string|null}
     */
    private static function readings(string $kind, array $g): array
    {
        $month = static fn (string $name): int => self::names()[rtrim(mb_strtolower($name), '.')] ?? 0;

        return match ($kind) {
            'iso' => [$r = self::ymd($g[1], (int) $g[3], $g[4]), $r],
            'dot' => [$r = self::ymd($g[3], (int) $g[2], $g[1]), $r],
            'dayname' => [$r = self::ymd($g[3], $month($g[2]), $g[1]), $r],
            'monthday' => [$r = self::ymd($g[3], $month($g[1]), $g[2]), $r],
            default => [self::ymd($g[4], (int) $g[3], $g[1]), self::ymd($g[4], (int) $g[1], $g[3])],
        };
    }

    /** A real date as Y-m-d; a two-digit year lies in this century unless that is more than 20 years ahead. */
    private static function ymd(string $year, int $month, string $day): ?string
    {
        $y = (int) $year;
        if (strlen($year) === 2) {
            $y += 2000;
            if ($y > (int) CarbonImmutable::now()->format('Y') + 20) {
                $y -= 100;
            }
        } elseif (strlen($year) !== 4) {
            return null;
        }

        return $y >= 1900 && $y <= 2099 && checkdate($month, (int) $day, $y) ? sprintf('%04d-%02d-%02d', $y, $month, (int) $day) : null;
    }

    /** "01.01.–31.01.2026", "01.01.2026 - 31.01.2026", "vom … bis …": a date at either end of a range. */
    private static function inRange(string $t, int $s, int $e): bool
    {
        $before = substr($t, max(0, $s - 12), $s - max(0, $s - 12));
        $after = substr($t, $e, 14);

        return preg_match('/(?:\d{1,2}[.\/]\d{0,4}[.\/]?|(?<![\d.])\d{1,2}\.?)\s*(?:–|—|-|bis|to|till|until|au|al)\s*$/iu', $before) === 1
            || (preg_match('/^\s*(?:–|—|-|bis|to|till|until|au|al)\s*\d{1,2}[.\/\-\s]/iu', $after) === 1 && preg_match('/^\s*[–—-]?\s*\d{1,2}:\d{2}/u', $after) !== 1);
    }

    /** A time right behind or in front of the date (a Kassenbon, an ISO time stamp). */
    private static function withTime(string $t, int $s, int $e): bool
    {
        $before = substr($t, max(0, $s - 12), $s - max(0, $s - 12));

        return preg_match('/^(?:\s*[,\-–]|\s*um|\s*at)?\s*T?\s*[0-2]?\d:[0-5]\d/iu', substr($t, $e, 16)) === 1
            || preg_match('/[0-2]?\d:[0-5]\d(?::[0-5]\d)?\s*(?:uhr)?\s*[,\-]?\s*$/iu', $before) === 1;
    }

    /** @return array<string, int> */
    private static function names(): array
    {
        if (self::$byName === null) {
            self::$byName = [];
            foreach (self::MONTHS as $n => $names) {
                foreach ($names as $name) {
                    self::$byName[$name] = $n;
                }
            }
        }

        return self::$byName;
    }

    /** @return array<string, string> by priority: an earlier pattern wins where two overlap */
    private static function patterns(): array
    {
        if (self::$patterns === null) {
            $names = array_keys(self::names());
            usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $month = '('.implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $names)).')(?!\p{L})\.?';
            self::$patterns = [
                'iso' => '/(?<![\d.,\/\-])((?:19|20)\d{2})\s?([.\/\-])\s?(\d{1,2})\s?\2\s?(\d{1,2})(?![\d]|[.,]\d)/u',
                'dayname' => '/(?<![\d.,\/])(\d{1,2})(?:st|nd|rd|th|er|e|º|°)?\s?[.\-\/]?\s*(?:of\s+|de\s+)?'.$month.'\s*(?:de\s+)?[\-\/,.]?\s*(\d{4}|\d{2})(?![\d]|[.,]\d)/iu',
                'monthday' => '/(?<!\p{L})'.$month.'\s*(\d{1,2})(?:st|nd|rd|th)?\s*,?\s*(\d{4})(?!\d)/iu',
                'dot' => '/(?<![\d.,])(\d{1,2})\s?[.,]\s?(\d{1,2})\s?[.,]\s?(\d{4}|\d{2})(?![\d]|[.,]\d)/u',
                'slash' => '/(?<![\d.,\/\-])(\d{1,2})\s?([\/\-])\s?(\d{1,2})\s?\2\s?(\d{4}|\d{2})(?![\d\/]|-\d)/u',
            ];
        }

        return self::$patterns;
    }
}

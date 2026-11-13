<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use App\Domain\Ocr\InvoiceProposal;
use App\Domain\Properties\PropertyValues;

/**
 * The amount to pay by weight (points 120.2, 120.4, A250): Zahlbetrag … beats Brutto, beats the largest amount low on
 * the page; Netto, MwSt, Zwischensumme, Rabatt and line items lose; netto + tax = brutto makes it sure. Also the currency.
 *
 * @phpstan-import-type Line from InvoiceLines
 * @phpstan-import-type Box from InvoiceLines
 */
final class AmountReader
{
    private const AMOUNT = '/(?<![\d.,])(-?(?:\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2}|\d{1,3}(?:,\d{3})+\.\d{2}|\d+\.\d{2}))(?!\d|[.,]\d)(?!\s*%)/u';

    /**
     * What kind of amount a line holds, by its label: total (Zahlbetrag …), gross (Brutto, inkl. MwSt), net, tax,
     * exclude (Rabatt, Skonto …) or plain.
     *
     * @return array{string, int, string|null} kind, weight, keyword
     */
    public static function classify(string $text): array
    {
        $t = InvoiceLines::lower($text);
        $match = static function (string $pattern) use ($t): ?string {
            return preg_match($pattern, $t, $m) === 1 ? trim($m[0]) : null;
        };
        if (($k = $match('/zahlbetrag|zu zahlende[rn]? betrag|zu zahlen|gesamtbetrag|rechnungsbetrag|endbetrag|summe brutto|bruttosumme|bruttobetrag|amount due|total due|fälliger betrag|offener betrag/u')) !== null) {
            return ['total', 100, $k];
        }
        if (($k = $match('/\b(?:inkl|incl|einschl)\.?\s*(?:\d{1,2}\s*%\s*)?(?:mwst|ust|vat|umsatzsteuer|mehrwertsteuer)|\bbrutto\b/u')) !== null) {
            return ['gross', 70, $k];
        }
        if (($k = $match('/\bnetto|nettobetrag|zwischensumme|\bsubtotal\b|summe netto/u')) !== null) {
            return ['net', -50, $k];
        }
        if (($k = $match('/\bmwst\b|\bust\b|umsatzsteuer|mehrwertsteuer|\bvat\b|\bmwst\.|\bust\./u')) !== null) {
            return ['tax', -60, $k];
        }
        if (($k = $match('/rabatt|skonto|abzüglich|\babzgl|nachlass|gutschrift|anzahlung|bereits bezahlt|versandkosten/u')) !== null) {
            return ['exclude', -40, $k];
        }
        if (($k = $match('/gesamtsumme|\bgesamt\b|\btotal\b|\bsumme\b/u')) !== null) {
            return ['gross', $k === 'gesamtsumme' ? 60 : 45, $k];
        }

        return ['plain', 0, null];
    }

    /**
     * @param  list<Line>  $lines
     * @param  array<string, mixed>  $hint
     * @return array{InvoiceProposal|null, list<InvoiceProposal>}
     */
    public static function amounts(array $lines, string $currency, array $hint): array
    {
        $candidates = [];
        $pending = null;
        $hintKeyword = is_string($hint['amount_keyword'] ?? null) && $hint['amount_keyword'] !== '' ? $hint['amount_keyword'] : null;
        $hintBox = is_array($hint['amount_box'] ?? null) ? $hint['amount_box'] : null;
        $hintPage = is_numeric($hint['amount_page'] ?? null) ? (int) $hint['amount_page'] : null;
        foreach ($lines as $line) {
            [$kind, $weight, $keyword] = self::classify($line['text']);
            $found = [];
            if (preg_match_all(self::AMOUNT, $line['text'], $matches, PREG_OFFSET_CAPTURE) !== false) {
                foreach ($matches[1] as [$raw, $offset]) {
                    $number = PropertyValues::parseNumber($raw, 2);
                    if ($number !== null) {
                        $found[] = [intdiv($number, 100), $raw, $offset];
                    }
                }
            }
            if ($found === []) {
                // A label alone on its line (a table): the amount stands on the next line.
                $pending = in_array($kind, ['total', 'gross'], true) ? [$kind, (int) round($weight * 0.8), $keyword, $line['page']] : null;

                continue;
            }
            if ($kind === 'plain' && $pending !== null && $pending[3] === $line['page']) {
                [$kind, $weight, $keyword] = $pending;
            }
            $pending = null;
            $lineCurrency = self::lineCurrency($line['text']) ?? $currency;
            $last = count($found) - 1;
            foreach ($found as $n => [$minor, $raw, $offset]) {
                $w = $weight;
                $k = $kind;
                if ($kind === 'plain') {
                    // Line items carry several amounts (quantity price, total); one amount low on the page is likelier.
                    $w = count($found) > 1 ? 5 : 15 + ($line['y'] >= 0.5 ? 10 : 0);
                } elseif ($n !== $last) {
                    // A labelled line with several amounts: the label belongs to the last one (netto | MwSt | brutto columns).
                    $w = 5;
                    $k = 'plain';
                }
                $box = InvoiceLines::box($line, $offset, $offset + strlen($raw));
                if ($hintKeyword !== null && str_contains(InvoiceLines::lower($line['text']), $hintKeyword) && $n === $last) {
                    $w += 200;
                }
                if ($hintBox !== null && $box !== null && ($hintPage === null || $hintPage === $line['page']) && self::near($hintBox, $box)) {
                    $w += 150;
                }
                $candidates[] = ['minor' => $minor, 'kind' => $k, 'weight' => $w, 'keyword' => $k === 'plain' ? self::label($line['text']) : $keyword, 'line' => $line, 'box' => $box, 'currency' => $lineCurrency];
            }
        }
        $positive = array_values(array_filter($candidates, static fn (array $c): bool => $c['minor'] > 0));
        if ($positive === []) {
            return [null, []];
        }

        // netto + tax = brutto (120.2): the sum of every net line and one or all tax lines.
        $nets = array_values(array_unique(array_column(array_filter($positive, static fn (array $c): bool => $c['kind'] === 'net'), 'minor')));
        $taxes = array_values(array_unique(array_column(array_filter($positive, static fn (array $c): bool => $c['kind'] === 'tax'), 'minor')));
        $sums = [];
        foreach ($nets as $net) {
            foreach ($taxes as $tax) {
                $sums[] = $net + $tax;
            }
            $sums[] = $net + array_sum($taxes);
        }
        $checked = static fn (int $minor): bool => array_filter($sums, static fn (int $s): bool => abs($s - $minor) <= 1) !== [];

        $pool = array_values(array_filter($positive, static fn (array $c): bool => in_array($c['kind'], ['total', 'gross', 'plain'], true)));
        if ($pool === []) {
            $pool = $positive;
        }
        usort($pool, static fn (array $a, array $b): int => [$b['weight'], $b['minor']] <=> [$a['weight'], $a['minor']]);
        $best = $pool[0];
        if ($best['weight'] < 100) {
            foreach ($pool as $c) {
                if ($c['minor'] !== $best['minor'] && $checked($c['minor']) && $c['weight'] >= 0) {
                    $best = $c;
                    break;
                }
            }
        }
        $confidence = match (true) {
            $best['weight'] >= 150 => 0.92,
            $best['kind'] === 'total' => 0.85,
            $best['kind'] === 'gross' => 0.7,
            default => 0.4,
        };
        if ($checked($best['minor'])) {
            $confidence = max($confidence, 0.95);
        } else {
            foreach ($pool as $c) {
                if ($c !== $best && $c['weight'] === $best['weight'] && $c['minor'] !== $best['minor']) {
                    $confidence *= 0.7;
                    break;
                }
            }
        }
        $amount = InvoiceLines::proposal(PropertyValues::formatAmount($best['minor'], $best['currency']), $confidence, $best['line'], $best['box'], $best['minor'], $best['currency'], keyword: $best['keyword']);

        $others = [];
        $seen = [$best['minor'] => true];
        usort($positive, static fn (array $a, array $b): int => [$b['weight'], $b['minor']] <=> [$a['weight'], $a['minor']]);
        foreach ($positive as $c) {
            if ($c['kind'] === 'tax' || isset($seen[$c['minor']]) || count($others) >= 6) {
                continue;
            }
            $seen[$c['minor']] = true;
            $others[] = InvoiceLines::proposal(PropertyValues::formatAmount($c['minor'], $c['currency']), 0.3, $c['line'], $c['box'], $c['minor'], $c['currency'], keyword: $c['keyword']);
        }

        return [$amount, $others];
    }

    /** The words of a line without numbers and currency ("Abschlag 50,00 €" → "abschlag"), null when nothing is left. */
    private static function label(string $text): ?string
    {
        $label = trim((string) preg_replace(['/[\d.,:;€$£%\-+*\/()]+/u', '/\b(?:eur|chf|usd|gbp)\b/u', '/\s+/u'], [' ', ' ', ' '], InvoiceLines::lower($text)));

        return mb_strlen($label) >= 3 ? mb_substr($label, 0, 60) : null;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array{x: float, y: float, w: float, h: float}  $b
     */
    private static function near(array $a, array $b): bool
    {
        $ax = (float) ($a['x'] ?? 0) + (float) ($a['w'] ?? 0) / 2;
        $ay = (float) ($a['y'] ?? 0) + (float) ($a['h'] ?? 0) / 2;

        return abs($ax - ($b['x'] + $b['w'] / 2)) <= 0.05 && abs($ay - ($b['y'] + $b['h'] / 2)) <= 0.03;
    }

    private static function lineCurrency(string $text): ?string
    {
        return match (true) {
            preg_match('/€|\bEUR\b/u', $text) === 1 => 'EUR',
            preg_match('/\bCHF\b/u', $text) === 1 => 'CHF',
            preg_match('/£|\bGBP\b/u', $text) === 1 => 'GBP',
            preg_match('/\$|\bUSD\b/u', $text) === 1 => 'USD',
            default => null,
        };
    }

    /**
     * @param  list<Line>  $lines
     */
    public static function documentCurrency(array $lines): string
    {
        $count = [];
        foreach ($lines as $line) {
            $c = self::lineCurrency($line['text']);
            if ($c !== null) {
                $count[$c] = ($count[$c] ?? 0) + 1;
            }
        }
        arsort($count);

        return $count === [] ? 'EUR' : (string) array_key_first($count);
    }
}

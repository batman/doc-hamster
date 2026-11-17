<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use App\Domain\Ocr\InvoiceProposal;
use App\Domain\Properties\PropertyValues;

/**
 * The amount to pay (points 120.2, 120.4, A250, 235, A399): every amount of the document is a candidate, its kind read
 * from the label in front of it (AmountLabels) or a label alone on the line above. Ranking: a labelled total ("zu
 * zahlen"/"Amount Due" over Gesamtbetrag/Total over Summe), the SUMME of a Kassenbon, an amount in larger print, the
 * largest amount low on the last page, the largest at all; net, VAT, line items, Skonto, deposits, change, Pfand, tip
 * and balances lose. net + VAT = gross (±1 cent) makes it sure and overrules a weaker label; a paid amount, a repeated
 * one or the line items adding up raise the confidence. Amounts in IBAN, phone or invoice-number lines without a
 * currency, or repaired by OCR fixes, cost points. The currency goes with the amount: its own, the line's, the
 * document's, else EUR.
 *
 * @phpstan-import-type Line from InvoiceLines
 * @phpstan-import-type Box from InvoiceLines
 *
 * @phpstan-type Candidate array{minor: int, kind: string, weight: int, keyword: string|null, line: Line, box: Box|null, currency: string, big: bool, n: int, item: bool}
 */
final class AmountReader
{
    private const NUMBER_LINE = '/\biban\b|\bbic\b|swift|\btel\b|telefon|phone|\bfax\b|mobil|steuer-?nr|steuernummer|ust-?id|vat (?:no|id|reg)|\bhrb\b|amtsgericht|kunden-?n|rechnungs-?n|invoice (?:no|number|#)|order (?:no|number)|bestell-?n|\bblz\b|konto-?n/u';

    /**
     * @return array{string, int, string|null} kind, weight, keyword
     */
    public static function classify(string $text): array
    {
        return AmountLabels::classify($text);
    }

    /**
     * @param  list<Line>  $lines
     * @param  array<string, mixed>  $hint
     * @return array{InvoiceProposal|null, list<InvoiceProposal>}
     */
    public static function amounts(array $lines, string $currency, array $hint): array
    {
        $all = self::candidates($lines, $currency, $hint);
        $positive = array_values(array_filter($all, static fn (array $c): bool => $c['minor'] > 0));
        if ($positive === []) {
            return [null, []];
        }
        $checked = self::check($positive);
        $positive = self::repeated($positive);
        $pool = array_values(array_filter($positive, static fn (array $c): bool => in_array($c['kind'], ['total', 'gross', 'plain'], true)));
        if ($pool === []) {
            $pool = array_values(array_filter($positive, static fn (array $c): bool => $c['kind'] === 'net')) ?: $positive;
        }
        usort($pool, static fn (array $a, array $b): int => [$b['weight'], $b['minor']] <=> [$a['weight'], $a['minor']]);
        $best = $pool[0];
        // A mismatch sends the reader to the candidate that adds up – but never past "zu zahlen" or a taught place.
        if ($checked !== null && ! $checked($best['minor']) && $best['weight'] < 110) {
            foreach ($pool as $c) {
                if ($checked($c['minor']) && $c['weight'] >= 0) {
                    $best = $c;
                    break;
                }
            }
        }
        $amount = InvoiceLines::proposal(PropertyValues::formatAmount($best['minor'], $best['currency']), self::confidence($best, $pool, $all, $checked), $best['line'], $best['box'], $best['minor'], $best['currency'], keyword: $best['keyword']);

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

    /**
     * @param  list<Line>  $lines
     * @param  array<string, mixed>  $hint
     * @return list<Candidate>
     */
    private static function candidates(array $lines, string $currency, array $hint): array
    {
        $hintKeyword = is_string($hint['amount_keyword'] ?? null) && $hint['amount_keyword'] !== '' ? $hint['amount_keyword'] : null;
        $hintBox = is_array($hint['amount_box'] ?? null) ? $hint['amount_box'] : null;
        $hintPage = is_numeric($hint['amount_page'] ?? null) ? (int) $hint['amount_page'] : null;
        $decimal = AmountParser::decimalStyle($lines);
        $receipt = self::receipt($lines);
        $median = InvoiceLines::medianHeight($lines);
        $lastPage = $lines === [] ? 1 : max(array_column($lines, 'page'));
        $candidates = [];
        $pending = null;
        foreach ($lines as $n => $line) {
            $found = AmountParser::scan($line['text'], $decimal);
            [$lineKind, $lineWeight, $lineKeyword] = AmountLabels::classify($line['text']);
            if ($found === []) {
                // A label alone on its line (a table): the amount stands on the next line.
                $short = count(preg_split('/\s+/u', trim($line['text'])) ?: []) <= 4;
                $pending = $short && in_array($lineKind, ['total', 'gross'], true) ? [$lineKind, (int) round($lineWeight * 0.8), $lineKeyword, $line['page']] : null;

                continue;
            }
            $numberLine = preg_match(self::NUMBER_LINE, InvoiceLines::lower($line['text'])) === 1;
            $lineCurrency = self::lineCurrency($line['text']) ?? $currency;
            $last = count($found) - 1;
            $segments = [];
            $from = 0;
            foreach ($found as $a) {
                $segments[] = AmountLabels::classify(substr($line['text'], $from, $a['s'] - $from));
                $from = $a['e'];
            }
            // Several labels ("Netto 340,00 MwSt 64,60 Brutto 404,60"): each amount has its own. One label: it is the
            // last amount's (netto | MwSt | brutto columns, "Gesamt 2 x 50,00 100,00").
            $own = count(array_filter($segments, static fn (array $s): bool => $s[0] !== 'plain')) > 1;
            foreach ($found as $k => $a) {
                [$kind, $w, $keyword] = $own ? $segments[$k] : ['plain', 0, null];
                if ($kind === 'plain' && $k === $last && $lineKind !== 'plain') {
                    [$kind, $w, $keyword] = [$lineKind, $lineWeight, $lineKeyword];
                }
                if ($kind === 'plain' && $k === $last && $pending !== null && $pending[3] === $line['page']) {
                    [$kind, $w, $keyword] = $pending;
                }
                $box = InvoiceLines::box($line, $a['s'], $a['e']);
                $big = $median > 0 && $box !== null && $box['h'] >= 1.3 * $median;
                if ($kind === 'plain') {
                    // Line items carry several amounts (quantity price, total); one amount low on the last page is likelier.
                    $w = count($found) > 1 ? 5 : 15 + ($line['y'] >= 0.5 && $line['page'] === $lastPage ? 10 : 0);
                    $keyword = self::label($line['text']);
                    // A41: "100,00 19,00 119,00" – net, tax and the gross that adds up on one unlabelled line.
                    if ($k === $last && $last >= 2 && abs($found[$last - 2]['minor'] + $found[$last - 1]['minor'] - $a['minor']) <= 1 && $found[$last - 1]['minor'] < $a['minor']) {
                        [$kind, $w] = ['gross', 95];
                    }
                    // A41: a whole number without cents (an article number, a quantity) is rarely the total.
                    if ($kind === 'plain' && $decimal !== null && preg_match('/[.,]\d{1,2}\s*$/', rtrim($a['raw'], ' €$£')) !== 1) {
                        $w -= 20;
                    }
                }
                if ($big && in_array($kind, ['plain', 'gross'], true)) {
                    $w += 25;
                }
                if ($receipt && $kind === 'gross' && $keyword === 'summe') {
                    $w = 85;
                }
                if ($numberLine && $a['currency'] === null) {
                    $w -= 50;
                }
                if ($a['fixed']) {
                    $w -= 5;
                }
                if ($hintKeyword !== null && $k === $last && str_contains(InvoiceLines::lower($line['text']), $hintKeyword)) {
                    $w += 200;
                }
                if ($hintBox !== null && $box !== null && ($hintPage === null || $hintPage === $line['page']) && self::near($hintBox, $box)) {
                    $w += 150;
                }
                $minor = $kind === 'exclude' && $keyword === 'gutschrift' ? -abs($a['minor']) : $a['minor'];
                $candidates[] = ['minor' => $minor, 'kind' => $kind, 'weight' => $w, 'keyword' => $keyword, 'line' => $line, 'box' => $box,
                    'currency' => $a['currency'] ?? $lineCurrency, 'big' => $big, 'n' => $n, 'item' => $kind === 'plain' && $last > 0 && $k === $last];
            }
            $pending = null;
        }

        return $candidates;
    }

    /**
     * A41: on a till receipt the total stands several times (Summe, paid by card, VAT analysis) – an amount on three or
     * more lines gains over a lone larger one (an article number read as an amount).
     *
     * @param  list<Candidate>  $positive
     * @return list<Candidate>
     */
    private static function repeated(array $positive): array
    {
        $lines = [];
        foreach ($positive as $c) {
            if (! in_array($c['kind'], ['net', 'tax', 'exclude'], true)) {
                $lines[$c['minor']][$c['n']] = true;
            }
        }
        foreach ($positive as $i => $c) {
            if (count($lines[$c['minor']] ?? []) >= 3 && in_array($c['kind'], ['plain', 'gross', 'paid'], true)) {
                $positive[$i]['weight'] += 40;
            }
        }

        return $positive;
    }

    /**
     * net + tax = gross (120.2): the sum of every net line and one or all tax lines, also less a deposit or Skonto.
     * Null when the document has no net and tax amounts to check against.
     *
     * @param  list<Candidate>  $positive
     * @return (callable(int): bool)|null
     */
    private static function check(array $positive): ?callable
    {
        $of = static fn (string $kind): array => array_values(array_unique(array_column(array_filter($positive, static fn (array $c): bool => $c['kind'] === $kind), 'minor')));
        [$nets, $taxes, $minus] = [$of('net'), $of('tax'), $of('exclude')];
        if ($nets === [] || $taxes === []) {
            return null;
        }
        $sums = [];
        foreach ($nets as $net) {
            foreach ([...$taxes, array_sum($taxes)] as $tax) {
                $sums[] = $net + $tax;
                foreach ($minus as $m) {
                    $sums[] = $net + $tax - $m;
                }
            }
        }

        return static fn (int $minor): bool => array_filter($sums, static fn (int $s): bool => abs($s - $minor) <= 1) !== [];
    }

    /**
     * @param  Candidate  $best
     * @param  list<Candidate>  $pool
     * @param  list<Candidate>  $all
     * @param  (callable(int): bool)|null  $checked
     */
    private static function confidence(array $best, array $pool, array $all, ?callable $checked): float
    {
        if ($checked !== null && $checked($best['minor'])) {
            return 0.97;
        }
        $confidence = match (true) {
            $best['weight'] >= 150 => 0.92,
            $best['weight'] >= 90 && in_array($best['kind'], ['total', 'gross'], true) => 0.9,
            $best['weight'] >= 80 && $best['kind'] === 'gross' => 0.85,
            $best['big'] => 0.6,
            $best['weight'] >= 25 => 0.5,
            default => 0.4,
        };
        if ($checked !== null) {
            // net + tax are there but do not add up to it: not sure.
            $confidence = min($confidence, 0.8);
        }
        foreach ($pool as $c) {
            if ($c['n'] !== $best['n'] && $c['weight'] === $best['weight'] && $c['minor'] !== $best['minor']) {
                $confidence *= 0.7;
                break;
            }
        }
        // A paid amount, the same amount once more, or the line items adding up to it.
        $again = array_filter($all, static fn (array $c): bool => $c['n'] !== $best['n'] && $c['minor'] === $best['minor'] && ! in_array($c['kind'], ['net', 'tax', 'exclude'], true));
        $items = array_sum(array_column(array_filter($all, static fn (array $c): bool => $c['item']), 'minor'));
        if ($again !== [] || ($items > 0 && abs($items - $best['minor']) <= 1)) {
            $confidence += 0.08;
        }

        return min(0.95, $confidence);
    }

    /**
     * A Kassenbon: payment and change lines, a till or a TSE.
     *
     * @param  list<Line>  $lines
     */
    private static function receipt(array $lines): bool
    {
        return preg_match('/kassenbon|bon-?nr|\bkasse\b|rückgeld|wechselgeld|gegeben|ec-?karte|girocard|\btse\b|kartenzahlung|\bbar\b/u', InvoiceLines::lower(implode("\n", array_column($lines, 'text')))) === 1;
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
            preg_match('/€|(?<!\p{L})eur(?:o|os)?(?!\p{L})/iu', $text) === 1 => 'EUR',
            preg_match('/(?<!\p{L})chf(?!\p{L})/iu', $text) === 1 => 'CHF',
            preg_match('/£|(?<!\p{L})gbp(?!\p{L})/iu', $text) === 1 => 'GBP',
            preg_match('/\$|(?<!\p{L})usd(?!\p{L})/iu', $text) === 1 => 'USD',
            default => null,
        };
    }

    /**
     * @param  list<Line>  $lines
     */
    public static function documentCurrency(array $lines, string $default = 'EUR'): string
    {
        $count = [];
        foreach ($lines as $line) {
            $c = self::lineCurrency($line['text']);
            if ($c !== null) {
                // A written code ("USD") is meant; a lone sign may be a misread €.
                $count[$c] = ($count[$c] ?? 0) + (preg_match('/(?<!\p{L})(?:eur|chf|gbp|usd)(?!\p{L})/iu', $line['text']) === 1 ? 2 : 1);
            }
        }
        arsort($count);
        $first = $count === [] ? null : (string) array_key_first($count);

        // A42: one stray "$" or "£" (an OCR misread of €) does not make the document foreign – the account's currency does.
        return $first !== null && ($count[$first] >= 2 || $first === $default) ? $first : $default;
    }
}

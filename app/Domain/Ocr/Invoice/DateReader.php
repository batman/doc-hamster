<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use App\Domain\Ocr\InvoiceProposal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Invoice and due date (points 120.3, A251, 234, A398, A399): every date on the document is a candidate, its kind
 * read from the label in front of it, on the line above (a column header) or right behind it (DateLabels). Ranking:
 * a labelled issue date; a date with a time (a Kassenbon); an unlabelled date high on page 1, near "Rechnung"/
 * "Invoice"; any other unlabelled date (earlier and repeated ones first); then the delivery and the order date. Far in
 * the future, before 2000, low in the footer, part of a range or next to another kind's label cost points; a due date
 * before the chosen date sends the reader to the next candidate. The due date comes from its label or "innerhalb N
 * Tagen" counted from the invoice date.
 *
 * Dates with / or - read two ways (03/04/2026): a part above 12 decides; else an unambiguous one on the same document
 * fixes the order; else US hints (dollars, English labels without European words or currencies) read month first; else day first.
 *
 * @phpstan-import-type Line from InvoiceLines
 *
 * @phpstan-type Candidate array{value: string, kind: string|null, behind: bool, line: Line, n: int, s: int, e: int, range: bool, time: bool}
 */
final class DateReader
{
    /** The kinds that may become the invoice date, with base score and confidence. */
    private const RANK = [
        DateLabels::ISSUE => [100, 0.95],
        DateLabels::GENERIC => [90, 0.9],
        DateLabels::DELIVERY => [20, 0.5],
        DateLabels::ORDER => [10, 0.4],
    ];

    /**
     * The dates on a line as Y-m-d with their byte offsets (slash dates in $order: dmy or mdy).
     *
     * @return list<array{string, int, int}>
     */
    public static function dates(string $text, string $order = 'dmy'): array
    {
        return array_map(static fn (array $d): array => [self::pick($d, $order), $d['s'], $d['e']], DateParser::scan($text));
    }

    /**
     * How to read the slash dates of a document: dmy or mdy.
     *
     * @param  list<Line>  $lines
     */
    public static function slashOrder(array $lines): string
    {
        foreach ($lines as $line) {
            foreach (DateParser::scan($line['text']) as $d) {
                if ($d['slash'] && ($d['d'] === null) !== ($d['m'] === null)) {
                    return $d['d'] !== null ? 'dmy' : 'mdy';
                }
            }
        }
        $all = InvoiceLines::lower(implode("\n", array_column($lines, 'text')));
        if (preg_match('/€|\beur\b|£|\bgbp\b|\bchf\b|mwst|\bust\b|umsatzsteuer|rechnung|datum|\biban\b|\bvat\b|\btva\b|\biva\b|\bbtw\b|factura|facture|fattura|factuur/u', $all) === 1) {
            return 'dmy';
        }

        return preg_match('/\$|\busd\b|\binvoice\b|amount due|balance due|bill to|\btotal\b/u', $all) === 1 ? 'mdy' : 'dmy';
    }

    /**
     * Every date of the document with its kind.
     *
     * @param  list<Line>  $lines
     * @return list<Candidate>
     */
    public static function found(array $lines, string $order): array
    {
        $out = [];
        $header = null;
        foreach ($lines as $n => $line) {
            $labels = DateLabels::find($line['text']);
            $dates = DateParser::scan($line['text']);
            if ($dates === []) {
                $header = $labels !== [] ? ['labels' => $labels, 'line' => $line, 'n' => $n] : null;

                continue;
            }
            $taken = [];
            foreach ($dates as $k => $d) {
                $label = DateLabels::at($labels, $d['s'], $d['e'], $taken, $dates[$k + 1]['s'] ?? PHP_INT_MAX);
                if ($label !== null && ! $label['behind']) {
                    $taken[] = $label['s'];
                } elseif ($header !== null && $header['n'] === $n - 1 && $header['line']['page'] === $line['page']
                    && ($above = self::fromHeader($header['labels'], $header['line'], $line, $d['s'], $d['e'], $k, count($dates))) !== null) {
                    $label = [...$above, 'behind' => false];
                }
                $out[] = ['value' => self::pick($d, $order), 'kind' => $label['kind'] ?? null, 'behind' => $label['behind'] ?? false,
                    'line' => $line, 'n' => $n, 's' => $d['s'], 'e' => $d['e'], 'range' => $d['range'], 'time' => $d['time']];
            }
            $header = null;
        }

        return $out;
    }

    /**
     * @param  list<Line>  $lines
     */
    public static function invoiceDate(array $lines, ?string $order = null): ?InvoiceProposal
    {
        $found = self::found($lines, $order ?? self::slashOrder($lines));
        $count = array_count_values(array_column(array_filter($found, static fn (array $c): bool => $c['kind'] !== DateLabels::NEVER), 'value'));
        $total = max(1, count($lines));
        $soon = CarbonImmutable::now()->addDays(7)->format('Y-m-d');
        $ranked = [];
        foreach ($found as $c) {
            if ($c['range'] || ($c['kind'] !== null && ! isset(self::RANK[$c['kind']]))) {
                continue;
            }
            [$score, $confidence] = self::base($c, $lines, $count[$c['value']] ?? 1, $total);
            if ($c['value'] > $soon) {
                $score -= 60;
                $confidence *= 0.5;
            }
            if ($c['value'] < '2000-01-01') {
                $score -= 80;
                $confidence *= 0.3;
            }
            $ranked[] = ['c' => $c, 'score' => $score, 'confidence' => $confidence];
        }
        if ($ranked === []) {
            return null;
        }
        usort($ranked, static fn (array $a, array $b): int => [$b['score'], $a['c']['n']] <=> [$a['score'], $b['c']['n']]);

        // A labelled due date before the chosen date: the next candidate.
        $dues = array_column(array_filter($found, static fn (array $c): bool => $c['kind'] === DateLabels::DUE && ! $c['range'] && ! $c['behind']), 'value');
        $firstDue = $dues === [] ? null : min($dues);
        $best = $ranked[0];
        foreach ($ranked as $r) {
            if ($firstDue === null || $r['c']['value'] <= $firstDue) {
                $best = $r;
                break;
            }
        }
        $confidence = $best['confidence'];
        foreach ($ranked as $r) {
            if ($r !== $best && $r['c']['kind'] === $best['c']['kind'] && $r['c']['kind'] !== null && $r['c']['value'] !== $best['c']['value'] && $r['score'] >= $best['score'] - 5) {
                $confidence *= 0.75;
                break;
            }
        }
        $c = $best['c'];

        return InvoiceLines::proposal($c['value'], $confidence, $c['line'], InvoiceLines::box($c['line'], $c['s'], $c['e']));
    }

    public static function plusDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify('+'.$days.' days')->format('Y-m-d');
    }

    /**
     * A251: "zahlbar bis 30.11.2026", "Due Date: …", "fällig am …", or "zahlbar innerhalb 14 Tagen" counted from the
     * invoice date.
     *
     * @param  list<Line>  $lines
     */
    public static function dueDate(array $lines, ?InvoiceProposal $invoiceDate, ?string $order = null): ?InvoiceProposal
    {
        $order ??= self::slashOrder($lines);
        foreach (self::found($lines, $order) as $c) {
            if ($c['kind'] === DateLabels::DUE && ! $c['behind'] && ! $c['range'] && ($invoiceDate === null || $c['value'] >= $invoiceDate->value)) {
                return InvoiceLines::proposal($c['value'], 0.85, $c['line'], InvoiceLines::box($c['line'], $c['s'], $c['e']));
            }
        }
        foreach ($lines as $line) {
            if (preg_match('/skonto/iu', $line['text']) !== 1 && preg_match('/bitte\s.*\bbis\b/iu', $line['text']) === 1 && ($dates = self::dates($line['text'], $order)) !== []) {
                $date = end($dates);

                return InvoiceLines::proposal($date[0], 0.8, $line, InvoiceLines::box($line, $date[1], $date[2]));
            }
        }
        if ($invoiceDate === null) {
            return null;
        }
        foreach ($lines as $line) {
            $skonto = preg_match('/skonto/iu', $line['text']) === 1;
            $pattern = $skonto
                ? '/(\d{1,3})\s*tage?n?\s*(?:rein\s*)?netto/iu'
                : '/(?:zahlbar|zahlungsziel|binnen|innerhalb|within|payable|net)\D{0,25}?(\d{1,3})\s*(?:tage?n?|days)/iu';
            if (preg_match($pattern, $line['text'], $m, PREG_OFFSET_CAPTURE) === 1 && (int) $m[1][0] > 0 && (int) $m[1][0] <= 365) {
                return InvoiceLines::proposal(self::plusDays($invoiceDate->value, (int) $m[1][0]), 0.75, $line, InvoiceLines::box($line, $m[0][1], $m[0][1] + strlen($m[0][0])));
            }
            if (! $skonto && preg_match('/zahlbar sofort|sofort zahlbar|sofort fällig|due immediately|due on receipt/iu', $line['text']) === 1) {
                return InvoiceLines::proposal($invoiceDate->value, 0.6, $line);
            }
        }

        return null;
    }

    /**
     * Score and confidence of one candidate before the penalties for the future and the last century.
     *
     * @param  Candidate  $c
     * @param  list<Line>  $lines
     * @return array{float, float}
     */
    private static function base(array $c, array $lines, int $repeated, int $total): array
    {
        $line = $c['line'];
        if ($c['kind'] !== null) {
            [$score, $confidence] = self::RANK[$c['kind']];

            // A label behind the date is weaker than one in front of it.
            return $c['behind'] ? [$score - 10, $confidence - 0.1] : [$score, $confidence];
        }
        if ($c['time']) {
            [$score, $confidence] = [70.0, 0.8];
        } elseif ($line['page'] === 1 && $line['y'] < 0.34) {
            [$score, $confidence] = [55.0, 0.5];
            if (self::nearInvoiceWord($lines, $c['n'])) {
                $score += 10;
                $confidence = 0.6;
            }
        } else {
            [$score, $confidence] = [40.0, 0.35];
        }
        $score -= 5 * $c['n'] / $total;
        if ($repeated > 1) {
            $score += min(10, 5 * ($repeated - 1));
            $confidence += 0.05;
        }
        if ($line['y'] > 0.85) {
            $score -= 15;
        }
        if (DateLabels::find($line['text']) !== []) {
            // Next to another kind's label (one that a neighbour date took).
            $score -= 30;
            $confidence *= 0.7;
        }

        return [$score, $confidence];
    }

    /**
     * @param  list<Line>  $lines
     */
    private static function nearInvoiceWord(array $lines, int $n): bool
    {
        for ($i = max(0, $n - 2); $i <= $n + 2; $i++) {
            if (isset($lines[$i]) && preg_match('/rechnung|invoice|factura|facture|fattura|factuur|fatura|beleg|quittung|receipt|\bnr\b|\bno\b|#/iu', $lines[$i]['text']) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The label of a column header right above: by position with word boxes, else by count.
     *
     * @param  list<array{kind: string, s: int, e: int}>  $labels
     * @param  Line  $header
     * @param  Line  $line
     * @return array{kind: string, s: int, e: int}|null
     */
    private static function fromHeader(array $labels, array $header, array $line, int $s, int $e, int $k, int $count): ?array
    {
        $box = InvoiceLines::box($line, $s, $e);
        if ($box !== null && $header['spans'] !== []) {
            $best = null;
            $distance = 0.3;
            foreach ($labels as $label) {
                $b = InvoiceLines::box($header, $label['s'], $label['e']);
                if ($b !== null && abs(($b['x'] + $b['w'] / 2) - ($box['x'] + $box['w'] / 2)) < $distance) {
                    $distance = abs(($b['x'] + $b['w'] / 2) - ($box['x'] + $box['w'] / 2));
                    $best = $label;
                }
            }

            return $best;
        }
        if (count($labels) === $count) {
            return $labels[$k];
        }

        return count($labels) === 1 && $k === 0 ? $labels[0] : null;
    }

    /**
     * @param  array{d: string|null, m: string|null}  $d
     */
    private static function pick(array $d, string $order): string
    {
        return (string) ($order === 'mdy' ? ($d['m'] ?? $d['d']) : ($d['d'] ?? $d['m']));
    }
}

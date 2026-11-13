<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use App\Domain\Ocr\InvoiceProposal;
use App\Domain\Properties\PropertyValues;
use DateTimeImmutable;

/**
 * Invoice and due date (points 120.3, A251): labelled dates first; "zahlbar bis …", "fällig am …" or "innerhalb N
 * Tagen" counted from the invoice date.
 *
 * @phpstan-import-type Line from InvoiceLines
 */
final class DateReader
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mär' => 3, 'mae' => 3, 'mar' => 3, 'apr' => 4, 'mai' => 5, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'dez' => 12, 'dec' => 12,
    ];

    private const DATE = '/(?<![\d.])(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4}|\d{2})(?![\d])|(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)|(?<!\d)(\d{1,2})\.?\s*(januar|jan|februar|feb|märz|maerz|mär|march|april|apr|mai|may|juni|june|jun|juli|july|jul|august|aug|september|sept|sep|oktober|october|okt|oct|november|nov|dezember|december|dez|dec)\.?\s+(\d{4})(?!\d)/iu';

    /**
     * The dates on a line as Y-m-d with their byte offsets.
     *
     * @return list<array{string, int, int}>
     */
    public static function dates(string $text): array
    {
        if (preg_match_all(self::DATE, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $out = [];
        foreach ($matches as $m) {
            // Groups of the alternatives not taken may be missing: read each one by number.
            $g = static fn (int $i): string => isset($m[$i]) ? $m[$i][0] : '';
            $date = null;
            if ($g(1) !== '') {
                $date = PropertyValues::parseDate($g(1).'.'.$g(2).'.'.$g(3));
            } elseif ($g(4) !== '') {
                $date = PropertyValues::parseDate($g(4).'-'.$g(5).'-'.$g(6));
            } elseif ($g(7) !== '') {
                $month = self::MONTHS[mb_substr(mb_strtolower($g(8)), 0, 3)] ?? null;
                $date = $month !== null ? PropertyValues::parseDate($g(7).'.'.$month.'.'.$g(9)) : null;
            }
            if ($date !== null) {
                $out[] = [$date, $m[0][1], $m[0][1] + strlen($m[0][0])];
            }
        }

        return $out;
    }

    /**
     * @param  list<Line>  $lines
     */
    public static function invoiceDate(array $lines): ?InvoiceProposal
    {
        foreach ([
            ['/rechnungsdatum|rechnungs-datum|datum der rechnung|invoice date|belegdatum/iu', 0.85],
            ['/\bdatum\b|\bdate\b/iu', 0.7],
        ] as [$label, $confidence]) {
            foreach ($lines as $n => $line) {
                if (preg_match($label, $line['text']) !== 1 || preg_match('/liefer|leistung|fällig|zahlbar|bestell|geburt|skonto|due/iu', $line['text']) === 1) {
                    continue;
                }
                $dates = self::dates($line['text']);
                $source = $line;
                if ($dates === [] && isset($lines[$n + 1]) && $lines[$n + 1]['page'] === $line['page']) {
                    $source = $lines[$n + 1];
                    $dates = self::dates($source['text']);
                }
                if ($dates !== []) {
                    return InvoiceLines::proposal($dates[0][0], $confidence, $source, InvoiceLines::box($source, $dates[0][1], $dates[0][2]));
                }
            }
        }
        foreach ($lines as $line) {
            $dates = self::dates($line['text']);
            if ($dates !== [] && preg_match('/fällig|zahlbar|skonto|liefer|leistung/iu', $line['text']) !== 1) {
                $top = $line['page'] === 1 && $line['y'] < 0.4;

                return InvoiceLines::proposal($dates[0][0], $top ? 0.45 : 0.3, $line, InvoiceLines::box($line, $dates[0][1], $dates[0][2]));
            }
        }

        return null;
    }

    public static function plusDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify('+'.$days.' days')->format('Y-m-d');
    }

    /**
     * A251: "zahlbar bis 30.11.2026", "fällig am …", or "zahlbar innerhalb 14 Tagen" counted from the invoice date.
     *
     * @param  list<Line>  $lines
     */
    public static function dueDate(array $lines, ?InvoiceProposal $invoiceDate): ?InvoiceProposal
    {
        foreach ($lines as $line) {
            if (preg_match('/skonto/iu', $line['text']) === 1) {
                continue;
            }
            if (preg_match('/zahlbar\s+(?:bis|zum|spätestens)|fällig\s+(?:am|bis|zum)|fälligkeit|zahlungsziel|due date|due on|payable by|bitte\s.*\bbis\b/iu', $line['text']) === 1) {
                $dates = self::dates($line['text']);
                if ($dates !== []) {
                    $date = end($dates);

                    return InvoiceLines::proposal($date[0], 0.85, $line, InvoiceLines::box($line, $date[1], $date[2]));
                }
            }
        }
        if ($invoiceDate === null) {
            return null;
        }
        foreach ($lines as $line) {
            $skonto = preg_match('/skonto/iu', $line['text']) === 1;
            $pattern = $skonto
                ? '/(\d{1,3})\s*tage?n?\s*(?:rein\s*)?netto/iu'
                : '/(?:zahlbar|zahlungsziel|binnen|innerhalb|within|payable)\D{0,25}?(\d{1,3})\s*(?:tage?n?|days)/iu';
            if (preg_match($pattern, $line['text'], $m, PREG_OFFSET_CAPTURE) === 1 && (int) $m[1][0] > 0 && (int) $m[1][0] <= 365) {
                return InvoiceLines::proposal(self::plusDays($invoiceDate->value, (int) $m[1][0]), 0.75, $line, InvoiceLines::box($line, $m[0][1], $m[0][1] + strlen($m[0][0])));
            }
            if (! $skonto && preg_match('/zahlbar sofort|sofort zahlbar|sofort fällig|due immediately/iu', $line['text']) === 1) {
                return InvoiceLines::proposal($invoiceDate->value, 0.6, $line);
            }
        }

        return null;
    }
}

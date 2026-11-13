<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use App\Domain\Ocr\InvoiceProposal;

/**
 * Skonto (A237): "3 % Skonto bei Zahlung bis 20.11.2026" or "innerhalb 10 Tagen 2 % Skonto" → percent and date.
 *
 * @phpstan-import-type Line from InvoiceLines
 */
final class SkontoReader
{
    /**
     * A237: "3 % Skonto bei Zahlung bis 20.11.2026" or "innerhalb 10 Tagen 2 % Skonto" → percent and date.
     *
     * @param  list<Line>  $lines
     * @return array{InvoiceProposal|null, InvoiceProposal|null}
     */
    public static function read(array $lines, ?InvoiceProposal $invoiceDate): array
    {
        foreach ($lines as $line) {
            if (preg_match('/skonto/iu', $line['text']) !== 1 || preg_match('/(\d{1,2}(?:[.,]\d{1,2})?)\s*%/u', $line['text'], $p, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $value = str_replace('.', ',', $p[1][0]);
            if ((float) str_replace(',', '.', $value) <= 0 || (float) str_replace(',', '.', $value) >= 30) {
                continue;
            }
            $percent = InvoiceLines::proposal($value, 0.8, $line, InvoiceLines::box($line, $p[0][1], $p[0][1] + strlen($p[0][0])));
            $until = null;
            $dates = DateReader::dates($line['text']);
            if ($dates !== []) {
                $until = InvoiceLines::proposal($dates[0][0], 0.8, $line, InvoiceLines::box($line, $dates[0][1], $dates[0][2]));
            } elseif ($invoiceDate !== null && preg_match('/(?:innerhalb|binnen|within)?\s*(?:von\s*)?(\d{1,3})\s*(?:tage?n?|days)/iu', $line['text'], $d, PREG_OFFSET_CAPTURE) === 1 && (int) $d[1][0] > 0 && (int) $d[1][0] <= 120) {
                $until = InvoiceLines::proposal(DateReader::plusDays($invoiceDate->value, (int) $d[1][0]), 0.75, $line, InvoiceLines::box($line, $d[0][1], $d[0][1] + strlen($d[0][0])));
            }

            return [$percent, $until];
        }

        return [null, null];
    }
}

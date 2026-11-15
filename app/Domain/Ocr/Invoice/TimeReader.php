<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use App\Domain\Ocr\InvoiceProposal;

/**
 * Point 219: the time a receipt was printed ("09:38", "9:38 Uhr", "09:38:12") as "H:i" – the bank's booking time is
 * compared with it when matching. On the line of the invoice date first, then on a line labelled Uhrzeit/Zeit/Time,
 * then right above or below the date; opening hours ("Mo–Fr 08:00–20:00") and ranges never count.
 *
 * @phpstan-import-type Line from InvoiceLines
 */
final class TimeReader
{
    private const TIME = '/(?<![\d:.,\-–])([01]?\d|2[0-3])[:.]([0-5]\d)(?::[0-5]\d)?(?![\d:,]|\.\d)(?!\s*(?:[-–]|bis)\s*\d)/u';

    private const SKIP = '/öffnungszeit|geöffnet|oeffnungszeit|opening|\bmo\b.{0,4}\b(?:fr|sa)\b|bis\s+\d{1,2}[:.]\d{2}/iu';

    /**
     * The times on a line as "H:i" with their byte offsets (a dot only with "Uhr" behind it: "9.38" is an amount).
     *
     * @return list<array{string, int, int}>
     */
    public static function times(string $text): array
    {
        if (preg_match(self::SKIP, $text) === 1 || preg_match_all(self::TIME, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $out = [];
        foreach ($matches as $m) {
            $end = $m[0][1] + strlen($m[0][0]);
            if (str_contains($m[0][0], '.') && preg_match('/^\s*uhr\b/iu', substr($text, $end)) !== 1) {
                continue;
            }
            $out[] = [sprintf('%02d:%02d', (int) $m[1][0], (int) $m[2][0]), $m[0][1], $end];
        }

        return $out;
    }

    /**
     * @param  list<Line>  $lines
     */
    public static function read(array $lines, ?InvoiceProposal $invoiceDate, string $order = 'dmy'): ?InvoiceProposal
    {
        $dateLines = [];
        foreach ($lines as $n => $line) {
            foreach (DateReader::dates($line['text'], $order) as $date) {
                if ($invoiceDate === null || $date[0] === $invoiceDate->value) {
                    $dateLines[] = $n;
                    break;
                }
            }
        }

        foreach ($dateLines as $n) {
            if (($found = self::first($lines[$n], 0.8)) !== null) {
                return $found;
            }
        }
        foreach ($lines as $line) {
            if (preg_match('/\b(?:uhrzeit|zeit|time)\b/iu', $line['text']) === 1 && ($found = self::first($line, 0.75)) !== null) {
                return $found;
            }
        }
        foreach ($dateLines as $n) {
            foreach ([$n + 1, $n - 1] as $near) {
                if (isset($lines[$near]) && $lines[$near]['page'] === $lines[$n]['page'] && ($found = self::first($lines[$near], 0.6)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  Line  $line
     */
    private static function first(array $line, float $confidence): ?InvoiceProposal
    {
        $times = self::times($line['text']);

        return $times === [] ? null : InvoiceLines::proposal($times[0][0], $confidence, $line, InvoiceLines::box($line, $times[0][1], $times[0][2]));
    }
}

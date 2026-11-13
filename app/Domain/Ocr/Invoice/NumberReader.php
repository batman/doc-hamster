<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use App\Domain\Ocr\InvoiceProposal;

/**
 * Invoice and customer number (point 120.3): the value right after its label, or the first token of the next line.
 *
 * @phpstan-import-type Line from InvoiceLines
 */
final class NumberReader
{
    public const string INVOICE = '(?:rechnungs?[\s-]*(?:nr|nummer|no)\b\.?|invoice\s*(?:no\b\.?|number|#)|beleg[\s-]*(?:nr\b\.?|nummer))';

    public const string CUSTOMER = '(?:kunden[\s-]*(?:nr|nummer|no)\b\.?|kd[\s.-]*nr\b\.?|customer\s*(?:no\b\.?|number|id))';

    /**
     * A value right after its label ("Rechnungs-Nr.: RE-2026-001"), or the first token of the next line (tables).
     *
     * @param  list<Line>  $lines
     */
    public static function labelled(array $lines, string $label): ?InvoiceProposal
    {
        $value = '([A-Z0-9][A-Z0-9\-\/._]{0,30}[A-Z0-9]|\d)';
        foreach ($lines as $n => $line) {
            if (preg_match('/'.$label.'/iu', $line['text']) !== 1) {
                continue;
            }
            if (preg_match('/'.$label.'\s*[:#.]?\s*'.$value.'/iu', $line['text'], $m, PREG_OFFSET_CAPTURE) === 1 && preg_match('/\d/', $m[1][0]) === 1) {
                return InvoiceLines::proposal($m[1][0], 0.85, $line, InvoiceLines::box($line, $m[1][1], $m[1][1] + strlen($m[1][0])));
            }
            $next = $lines[$n + 1] ?? null;
            if ($next !== null && $next['page'] === $line['page'] && preg_match('/^'.$value.'(?=\s|$)/iu', $next['text'], $m, PREG_OFFSET_CAPTURE) === 1 && preg_match('/\d/', $m[1][0]) === 1) {
                return InvoiceLines::proposal($m[1][0], 0.6, $next, InvoiceLines::box($next, $m[1][1], $m[1][1] + strlen($m[1][0])));
            }
        }

        return null;
    }
}

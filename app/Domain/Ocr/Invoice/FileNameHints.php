<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use App\Domain\Ocr\InvoiceProposal;
use App\Domain\Properties\PropertyValues;
use Carbon\CarbonImmutable;

/**
 * A43, A44: second opinions on what the text recognition read. Many files carry date and amount in their name
 * ("2025-03-14 Fahrzeugkosten Muster 120.0 1234….pdf" from a former bookkeeping export) – the name was typed by a person
 * and wins over a misread. And a date far before the upload or after it is implausible (2005 for 2025): it stays a
 * proposal, never sure.
 */
final class FileNameHints
{
    /** Agreeing with the file name makes a value sure; a file name alone is a fair proposal. */
    public const AGREE = 0.95;

    public const NAME = 0.7;

    /** Below the store threshold of a sure value: the person checks it. */
    public const DOUBT = 0.4;

    /**
     * The ISO date in a file name ("2025-10-30 …", "… 2026-11-01.pdf") – a dotted date is the print date, not taken.
     */
    public static function date(string $name): ?string
    {
        if (preg_match_all('/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/', $name, $m, PREG_SET_ORDER) !== 1) {
            return null;
        }
        [, $y, $mo, $d] = $m[0];

        return checkdate((int) $mo, (int) $d, (int) $y) ? "$y-$mo-$d" : null;
    }

    /**
     * The one decimal amount in a file name ("120.0", "99.5", "1234.56") in minor units – none or several: null.
     */
    public static function amount(string $name): ?int
    {
        $name = (string) preg_replace(['/\.[A-Za-z][A-Za-z0-9]{1,4}$/', '/(?<!\d)\d{4}-\d{2}-\d{2}(?!\d)/'], ['', ' '], $name);
        if (preg_match_all('/(?<![\d.,\-\/])(\d{1,7})[.,](\d{1,2})(?![\d.,\-\/])/', $name, $m, PREG_SET_ORDER) !== 1) {
            return null;
        }

        return (int) $m[0][1] * 100 + (int) str_pad($m[0][2], 2, '0');
    }

    /** A43: an invoice date more than ten years before the upload or after it is a misread. */
    public static function implausible(string $date, CarbonImmutable $uploaded): bool
    {
        $day = CarbonImmutable::createFromFormat('Y-m-d', $date);

        return $day === null || $day->lt($uploaded->subYears(10)) || $day->gt($uploaded->addDay());
    }

    /**
     * The reading's date and amount checked against the file name and the upload day.
     *
     * @param  array<string, InvoiceProposal>  $found  InvoiceReading::suggestions()
     * @return array<string, InvoiceProposal>
     */
    public static function apply(array $found, string $name, CarbonImmutable $uploaded, string $currency): array
    {
        $date = self::date($name);
        $read = $found['invoice_date'] ?? null;
        if ($date !== null && ! self::implausible($date, $uploaded)) {
            $found['invoice_date'] = $read !== null && $read->value === $date
                ? self::with($read, max($read->confidence, self::AGREE))
                : new InvoiceProposal($date, self::NAME);
        } elseif ($read !== null && self::implausible($read->value, $uploaded)) {
            $found['invoice_date'] = self::with($read, min($read->confidence, self::DOUBT));
        }

        $minor = self::amount($name);
        $amount = $found['invoice_amount'] ?? null;
        if ($minor !== null && $minor > 0) {
            $found['invoice_amount'] = $amount !== null && $amount->minor === $minor
                ? self::with($amount, max($amount->confidence, self::AGREE))
                : new InvoiceProposal(PropertyValues::formatAmount($minor, $amount->currency ?? $currency), self::NAME, minor: $minor, currency: $amount->currency ?? $currency);
        }

        return $found;
    }

    private static function with(InvoiceProposal $p, float $confidence): InvoiceProposal
    {
        return new InvoiceProposal($p->value, $confidence, $p->page, $p->box, $p->minor, $p->currency, $p->original, $p->keyword);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Payments\InvoiceSuggestions;
use DateTimeImmutable;

/**
 * How well a booking and a document belong together, 0–100 (A268, PLAN §9.2 for banks without times). Pure: plain
 * values in, score and reasons out.
 *
 * - Amount (40): the document's amount equals the booking's, compared as absolute values – a payment is negative on
 *   the statement, the invoice positive (and an incoming payment matches an outgoing invoice the same way). ±1 % gives 20
 *   (rounding, tip, exchange rate); an amount the reader only saw on the document (no property) gives 30 when equal.
 * - Date (15): booking on the due date, a few days after the invoice date, or near the upload day.
 * - IBAN (25): the document's IBAN is the counterparty's, or stands in the purpose.
 * - Invoice number (25): stands in the purpose.
 * - Sender (20): the sender's name words stand in counterparty or purpose.
 *
 * At most 100. AUTO and above, with no close second, links as "vorgeschlagen"; SUGGEST and above is offered.
 */
final class MatchScore
{
    public const AUTO = 80;

    public const SUGGEST = 40;

    /** The best must lead the second by this much to be linked on its own (PLAN §9.2). */
    public const UNIQUE_GAP = 15;

    /**
     * @param  array{date: string, amount: int, orig_amount?: ?int, counterparty?: ?string, purpose?: ?string, iban?: ?string}  $tx
     * @param  array{amount?: ?int, amounts?: list<int>, invoice_date?: ?string, due_date?: ?string, uploaded?: ?string, iban?: ?string, sender?: ?string, invoice_number?: ?string}  $doc
     * @return array{score: int, reasons: array<string, int>}
     */
    public static function score(array $tx, array $doc): array
    {
        $reasons = array_filter([
            // A291: a payment in another currency matches its invoice by the original amount.
            'amount' => max(self::amount(abs($tx['amount']), $doc), ($tx['orig_amount'] ?? null) === null ? 0 : self::amount(abs((int) $tx['orig_amount']), $doc)),
            'date' => self::date($tx['date'], $doc),
            'iban' => self::iban($tx, $doc['iban'] ?? null),
            'invoice_number' => self::invoiceNumber($tx['purpose'] ?? null, $doc['invoice_number'] ?? null),
            'sender' => self::sender(trim(($tx['counterparty'] ?? '').' '.($tx['purpose'] ?? '')), $doc['sender'] ?? null),
        ]);

        return ['score' => min(100, array_sum($reasons)), 'reasons' => $reasons];
    }

    /**
     * Cheap pre-check before scoring many pairs: an amount within 1 %, the same IBAN, or – a document without
     * amount – a date within a week.
     *
     * @param  array{date: string, amount: int, orig_amount?: ?int, counterparty?: ?string, purpose?: ?string, iban?: ?string}  $tx
     * @param  array{amount?: ?int, amounts?: list<int>, invoice_date?: ?string, due_date?: ?string, uploaded?: ?string, iban?: ?string, sender?: ?string, invoice_number?: ?string}  $doc
     */
    public static function worthScoring(array $tx, array $doc): bool
    {
        $amounts = self::amounts($doc);
        foreach ($amounts as $amount) {
            if (self::near(abs($tx['amount']), abs($amount)) || (($tx['orig_amount'] ?? null) !== null && self::near(abs((int) $tx['orig_amount']), abs($amount)))) {
                return true;
            }
        }
        if (($doc['iban'] ?? null) !== null && ($tx['iban'] ?? null) === $doc['iban']) {
            return true;
        }

        return $amounts === [] && self::date($tx['date'], $doc) >= 8;
    }

    /**
     * @param  array{amount?: ?int, amounts?: list<int>}  $doc
     */
    private static function amount(int $paid, array $doc): int
    {
        $main = $doc['amount'] ?? null;
        if ($main !== null) {
            if (abs($main) === $paid) {
                return 40;
            }
            if (self::near($paid, abs($main))) {
                return 20;
            }
        }
        foreach ($doc['amounts'] ?? [] as $other) {
            if (abs($other) === $paid && $paid > 0) {
                return 30;
            }
        }

        return 0;
    }

    /**
     * @param  array{amount?: ?int, amounts?: list<int>}  $doc
     * @return list<int>
     */
    private static function amounts(array $doc): array
    {
        return array_values(array_filter([$doc['amount'] ?? null, ...($doc['amounts'] ?? [])], static fn (?int $a): bool => $a !== null && $a !== 0));
    }

    private static function near(int $a, int $b): bool
    {
        return $a > 0 && $b > 0 && abs($a - $b) <= max(1, intdiv(max($a, $b), 100));
    }

    /**
     * @param  array{invoice_date?: ?string, due_date?: ?string, uploaded?: ?string}  $doc
     */
    private static function date(string $booked, array $doc): int
    {
        $points = 0;
        $due = self::days($doc['due_date'] ?? null, $booked);
        if ($due !== null) {
            $points = max($points, match (true) {
                abs($due) <= 3 => 15,
                abs($due) <= 10 => 8,
                default => 0,
            });
        }
        // Paid on the invoice day (card, cash desk) or some days later (transfer).
        $invoice = self::days($doc['invoice_date'] ?? null, $booked);
        if ($invoice !== null) {
            $points = max($points, match (true) {
                abs($invoice) <= 1 => 15,
                $invoice >= -3 && $invoice <= 14 => 10,
                $invoice > 14 && $invoice <= 45 => 5,
                default => 0,
            });
        }
        // The photo is usually taken right after paying.
        $upload = self::days($booked, $doc['uploaded'] ?? null);
        if ($upload !== null) {
            $points = max($points, match (true) {
                abs($upload) === 0 => 15,
                abs($upload) <= 3 => 8,
                abs($upload) <= 7 => 3,
                default => 0,
            });
        }

        return $points;
    }

    /** Days from $from to $to (positive: $to is later); null when one is missing. */
    private static function days(?string $from, ?string $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', substr($from, 0, 10));
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', substr($to, 0, 10));
        if ($a === false || $b === false) {
            return null;
        }

        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /**
     * @param  array{counterparty?: ?string, purpose?: ?string, iban?: ?string}  $tx
     */
    private static function iban(array $tx, ?string $iban): int
    {
        if ($iban === null || $iban === '') {
            return 0;
        }
        if (($tx['iban'] ?? null) === $iban) {
            return 25;
        }
        $text = strtoupper((string) preg_replace('/\s+/', '', ($tx['counterparty'] ?? '').($tx['purpose'] ?? '')));

        return str_contains($text, $iban) ? 25 : 0;
    }

    private static function invoiceNumber(?string $purpose, ?string $number): int
    {
        $number = self::compact($number);
        if ($purpose === null || strlen($number) < 4 || preg_match('/\d/', $number) !== 1) {
            return 0;
        }

        return str_contains(self::compact($purpose), $number) ? 25 : 0;
    }

    private static function sender(string $text, ?string $sender): int
    {
        if ($sender === null || $text === '') {
            return 0;
        }
        $words = array_values(array_filter(explode(' ', InvoiceSuggestions::senderKey($sender)), static fn (string $w): bool => mb_strlen($w) >= 3));
        if ($words === []) {
            return 0;
        }
        $haystack = ' '.InvoiceSuggestions::senderKey($text).' ';
        $found = count(array_filter($words, static fn (string $w): bool => str_contains($haystack, ' '.$w.' ')));
        $share = $found / count($words);

        return $share >= 0.5 ? (int) round(20 * $share) : 0;
    }

    private static function compact(?string $text): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $text));
    }
}

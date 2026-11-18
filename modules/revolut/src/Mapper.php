<?php

declare(strict_types=1);

namespace DocHamsterModules\Revolut;

use Illuminate\Support\Carbon;

/**
 * A Revolut transaction → one booking line per leg (each leg belongs to one account; an exchange has two), in the shape
 * of a statement line (ModuleBanking / StatementImport). booking_id is Revolut's transaction id – the same value the
 * Revolut CSV export has in its column "ID" – so bookings imported by CSV before are recognised, not doubled. The type
 * goes into kind in the CSV's spelling (CARD_PAYMENT …), so the row icons fit.
 */
final class Mapper
{
    /**
     * @param  array<string, mixed>  $t
     * @return array<string, array<string, mixed>> Revolut account id => line
     */
    public static function lines(array $t): array
    {
        $out = [];
        $at = Carbon::parse((string) ($t['completed_at'] ?? $t['created_at'] ?? 'now'))->timezone((string) config('app.timezone'));
        $merchant = is_array($t['merchant'] ?? null) ? $t['merchant'] : [];
        $card = is_array($t['card'] ?? null) ? $t['card'] : [];
        $digits = (string) preg_replace('/\D/', '', (string) ($card['card_number'] ?? ''));
        foreach ((array) ($t['legs'] ?? []) as $leg) {
            if (! is_array($leg) || ! is_string($leg['account_id'] ?? null)) {
                continue;
            }
            $currency = strtoupper((string) ($leg['currency'] ?? ''));
            $bill = strtoupper((string) ($leg['bill_currency'] ?? ''));
            $description = self::text($leg['description'] ?? null, 255);
            $line = [
                'booking_date' => $at->toDateString(),
                'booked_time' => $at->format('H:i'),
                'amount_minor' => self::minor($leg['amount'] ?? 0),
                'currency' => $currency !== '' ? $currency : null,
                'counterparty' => self::text($merchant['name'] ?? null, 255) ?? self::party($description),
                'purpose' => self::text($t['reference'] ?? null, 1000) ?? $description,
                'counterparty_iban' => null,
                'counterparty_bic' => null,
                'fingerprint' => hash('sha256', 'revolut|'.($t['id'] ?? '').'|'.($leg['leg_id'] ?? $leg['account_id'])),
                'booking_id' => self::text($t['id'] ?? null, 100),
                'value_date' => null,
                'purpose2' => $description !== null && $description !== self::text($t['reference'] ?? null, 1000) ? $description : null,
                'kind' => self::text(strtoupper((string) ($t['type'] ?? '')), 60),
                'reference' => self::text($t['reference'] ?? null, 255),
                'creditor_id' => null,
                'orig_amount_minor' => $bill !== '' && $bill !== $currency && isset($leg['bill_amount']) ? self::minor($leg['bill_amount']) * (self::minor($leg['amount'] ?? 0) < 0 ? -1 : 1) : null,
                'orig_currency' => $bill !== '' && $bill !== $currency ? $bill : null,
                'fee_minor' => isset($leg['fee']) && (float) $leg['fee'] != 0.0 ? self::minor($leg['fee']) : null,
                'fee_currency' => isset($leg['fee']) && (float) $leg['fee'] != 0.0 ? $currency : null,
                'balance_minor' => isset($leg['balance']) ? self::minor($leg['balance']) : null,
                'category' => null,
                'card_last4' => strlen($digits) >= 4 ? substr($digits, -4) : null,
                'bank_state' => self::text(strtolower((string) ($t['state'] ?? '')), 30),
                'sub_account' => null,
                'related_id' => self::text($t['related_transaction_id'] ?? null, 100),
                'mcc' => is_string($merchant['category_code'] ?? null) && strlen($merchant['category_code']) === 4 ? $merchant['category_code'] : null,
                'extra' => array_filter(['city' => $merchant['city'] ?? null, 'country' => $merchant['country'] ?? null, 'type' => $t['type'] ?? null], static fn ($v): bool => is_string($v) && $v !== '') ?: null,
                'raw' => null,
            ];
            $out[$leg['account_id']] = $line;
        }

        return $out;
    }

    /** Amounts as minor units (cents), like every amount in docHamster. */
    public static function minor(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** "To Max Mustermann" / "Payment from Muster GmbH" → the name. */
    private static function party(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $name = trim((string) preg_replace('/^(to|from|payment from|transfer to|transfer from|an|von)\s+/i', '', $description));

        return $name !== '' ? $name : $description;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }
}

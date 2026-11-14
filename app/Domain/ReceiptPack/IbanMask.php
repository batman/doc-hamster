<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

/**
 * An IBAN as the receipt pack prints it (point 211.1): country and check digits and the last four characters stay,
 * everything between becomes "*", in groups of four – "DE89 **** **** **** **30 00". Short or empty values give "".
 */
final class IbanMask
{
    public static function mask(?string $iban): string
    {
        $plain = strtoupper((string) preg_replace('/\s+/', '', (string) $iban));
        if (strlen($plain) < 9) {
            return '';
        }
        $masked = substr($plain, 0, 4).str_repeat('*', strlen($plain) - 8).substr($plain, -4);

        return implode(' ', str_split($masked, 4));
    }
}

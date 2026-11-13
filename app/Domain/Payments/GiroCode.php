<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;

/**
 * GiroCode (point 120, EPC069-12 "SEPA Credit Transfer" QR code, version 002, UTF-8), made locally as SVG (D-020:
 * no external service). Only euros; the banking app reads payee, IBAN, BIC, amount and the unstructured
 * remittance ("Verwendungszweck", at most 140 characters).
 */
final class GiroCode
{
    public const MAX_NAME = 70;

    public const MAX_REMITTANCE = 140;

    /** EPC: 0.01 to 999 999 999.99 euros. */
    public const MAX_MINOR = 99_999_999_999;

    /**
     * The EPC payload: BCD / 002 / 1 (UTF-8) / SCT / BIC / name / IBAN / EUR123.45 / purpose / structured / text.
     */
    public static function payload(string $name, string $iban, ?string $bic, int $minor, string $reference = ''): string
    {
        return implode("\n", [
            'BCD',
            '002',
            '1',
            'SCT',
            strtoupper(trim((string) $bic)),
            self::clip($name, self::MAX_NAME),
            strtoupper((string) preg_replace('/\s+/', '', $iban)),
            self::amount($minor),
            '',
            '',
            self::clip($reference, self::MAX_REMITTANCE),
        ]);
    }

    /** 12345 → "EUR123.45". */
    public static function amount(int $minor): string
    {
        return 'EUR'.intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** The Verwendungszweck made from invoice and customer number: "Rechnung RE-1, Kunde 4711". */
    public static function reference(?string $invoiceNumber, ?string $customerNumber): string
    {
        $parts = [];
        if ($invoiceNumber !== null && trim($invoiceNumber) !== '') {
            $parts[] = __('girocode.reference_invoice', ['number' => trim($invoiceNumber)]);
        }
        if ($customerNumber !== null && trim($customerNumber) !== '') {
            $parts[] = __('girocode.reference_customer', ['number' => trim($customerNumber)]);
        }

        return self::clip(implode(', ', $parts), self::MAX_REMITTANCE);
    }

    /** A237: the amount less the Skonto (percent ×10000 like a number property), rounded to the cent. */
    public static function discounted(int $minor, int $percent): int
    {
        return (int) round($minor * (1 - $percent / 1_000_000));
    }

    /** The QR code as SVG (error correction M, no ECI header: some banking apps stumble over it). */
    public static function svg(string $payload, int $size = 240): string
    {
        $code = Encoder::encode($payload, ErrorCorrectionLevel::M(), 'UTF-8', null, false);

        return (new ImageRenderer(new RendererStyle($size), new SvgImageBackEnd))->render($code);
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, $max);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

/**
 * One account of a receipt pack (point 211.2): a bank account or the cash box, each its own section.
 */
final readonly class PackSection
{
    /**
     * @param  list<PackBooking>  $bookings  in date order
     */
    public function __construct(
        public string $accountId,
        public string $name,
        public string $iban,
        public string $kind,
        public string $currency,
        public array $bookings = [],
    ) {}

    public function figures(): PackFigures
    {
        return PackFigures::of($this->bookings);
    }

    /** "DE89 **** … 30 00", or "" for a cash box or an account without IBAN. */
    public function maskedIban(): string
    {
        return IbanMask::mask($this->iban);
    }
}

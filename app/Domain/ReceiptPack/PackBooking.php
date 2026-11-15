<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

use Carbon\CarbonImmutable;

/**
 * One booking of a receipt pack, already read from the database: what the overview row and its own page show.
 * `receipts` are the confirmed linked documents; "kein Beleg nötig" bookings are never missing. `time`: the booking's
 * time of day when the bank gave one ("H:i", point 219).
 */
final readonly class PackBooking
{
    /**
     * @param  list<string>  $tags
     * @param  list<PackReceipt>  $receipts
     */
    public function __construct(
        public string $id,
        public CarbonImmutable $date,
        public int $amountMinor,
        public string $currency,
        public string $counterparty,
        public string $purpose,
        public array $tags = [],
        public array $receipts = [],
        public bool $noReceipt = false,
        public ?string $noReceiptReason = null,
        public ?string $time = null,
    ) {}

    public function hasReceipt(): bool
    {
        return $this->receipts !== [];
    }

    public function isMissing(): bool
    {
        return ! $this->hasReceipt() && ! $this->noReceipt;
    }
}

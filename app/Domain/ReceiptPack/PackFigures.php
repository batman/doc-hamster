<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

/**
 * The key figures of a receipt pack or one of its accounts (point 211.1): bookings, with receipt, "kein Beleg nötig",
 * expenses and income (positive minor units per currency) and the bookings whose receipt is missing.
 */
final readonly class PackFigures
{
    /**
     * @param  array<string, int>  $expenses  currency => minor units, positive
     * @param  array<string, int>  $income  currency => minor units
     * @param  list<PackBooking>  $missing
     */
    public function __construct(
        public int $count,
        public int $withReceipt,
        public int $noReceipt,
        public array $expenses,
        public array $income,
        public array $missing,
    ) {}

    /**
     * @param  list<PackBooking>  $bookings
     */
    public static function of(array $bookings): self
    {
        $expenses = [];
        $income = [];
        $missing = [];
        $with = 0;
        $none = 0;
        foreach ($bookings as $booking) {
            if ($booking->amountMinor < 0) {
                $expenses[$booking->currency] = ($expenses[$booking->currency] ?? 0) - $booking->amountMinor;
            } elseif ($booking->amountMinor > 0) {
                $income[$booking->currency] = ($income[$booking->currency] ?? 0) + $booking->amountMinor;
            }
            $with += $booking->hasReceipt() ? 1 : 0;
            $none += ! $booking->hasReceipt() && $booking->noReceipt ? 1 : 0;
            if ($booking->isMissing()) {
                $missing[] = $booking;
            }
        }
        ksort($expenses);
        ksort($income);

        return new self(count($bookings), $with, $none, $expenses, $income, $missing);
    }

    /** All sections together (the cover's totals). */
    public static function total(PackSection ...$sections): self
    {
        $bookings = [];
        foreach ($sections as $section) {
            array_push($bookings, ...$section->bookings);
        }

        return self::of($bookings);
    }

    public function missingCount(): int
    {
        return count($this->missing);
    }
}

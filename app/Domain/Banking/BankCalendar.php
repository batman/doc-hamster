<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The account window's calendar (159.2, A332–A334): months only between the first and the last month with bookings
 * (like the Dokumente calendar, point 107), each day with its bookings, its sum and whether one still lacks a document.
 */
final class BankCalendar
{
    /** Bookings shown per day before "+N weitere" (A332). */
    public const PER_DAY = 3;

    /**
     * The first and the last month with bookings of the (filtered) query; null without bookings.
     *
     * @param  Builder<Transaction>  $query
     * @return array{first: CarbonImmutable, last: CarbonImmutable}|null
     */
    public static function range(Builder $query): ?array
    {
        $lo = $query->clone()->min('booking_date');
        $hi = $query->clone()->max('booking_date');
        if (! is_string($lo) || ! is_string($hi)) {
            return null;
        }

        return ['first' => CarbonImmutable::parse($lo)->startOfMonth(), 'last' => CarbonImmutable::parse($hi)->startOfMonth()];
    }

    /**
     * The month to show: the wanted one (?month=YYYY-MM) inside the range, else this month when it lies inside, else the
     * last month with bookings.
     *
     * @param  array{first: CarbonImmutable, last: CarbonImmutable}|null  $range
     */
    public static function month(mixed $wanted, ?array $range, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now = ($now ?? CarbonImmutable::now())->startOfMonth();
        $parsed = is_string($wanted) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $wanted) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $wanted) : null;
        if ($range === null) {
            return $parsed instanceof CarbonImmutable ? $parsed : $now;
        }
        $month = $parsed instanceof CarbonImmutable ? $parsed : ($now->between($range['first'], $range['last']) ? $now : $range['last']);

        return $month->lessThan($range['first']) ? $range['first'] : ($month->greaterThan($range['last']) ? $range['last'] : $month);
    }

    /**
     * The bookings by day of the month: the first PER_DAY of them, how many more, the day's sum (in the account's
     * currency) and whether one is still open (A334). Needs the status counts (withStatusCounts).
     *
     * @param  iterable<Transaction>  $transactions
     * @return array<int, array{entries: list<Transaction>, more: int, sum: int, open: bool}>
     */
    public static function days(iterable $transactions, string $currency): array
    {
        $days = [];
        foreach ($transactions as $transaction) {
            $day = (int) $transaction->booking_date->format('j');
            $days[$day] ??= ['entries' => [], 'more' => 0, 'sum' => 0, 'open' => false];
            if (count($days[$day]['entries']) < self::PER_DAY) {
                $days[$day]['entries'][] = $transaction;
            } else {
                $days[$day]['more']++;
            }
            if ($transaction->currency === $currency) {
                $days[$day]['sum'] += $transaction->amount_minor;
            }
            $days[$day]['open'] = $days[$day]['open'] || $transaction->status() === Transaction::OPEN;
        }

        return $days;
    }
}

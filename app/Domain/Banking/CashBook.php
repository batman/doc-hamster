<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Point 190, the Bargeldkasse: an account of kind "cash" whose bookings are typed in by hand (source "manual"). They
 * are normal transactions, so linking documents, the red counts and "kein Beleg nötig" work as for a bank. The running
 * balance (opening balance plus every entry up to it, by date and then in the order they were typed) is kept in
 * `balance_minor` like a bank's saldo after each booking.
 */
final class CashBook
{
    /**
     * The balance after each entry.
     *
     * @param  iterable<string, int>  $amounts  entry id → amount in minor units, in booking order
     * @return array<string, int>
     */
    public static function running(int $opening, iterable $amounts): array
    {
        $balance = $opening;
        $after = [];
        foreach ($amounts as $id => $amount) {
            $balance += $amount;
            $after[$id] = $balance;
        }

        return $after;
    }

    /** Writes the running balance onto every entry of the cash account and returns the current balance. */
    public static function rebalance(BankAccount $account): int
    {
        $entries = Transaction::query()->where('bank_account_id', $account->id)->orderBy('booking_date')->orderBy('id')->get(['id', 'amount_minor', 'balance_minor']);
        $after = self::running($account->opening_balance_minor, $entries->pluck('amount_minor', 'id')->map(static fn ($a): int => (int) $a));

        DB::transaction(static function () use ($entries, $after): void {
            foreach ($entries as $entry) {
                if ($entry->balance_minor !== $after[$entry->id]) {
                    Transaction::query()->whereKey($entry->id)->update(['balance_minor' => $after[$entry->id]]);
                }
            }
        });

        return $after === [] ? $account->opening_balance_minor : (int) end($after);
    }

    /** The current balance: opening balance plus all entries. */
    public static function balance(BankAccount $account): int
    {
        return $account->opening_balance_minor + (int) Transaction::query()->where('bank_account_id', $account->id)->sum('amount_minor');
    }

    public static function add(BankAccount $account, string $date, int $amount, string $text): Transaction
    {
        $entry = Transaction::query()->create([
            'bank_account_id' => $account->id,
            'booking_date' => $date,
            'amount_minor' => $amount,
            'currency' => $account->currency,
            'purpose' => $text,
            'source' => Transaction::MANUAL,
            // Unique per account like an imported row's; a hand entry has nothing to dedupe against.
            'fingerprint' => hash('sha256', 'manual|'.Str::ulid()),
        ]);
        self::rebalance($account);

        return $entry;
    }

    public static function change(Transaction $entry, string $date, int $amount, string $text): void
    {
        $entry->update(['booking_date' => $date, 'amount_minor' => $amount, 'purpose' => $text]);
        self::rebalance($entry->account);
    }

    public static function remove(Transaction $entry): void
    {
        $account = $entry->account;
        $entry->delete();
        self::rebalance($account);
    }
}

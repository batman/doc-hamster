<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use Illuminate\Support\Facades\DB;

/**
 * Turns the rows of a statement into transactions. The fingerprint (PLAN §9.1)
 * counts identical rows of a file, so two equal coffees on one day stay two
 * bookings and an overlapping re-import still adds nothing.
 */
final class StatementImport
{
    /**
     * @param  array{date: int, amount: int, counterparty: int|null, purpose: int|null}  $columns
     * @return array{lines: list<array{booking_date: string, amount_minor: int, counterparty: string|null, purpose: string|null, fingerprint: string}>, unreadable: int}
     */
    public static function read(CsvStatement $statement, array $columns): array
    {
        $lines = [];
        $unreadable = 0;
        $seen = [];

        foreach ($statement->rows as $row) {
            $date = CsvStatement::normaliseDate($row[$columns['date']]);
            $amount = CsvStatement::amountToMinor($row[$columns['amount']]);

            if ($date === null || $amount === null) {
                $unreadable++;

                continue;
            }

            $counterparty = self::text($row, $columns['counterparty'], 255);
            $purpose = self::text($row, $columns['purpose'], 1000);

            $key = implode('|', [$date, $amount, mb_strtolower($counterparty ?? ''), mb_strtolower($purpose ?? '')]);
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            $lines[] = [
                'booking_date' => $date,
                'amount_minor' => $amount,
                'counterparty' => $counterparty,
                'purpose' => $purpose,
                'fingerprint' => hash('sha256', $key.'|'.$seen[$key]),
            ];
        }

        return ['lines' => $lines, 'unreadable' => $unreadable];
    }

    /**
     * Stores what the account doesn't have yet and returns how many rows that were.
     *
     * @param  list<array{booking_date: string, amount_minor: int, counterparty: string|null, purpose: string|null, fingerprint: string}>  $lines
     */
    public static function store(BankAccount $account, array $lines): int
    {
        return DB::transaction(static function () use ($account, $lines): int {
            $known = Transaction::query()->where('bank_account_id', $account->id)->pluck('fingerprint')->flip();
            $new = 0;

            foreach ($lines as $line) {
                if (! $known->has($line['fingerprint'])) {
                    Transaction::query()->create([...$line, 'bank_account_id' => $account->id, 'currency' => $account->currency]);
                    $new++;
                }
            }

            return $new;
        });
    }

    /**
     * @param  list<string>  $row
     */
    private static function text(array $row, ?int $column, int $limit): ?string
    {
        $text = $column === null ? '' : (string) preg_replace('/\s+/u', ' ', $row[$column]);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }
}

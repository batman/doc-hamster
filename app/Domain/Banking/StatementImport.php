<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Properties\PropertyValues;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns the rows of a statement into transactions (A289–A303).
 *
 * Reading: every mapped field, the columns nobody mapped as "weitere Angaben" (A290) and the raw row (143). Amounts
 * keep their sign; where the bank's sign is unreliable it is repaired from the type (a fee or card payment is always
 * money going out) and from the running balance (143, A309).
 *
 * Dedupe on a re-import (A294) in this order: 1) the bank's booking ID, 2) the balance after the booking, 3) the
 * fingerprint – a hash of booking date, amount, counterparty and purpose plus a running number for identical rows of
 * one file (PLAN §9.1, A273). A booking imported before without ID is found by date, amount and counterparty and gets
 * the ID (A303). What none of them finds but looks like an existing booking (same day and amount) is "unklar" and is
 * decided by the person (A295). A known booking only gets its empty fields filled and its bank status updated
 * (pending → completed); links, confirmations and "kein Beleg nötig" stay untouched, a differing filled value is not
 * overwritten but shown as "abweichend" (A302).
 */
final class StatementImport
{
    /** Fields a re-import may fill when they are empty (A302). */
    private const FILLABLE = ['booking_id', 'value_date', 'counterparty', 'purpose', 'purpose2', 'counterparty_iban', 'counterparty_bic', 'kind', 'reference', 'creditor_id',
        'orig_amount_minor', 'orig_currency', 'fee_minor', 'fee_currency', 'balance_minor', 'category', 'card_last4', 'sub_account', 'related_id', 'mcc'];

    /** Of those, the ones whose difference is worth showing ("abweichend"). */
    private const COMPARED = ['counterparty', 'purpose', 'purpose2', 'counterparty_iban', 'reference', 'orig_amount_minor', 'fee_minor', 'balance_minor', 'card_last4', 'mcc'];

    /** Bank types that are always money going out (143). */
    private const OUTGOING_KINDS = ['FEE', 'CARD_PAYMENT', 'ATM', 'CHARGE', 'CARD PAYMENT'];

    /**
     * @param  array<string, int|list<int>|null>  $columns  field → column (StatementColumns)
     * @return array{lines: list<array<string, mixed>>, unreadable: int}
     */
    public static function read(CsvStatement $statement, array $columns): array
    {
        $lines = [];
        $unreadable = 0;
        $seen = [];
        $mapped = [];
        foreach ($columns as $index) {
            foreach ((array) $index as $i) {
                $mapped[$i] = true;
            }
        }

        foreach ($statement->rows as $row) {
            $cell = static function (string $field) use ($row, $columns): string {
                foreach ((array) ($columns[$field] ?? []) as $index) {
                    $value = trim((string) preg_replace('/\s+/u', ' ', $row[$index] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }

                return '';
            };
            $valueDate = CsvStatement::normaliseDate($cell('value_date'));
            $date = CsvStatement::normaliseDate($cell('date')) ?? ($cell('date') === '' ? $valueDate : null);
            $amount = CsvStatement::amountToMinor($cell('amount'));

            if ($date === null || $amount === null) {
                $unreadable++;

                continue;
            }

            $text = static fn (string $field, int $limit): ?string => $cell($field) === '' ? null : mb_substr($cell($field), 0, $limit);
            $money = static fn (string $field): ?int => $cell($field) === '' ? null : CsvStatement::amountToMinor($cell($field));
            $currency = static fn (string $field): ?string => preg_match('/^[A-Za-z]{3}$/', $cell($field)) === 1 ? strtoupper($cell($field)) : null;
            $counterparty = $text('counterparty', 255);
            $purpose = $text('purpose', 1000);
            $iban = $text('iban', 60);
            $bic = strtoupper(str_replace(' ', '', $cell('bic')));
            $card = (string) preg_replace('/\D/', '', $cell('card'));
            $mcc = (string) preg_replace('/\D/', '', $cell('mcc'));

            $extra = [];
            $raw = [];
            foreach ($statement->header as $index => $name) {
                $value = trim($row[$index] ?? '');
                if ($value === '') {
                    continue;
                }
                $raw[$name] = $value;
                if (! isset($mapped[$index])) {
                    $extra[$name] = mb_substr($value, 0, 500);
                }
            }

            $key = implode('|', [$date, $amount, mb_strtolower($counterparty ?? ''), mb_strtolower($purpose ?? '')]);
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            $lines[] = [
                'booking_date' => $date,
                'amount_minor' => $amount,
                'counterparty' => $counterparty,
                'purpose' => $purpose,
                'counterparty_iban' => $iban === null ? null : PropertyValues::parseIban($iban),
                'fingerprint' => hash('sha256', $key.'|'.$seen[$key]),
                'booking_id' => $text('booking_id', 100),
                'value_date' => $valueDate,
                'purpose2' => $text('purpose2', 1000),
                'kind' => $text('kind', 60),
                'reference' => $text('reference', 255),
                'creditor_id' => $text('creditor_id', 40),
                'counterparty_bic' => preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic) === 1 ? $bic : null,
                'orig_amount_minor' => $money('orig_amount'),
                'orig_currency' => $currency('orig_currency'),
                'fee_minor' => $money('fee'),
                'fee_currency' => $currency('fee_currency'),
                'balance_minor' => $money('balance'),
                'currency' => $currency('currency'),
                'category' => $text('category', 100),
                // Never more of a card number than its last four digits.
                'card_last4' => strlen($card) >= 4 ? substr($card, -4) : null,
                'bank_state' => $cell('state') === '' ? null : mb_strtolower(mb_substr($cell('state'), 0, 30)),
                'sub_account' => $text('sub_account', 100),
                'related_id' => $text('related_id', 100),
                'mcc' => strlen($mcc) === 4 ? $mcc : null,
                'extra' => $extra === [] ? null : $extra,
                'raw' => $raw,
            ];
        }

        return ['lines' => self::fixSigns($lines), 'unreadable' => $unreadable];
    }

    /**
     * 143: a fee or card payment is always money going out; where the file has a balance, its change between two
     * neighbouring rows gives the sign (whichever order the file is in). The original amount follows the sign.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function fixSigns(array $lines): array
    {
        foreach ($lines as $i => $line) {
            if ($line['amount_minor'] > 0 && in_array(strtoupper((string) $line['kind']), self::OUTGOING_KINDS, true)) {
                $lines[$i]['amount_minor'] = -$line['amount_minor'];
            }
        }

        $groups = [];
        foreach ($lines as $i => $line) {
            if ($line['balance_minor'] !== null) {
                $groups[$line['sub_account'].'|'.$line['currency']][] = $i;
            }
        }
        foreach ($groups as $rows) {
            $fits = static function (int $delta, array $line): bool {
                $amount = abs((int) $line['amount_minor']);
                $fee = abs((int) ($line['fee_minor'] ?? 0));

                return $delta !== 0 && (abs($delta) === $amount || abs($delta) === $amount + $fee || abs($delta) === abs($amount - $fee));
            };
            $ascending = 0;
            $descending = 0;
            for ($k = 1; $k < count($rows); $k++) {
                $delta = $lines[$rows[$k]]['balance_minor'] - $lines[$rows[$k - 1]]['balance_minor'];
                $ascending += $fits($delta, $lines[$rows[$k]]) ? 1 : 0;
                $descending += $fits(-$delta, $lines[$rows[$k - 1]]) ? 1 : 0;
            }
            if ($ascending === $descending) {
                continue;
            }
            for ($k = 1; $k < count($rows); $k++) {
                [$own, $delta] = $ascending > $descending
                    ? [$rows[$k], $lines[$rows[$k]]['balance_minor'] - $lines[$rows[$k - 1]]['balance_minor']]
                    : [$rows[$k - 1], $lines[$rows[$k - 1]]['balance_minor'] - $lines[$rows[$k]]['balance_minor']];
                $amount = $lines[$own]['amount_minor'];
                if ($fits($delta, $lines[$own]) && ($delta < 0) !== ($amount < 0) && $amount !== 0) {
                    $lines[$own]['amount_minor'] = -$amount;
                }
            }
        }

        foreach ($lines as $i => $line) {
            $orig = $line['orig_amount_minor'];
            if ($orig !== null && $orig !== 0 && ($orig < 0) !== ($line['amount_minor'] < 0)) {
                $lines[$i]['orig_amount_minor'] = -$orig;
            }
        }

        return $lines;
    }

    /**
     * Sorts the lines into new, known and unclear against what the account has (A294, A295, A302, A303).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{line: array<string, mixed>, action: string, existing: string|null, fills: array<string, mixed>, diffs: array<string, array{0: mixed, 1: mixed}>}>
     */
    public static function plan(?BankAccount $account, array $lines): array
    {
        $existing = $account === null ? collect() : Transaction::query()->where('bank_account_id', $account->id)->get();
        $byId = $existing->filter(static fn (Transaction $t): bool => $t->booking_id !== null)->keyBy('booking_id');
        $byFingerprint = $existing->keyBy('fingerprint');
        $byBalance = $existing->filter(static fn (Transaction $t): bool => $t->balance_minor !== null)
            ->keyBy(static fn (Transaction $t): string => $t->booking_date->format('Y-m-d').'|'.$t->amount_minor.'|'.$t->balance_minor);
        $claimed = [];
        $entries = [];

        foreach ($lines as $line) {
            $found = null;
            $unclear = null;
            $free = static fn (?Transaction $t): ?Transaction => $t !== null && ! isset($claimed[$t->id]) ? $t : null;

            if ($line['booking_id'] !== null) {
                $found = $free($byId->get($line['booking_id']));
                if ($found === null) {
                    // A303: imported before without ID – same day, amount (also the old wrong sign or the original amount) and counterparty.
                    $legacy = self::sameDay($existing, $line, $claimed, true);
                    $found = $legacy->first(static fn (Transaction $t): bool => self::sameParty($t->counterparty, $line['counterparty']));
                    $unclear = $found === null ? $legacy->first() : null;
                }
            } else {
                if ($line['balance_minor'] !== null) {
                    $found = $free($byBalance->get($line['booking_date'].'|'.$line['amount_minor'].'|'.$line['balance_minor']));
                }
                $found ??= $free($byFingerprint->get($line['fingerprint']));
                if ($found === null) {
                    $unclear = self::sameDay($existing, $line, $claimed, false)->first();
                }
            }

            if ($found !== null) {
                $claimed[$found->id] = true;
                $entries[] = ['line' => $line, 'action' => 'known', 'existing' => $found->id, ...self::compare($found, $line)];
            } elseif ($unclear !== null) {
                $claimed[$unclear->id] = true;
                $entries[] = ['line' => $line, 'action' => 'unclear', 'existing' => $unclear->id, ...self::compare($unclear, $line)];
            } else {
                $entries[] = ['line' => $line, 'action' => 'new', 'existing' => null, 'fills' => [], 'diffs' => []];
            }
        }

        return $entries;
    }

    /**
     * Stores the plan: new rows, fills of known ones, the unclear ones as decided (index → "new" | "known"; undecided
     * ones are left out). Returns the ids of the new rows and of those that just became completed (for the matcher).
     *
     * @param  array<int, array{line: array<string, mixed>, action: string, existing: string|null, fills: array<string, mixed>, diffs: array<string, array{0: mixed, 1: mixed}>}>  $plan
     * @param  array<int|string, string>  $decisions
     * @return array{new: list<string>, updated: int, completed: list<string>}
     */
    public static function apply(BankAccount $account, array $plan, array $decisions = []): array
    {
        return DB::transaction(static function () use ($account, $plan, $decisions): array {
            $fingerprints = Transaction::query()->where('bank_account_id', $account->id)->pluck('fingerprint')->flip();
            $new = [];
            $completed = [];
            $updated = 0;

            foreach ($plan as $index => $entry) {
                $action = $entry['action'] === 'unclear' ? ($decisions[$index] ?? null) : $entry['action'];
                $line = $entry['line'];
                if ($action === 'new') {
                    $fingerprint = $line['fingerprint'];
                    for ($n = 2; $fingerprints->has($fingerprint); $n++) {
                        $fingerprint = hash('sha256', $line['fingerprint'].'|'.$n);
                    }
                    $fingerprints->put($fingerprint, true);
                    $new[] = Transaction::query()->create([
                        ...$line, 'fingerprint' => $fingerprint, 'bank_account_id' => $account->id, 'currency' => $line['currency'] ?? $account->currency,
                    ])->id;
                } elseif ($action === 'known' && $entry['existing'] !== null && $entry['fills'] !== []) {
                    $transaction = Transaction::query()->whereKey($entry['existing'])->first();
                    if ($transaction === null) {
                        continue;
                    }
                    if (isset($entry['fills']['bank_state']) && $entry['fills']['bank_state'] === 'completed') {
                        $completed[] = $transaction->id;
                    }
                    $transaction->forceFill($entry['fills'])->save();
                    $updated++;
                }
            }

            return ['new' => $new, 'updated' => $updated, 'completed' => $completed];
        });
    }

    /**
     * The empty fields a known booking gets, the bank status, and what differs (A302). A booking imported before
     * without raw data (the first import, 143) also takes the corrected amount.
     *
     * @param  array<string, mixed>  $line
     * @return array{fills: array<string, mixed>, diffs: array<string, array{0: mixed, 1: mixed}>}
     */
    private static function compare(Transaction $old, array $line): array
    {
        $fills = [];
        $diffs = [];
        foreach (self::FILLABLE as $field) {
            $value = $line[$field] ?? null;
            $current = $old->getAttribute($field);
            if ($current instanceof \DateTimeInterface) {
                $current = $current->format('Y-m-d');
            }
            if ($value === null || $value === '') {
                continue;
            }
            if ($current === null || $current === '') {
                $fills[$field] = $value;
            } elseif (in_array($field, self::COMPARED, true) && (string) $current !== (string) $value) {
                $diffs[$field] = [$current, $value];
            }
        }
        if ($line['bank_state'] !== null && $line['bank_state'] !== $old->bank_state) {
            $fills['bank_state'] = $line['bank_state'];
        }
        if ($old->raw === null) {
            $fills['raw'] = $line['raw'];
            if ($old->extra === null && $line['extra'] !== null) {
                $fills['extra'] = $line['extra'];
            }
            if ($old->amount_minor !== $line['amount_minor']) {
                $fills['amount_minor'] = $line['amount_minor'];
            }
        } elseif ($old->amount_minor !== $line['amount_minor']) {
            $diffs['amount_minor'] = [$old->amount_minor, $line['amount_minor']];
        }

        return ['fills' => $fills, 'diffs' => $diffs];
    }

    /**
     * Unclaimed bookings of the same day and amount; with $legacy only those without booking ID, also with the old
     * wrong sign or the original amount as amount (143).
     *
     * @param  Collection<int, Transaction>  $existing
     * @param  array<string, mixed>  $line
     * @param  array<string, bool>  $claimed
     * @return Collection<int, Transaction>
     */
    private static function sameDay(Collection $existing, array $line, array $claimed, bool $legacy): Collection
    {
        $amounts = $legacy ? array_filter([abs((int) $line['amount_minor']), $line['orig_amount_minor'] === null ? null : abs((int) $line['orig_amount_minor'])]) : [];

        return $existing->filter(static fn (Transaction $t): bool => ! isset($claimed[$t->id])
            && $t->booking_date->format('Y-m-d') === $line['booking_date']
            && ($legacy
                ? $t->booking_id === null && in_array(abs($t->amount_minor), $amounts, true)
                : $t->amount_minor === $line['amount_minor'] && $t->fingerprint !== $line['fingerprint']))->values();
    }

    private static function sameParty(?string $a, ?string $b): bool
    {
        $a = mb_strtolower(trim((string) $a));
        $b = mb_strtolower(trim((string) $b));

        return $a === '' || $b === '' || $a === $b || str_contains($a, $b) || str_contains($b, $a);
    }

    /**
     * Stores everything that is new and fills what is known, without asking (unclear ones stay out). Kept for callers
     * that don't show a preview.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<string>
     */
    public static function store(BankAccount $account, array $lines): array
    {
        return self::apply($account, self::plan($account, $lines))['new'];
    }
}

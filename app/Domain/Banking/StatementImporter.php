<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Org\OrgScope;
use App\Domain\Properties\PropertyValues;
use Illuminate\Support\Facades\DB;

/**
 * One import from start to end: the lines of a statement, split by account (a Revolut file with several "Account"
 * values or currencies goes into several accounts, missing ones are created, A299), each with its dedupe plan
 * (StatementImport::plan, A294/A295) – and then storing it: accounts with a speaking name (142), the bookings, the
 * imported period (A296), refunds hung on their original (A298).
 */
final class StatementImporter
{
    /**
     * @param  array<string, int|list<int>|null>  $columns
     * @return array{groups: list<array{key: string, account: BankAccount|null, name: string, currency: string, sub: string|null, plan: array<int, array{line: array<string, mixed>, action: string, existing: string|null, fills: array<string, mixed>, diffs: array<string, array{0: mixed, 1: mixed}>}>}>, unreadable: int, lines: int}
     */
    public static function prepare(CsvStatement $statement, array $columns, ?BankAccount $target, ?string $name, string $currency): array
    {
        $source = StatementColumns::source($statement->header);
        $read = StatementImport::read($statement, $columns);

        $byGroup = [];
        foreach ($read['lines'] as $index => $line) {
            $cur = $line['currency'] ?? $target->currency ?? $currency;
            $byGroup[($line['sub_account'] ?? '').'|'.$cur][$index] = $line;
        }

        $groups = [];
        $several = count($byGroup) > 1;
        foreach ($byGroup as $key => $lines) {
            [$sub, $cur] = explode('|', $key, 2);
            $sub = $sub === '' ? null : $sub;
            $sourceKey = ($source ?? 'file').':'.$key;
            $account = null;
            if (! $several) {
                $account = $target ?? self::bySourceKey($sourceKey);
            } else {
                $account = $target !== null && $target->source_key === $sourceKey ? $target : self::bySourceKey($sourceKey);
                // The account the file was dropped on takes the first group of its currency, if it has no source yet.
                if ($account === null && $target !== null && $target->source_key === null && $target->currency === $cur
                    && ! collect($groups)->contains(static fn (array $g): bool => $g['account']?->id === $target->id)) {
                    $account = $target;
                }
            }
            $plan = StatementImport::plan($account, array_values($lines));
            $groups[] = [
                'key' => $sourceKey,
                'account' => $account,
                'name' => $account->name ?? (! $several && $name !== null ? $name : self::proposeName($statement, $source, $sub, $cur)),
                'currency' => $cur,
                'sub' => $sub,
                'plan' => array_combine(array_keys($lines), $plan),
            ];
        }

        return ['groups' => $groups, 'unreadable' => $read['unreadable'], 'lines' => count($read['lines'])];
    }

    /**
     * @param  array{groups: list<array{key: string, account: BankAccount|null, name: string, currency: string, sub: string|null, plan: array<int, array{line: array<string, mixed>, action: string, existing: string|null, fills: array<string, mixed>, diffs: array<string, array{0: mixed, 1: mixed}>}>}>, unreadable: int, lines: int}  $prepared
     * @param  array<int|string, string>  $decisions  line index → "new" | "known" for the unclear ones
     * @param  array<string, string|list<string>>  $profile  the columns by header name, remembered per account
     * @return array{accounts: list<BankAccount>, new: list<string>, known: int, updated: int, completed: list<string>}
     */
    public static function store(CsvStatement $statement, array $prepared, array $decisions, array $profile, ?string $iban): array
    {
        $source = StatementColumns::source($statement->header);

        return DB::transaction(static function () use ($statement, $prepared, $decisions, $profile, $iban, $source): array {
            $result = ['accounts' => [], 'new' => [], 'known' => 0, 'updated' => 0, 'completed' => []];
            $own = $iban ?? self::ownIban($statement);
            $single = count($prepared['groups']) === 1;

            foreach ($prepared['groups'] as $group) {
                $account = $group['account'] ?? BankAccount::query()->create(['name' => $group['name'], 'currency' => $group['currency']]);
                $changes = ['import_profile' => $profile];
                if ($account->source_key === null && $source !== null) {
                    $changes['source_key'] = $group['key'];
                }
                if ($single && $own !== null && $account->iban === null) {
                    $changes['iban'] = $own;
                }
                // 142: an account still named after a Revolut file gets its speaking name.
                if (str_starts_with((string) $source, 'revolut') && preg_match('/(statement|transactions?)[_-]|\.(csv|xlsx)$|_\d{4}-\d{2}/i', $account->name) === 1) {
                    $changes['name'] = self::proposeName($statement, $source, $group['sub'], $group['currency']);
                }
                $account->update($changes);

                $done = StatementImport::apply($account, $group['plan'], $decisions);
                $result['accounts'][] = $account;
                $result['new'] = [...$result['new'], ...$done['new']];
                $result['updated'] += $done['updated'];
                $result['completed'] = [...$result['completed'], ...$done['completed']];
                $result['known'] += count(array_filter($group['plan'], static fn (array $e, int|string $i): bool => $e['action'] === 'known' || ($e['action'] === 'unclear' && ($decisions[$i] ?? null) === 'known'), ARRAY_FILTER_USE_BOTH));

                $dates = array_map(static fn (array $e): string => (string) $e['line']['booking_date'], $group['plan']);
                if ($dates !== []) {
                    BankImport::query()->create(['bank_account_id' => $account->id, 'date_from' => min($dates), 'date_to' => max($dates), 'rows' => count($dates)]);
                }
            }
            if ($result['accounts'] !== []) {
                self::linkRelated($result['accounts'][0]->org_id);
            }

            return $result;
        });
    }

    /**
     * A298: a refund or reversal names its original by the bank's ID; it hangs on it and needs no document of its own.
     */
    public static function linkRelated(string $orgId): int
    {
        $linked = 0;
        $children = Transaction::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)
            ->whereNotNull('related_id')->whereNull('parent_id')->get();
        foreach ($children as $child) {
            $parent = Transaction::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)
                ->where('booking_id', $child->related_id)->whereKeyNot($child->id)->first();
            if ($parent === null) {
                continue;
            }
            $changes = ['parent_id' => $parent->id];
            if (! $child->no_receipt && ! $child->matches()->withoutGlobalScope(OrgScope::class)->where('state', TransactionMatch::CONFIRMED)->exists()) {
                $changes['no_receipt'] = true;
            }
            $child->forceFill($changes)->save();
            $linked++;
        }

        return $linked;
    }

    /**
     * 142: "Revolut Business EUR", "Revolut Savings GBP", "Sparkasse …1234", "Bankkonto …1234" – never the file name.
     */
    public static function proposeName(CsvStatement $statement, ?string $source, ?string $sub, string $currency): string
    {
        if ($source === 'revolut_business' || $source === 'revolut_personal') {
            $parts = [$source === 'revolut_business' ? 'Revolut Business' : 'Revolut'];
            $plain = in_array(mb_strtolower((string) $sub), ['', 'main', 'current', 'hauptkonto', mb_strtolower($currency)], true);
            if (! $plain) {
                $parts[] = (string) $sub;
            }
            if (! str_contains(strtoupper((string) $sub), $currency) || $plain) {
                $parts[] = $currency;
            }

            return implode(' ', $parts);
        }

        $iban = self::ownIban($statement);
        $bank = self::bankName($statement) ?? __('bank.account_default');

        return $iban === null ? $bank : $bank.' …'.substr($iban, -4);
    }

    /** The own IBAN: Sparkasse's "Auftragskonto" column, else the first IBAN above the table. */
    public static function ownIban(CsvStatement $statement): ?string
    {
        $column = array_search('auftragskonto', array_map(StatementColumns::normalise(...), $statement->header), true);
        if ($column !== false && isset($statement->rows[0][$column])) {
            $iban = PropertyValues::parseIban($statement->rows[0][$column]);
            if ($iban !== null) {
                return $iban;
            }
        }
        if (preg_match('/\b([A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){3,7}(?: ?[A-Z0-9]{1,3})?)\b/', $statement->preamble, $m) === 1) {
            return PropertyValues::parseIban($m[1]);
        }

        return null;
    }

    /** A few banks by their typical header; CAMT names the bank itself. */
    private static function bankName(CsvStatement $statement): ?string
    {
        $header = ' '.implode(' | ', array_map(StatementColumns::normalise(...), $statement->header)).' ';
        $has = static fn (string ...$words): bool => array_filter($words, static fn (string $w): bool => ! str_contains($header, $w)) === [];

        $name = match (true) {
            $has('auftragskonto', 'beguenstigter zahlungspflichtiger') => 'Sparkasse',
            $has('zahlungspflichtige r', 'zahlungsempfaenger in') => 'DKB',
            $has('auftraggeber empfaenger', 'valuta', 'buchungstext') => 'ING',
            $has('payee', 'payment reference', 'amount eur') => 'N26',
            default => null,
        };
        if ($name === null && StatementColumns::source($statement->header) === 'camt') {
            $first = trim((string) preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/', '', strtok($statement->preamble, "\n") ?: ''));
            $name = $first === '' ? null : mb_substr($first, 0, 100);
        }

        return $name;
    }

    private static function bySourceKey(string $key): ?BankAccount
    {
        return BankAccount::query()->where('source_key', $key)->first();
    }
}

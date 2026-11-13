<?php

declare(strict_types=1);

namespace App\Domain\Banking;

/**
 * Which column of a statement holds which field (A289, A301). Only date and amount are required. Known layouts
 * (Revolut Business and Personal, CAMT.053) are mapped without questions (A292); otherwise 1) the header names in
 * German and English with synonyms, 2) the content of the columns nobody claimed (IBAN, date, amount, currency code,
 * card number), 3) what an account remembered by header name wins over both. A field found by its name or remembered
 * is "sure", one found by content "unsure" (the column page shows it orange).
 *
 * A field maps to one column or to a list (the first non-empty cell wins, e.g. Revolut's counterparty from
 * Beneficiary name, Payer or Sender name).
 */
final class StatementColumns
{
    public const REQUIRED = ['date', 'amount'];

    /** All fields in the order of the column page. */
    public const FIELDS = [
        'date', 'amount', 'counterparty', 'purpose', 'purpose2', 'iban', 'bic', 'kind', 'reference', 'creditor_id', 'value_date',
        'orig_amount', 'orig_currency', 'fee', 'fee_currency', 'balance', 'currency', 'category', 'card', 'state', 'sub_account',
        'booking_id', 'related_id', 'mcc',
    ];

    /** Header patterns (normalised: lower case, umlauts spelt out, other signs as spaces), in the order fields claim columns. */
    private const PATTERNS = [
        'booking_id' => ['/^(id|transaction id|transaktions ?id|buchungs ?id|umsatz ?id|bank ?referenz|bank reference|reference id)$/'],
        'related_id' => ['/related transaction|bezugs ?id|ursprungsbuchung/'],
        'orig_amount' => ['/orig(inal)? ?amount|original ?betrag|ursprungs ?betrag|fremdwaehrungs ?betrag|betrag (in )?fremdwaehrung/'],
        'orig_currency' => ['/orig(inal)? ?currency|original ?waehrung|ursprungs ?waehrung|^fremdwaehrung$/'],
        'fee_currency' => ['/fee currency|gebuehren ?waehrung/'],
        'fee' => ['/^(fee|fees|gebuehr|gebuehren|entgelt)$/'],
        'balance' => ['/saldo|balance|kontostand/'],
        'value_date' => ['/wertstellung|valuta|value ?date|date started|started date/'],
        'date' => ['/buchungs ?(tag|datum)|date completed|completed date|booking date|transaction date/', '/^(buchung|datum|date)$/', '/datum|date/'],
        'creditor_id' => ['/glaeubiger ?id|creditor ?id/'],
        'iban' => ['/(empf|beguenstigt|zahlungsbeteiligt|gegen|auftraggeber|counterparty|payee|beneficiary|zahlungspflichtig).*iban|iban.*(empf|beguenstigt|zahlungsbeteiligt|gegen|auftraggeber|counterparty|payee|zahlungspflichtig)/', '/^(kontonummer )?iban$/'],
        'bic' => ['/(empf|beguenstigt|zahlungsbeteiligt|gegen|auftraggeber|counterparty|payee|beneficiary|zahlungspflichtig).*(bic|swift)|^(bic|swift|bic swift)$/'],
        'card' => ['/card number|kartennummer|^(karte|card)$/'],
        'state' => ['/^(state|status)$/'],
        'kind' => ['/^(type|typ|buchungstext|umsatzart|transaction type|buchungsart|vorgang)$/'],
        'category' => ['/kategorie|category/'],
        'mcc' => ['/^mcc$/'],
        'currency' => ['/^(currency|waehrung|payment currency)$/'],
        'sub_account' => ['/^(account|konto|product|unterkonto)$/'],
        'purpose2' => ['/(verwendungszweck|zweck|purpose) ?2/'],
        'reference' => ['/mandats ?referenz|mandate|end ?to ?end|kundenreferenz|^(referenz|reference)$/'],
        'purpose' => ['/verwendungszweck|zweck|purpose|beschreibung|description|buchungsdetails|payment reference|memo|^details$/'],
        'amount' => ['/total amount/', '/^(betrag|amount|umsatz)/', '/betrag|amount|umsatz/'],
        'counterparty' => ['/empfaenger|auftraggeber|beguenstigt|zahlungspflichtig|zahlungsbeteiligt|counterparty|payee|beneficiary name|merchant|haendler/', '/name/'],
    ];

    /** Revolut Business (A292): header names → fields; lists take the first non-empty cell. */
    private const REVOLUT_BUSINESS = [
        'date' => ['Date completed (UTC)', 'Date started (UTC)'],
        'value_date' => 'Date started (UTC)',
        'booking_id' => 'ID',
        'kind' => 'Type',
        'state' => 'State',
        'purpose' => 'Description',
        'purpose2' => 'Reference',
        'counterparty' => ['Beneficiary name', 'Payer', 'Sender name'],
        'card' => 'Card number',
        'orig_currency' => 'Orig currency',
        'orig_amount' => 'Orig amount',
        'currency' => 'Payment currency',
        'amount' => ['Total amount', 'Amount'],
        'fee' => 'Fee',
        'fee_currency' => 'Fee currency',
        'balance' => 'Balance',
        'sub_account' => 'Account',
        'iban' => 'Beneficiary IBAN',
        'bic' => 'Beneficiary BIC',
        'mcc' => 'MCC',
        'related_id' => 'Related transaction id',
    ];

    private const REVOLUT_PERSONAL = [
        'date' => ['Completed Date', 'Started Date'],
        'value_date' => 'Started Date',
        'kind' => 'Type',
        'sub_account' => 'Product',
        'purpose' => 'Description',
        'amount' => 'Amount',
        'fee' => 'Fee',
        'currency' => 'Currency',
        'state' => 'State',
        'balance' => 'Balance',
    ];

    /**
     * @param  array<string, string|list<string>>  $profile  remembered header names by field (an account's last import)
     * @return array{columns: array<string, int|list<int>|null>, sure: array<string, bool>, source: string|null}
     */
    public static function guess(CsvStatement $statement, array $profile = []): array
    {
        $source = self::source($statement->header);
        $columns = array_fill_keys(self::FIELDS, null);
        $sure = [];

        $known = match ($source) {
            'revolut_business' => self::REVOLUT_BUSINESS,
            'revolut_personal' => self::REVOLUT_PERSONAL,
            'camt' => array_combine(CamtStatement::FIELDS, CamtStatement::HEADER),
            default => null,
        };
        if ($known !== null) {
            foreach ($known as $field => $names) {
                $columns[$field] = self::byName($statement->header, $names);
                $sure[$field] = $columns[$field] !== null;
            }
        } else {
            $columns = self::byPatterns($statement->header, $columns, $sure);
            $columns = self::byContent($statement, $columns, $sure);
        }

        foreach ($profile as $field => $names) {
            $index = in_array($field, self::FIELDS, true) ? self::byName($statement->header, $names) : null;
            if ($index !== null) {
                $columns[$field] = $index;
                $sure[$field] = true;
            }
        }

        return ['columns' => $columns, 'sure' => array_filter($sure, static fn (bool $s, string $field): bool => $columns[$field] !== null, ARRAY_FILTER_USE_BOTH), 'source' => $source];
    }

    /**
     * "revolut_business", "revolut_personal", "camt" or null, from the header names.
     *
     * @param  list<string>  $header
     */
    public static function source(array $header): ?string
    {
        if ($header === CamtStatement::HEADER) {
            return 'camt';
        }
        $has = static fn (string ...$names): bool => array_diff($names, $header) === [];
        if ($has('Date started (UTC)', 'Date completed (UTC)', 'ID', 'Type', 'State', 'Description', 'Amount')) {
            return 'revolut_business';
        }
        if ($has('Type', 'Product', 'Started Date', 'Completed Date', 'Description', 'Amount', 'Currency', 'State')) {
            return 'revolut_personal';
        }

        return null;
    }

    /** "Empfänger-IBAN" → "empfaenger iban". */
    public static function normalise(string $name): string
    {
        $name = str_replace(['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü'], ['ae', 'oe', 'ue', 'ss', 'ae', 'oe', 'ue'], $name);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($name)));
    }

    /**
     * @param  list<string>  $header
     * @param  string|list<string>  $names
     * @return int|list<int>|null
     */
    private static function byName(array $header, string|array $names): int|array|null
    {
        $found = [];
        foreach ((array) $names as $name) {
            $index = array_search($name, $header, true);
            if ($index !== false) {
                $found[] = (int) $index;
            }
        }

        return match (count($found)) {
            0 => null,
            1 => $found[0],
            default => $found,
        };
    }

    /**
     * @param  list<string>  $header
     * @param  array<string, int|list<int>|null>  $columns
     * @param  array<string, bool>  $sure
     * @return array<string, int|list<int>|null>
     */
    private static function byPatterns(array $header, array $columns, array &$sure): array
    {
        $names = array_map(self::normalise(...), $header);
        $used = [];
        foreach (self::PATTERNS as $field => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($names as $index => $name) {
                    if (isset($used[$index]) || ($field !== 'iban' && $field !== 'bic' && preg_match('/\b(iban|bic)\b/', $name) === 1)) {
                        continue;
                    }
                    if (preg_match($pattern, $name) === 1) {
                        $columns[$field] = $index;
                        $sure[$field] = true;
                        $used[$index] = true;

                        continue 3;
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * Columns no name claimed, by what most of their first cells look like (A301 step 2).
     *
     * @param  array<string, int|list<int>|null>  $columns
     * @param  array<string, bool>  $sure
     * @return array<string, int|list<int>|null>
     */
    private static function byContent(CsvStatement $statement, array $columns, array &$sure): array
    {
        $used = [];
        foreach ($columns as $index) {
            foreach ((array) $index as $i) {
                $used[$i] = true;
            }
        }
        $sample = array_slice($statement->rows, 0, 20);
        foreach (array_keys($statement->header) as $index) {
            if (isset($used[$index])) {
                continue;
            }
            $cells = array_values(array_filter(array_map(static fn (array $row): string => trim($row[$index] ?? ''), $sample), static fn (string $c): bool => $c !== ''));
            if ($cells === []) {
                continue;
            }
            $all = static fn (callable $test): bool => count(array_filter($cells, $test)) === count($cells);
            $field = match (true) {
                $all(static fn (string $c): bool => preg_match('/^[A-Z]{2}\d{2}[A-Z0-9 ]{11,32}$/', strtoupper($c)) === 1) => 'iban',
                $all(static fn (string $c): bool => CsvStatement::normaliseDate($c) !== null) => $columns['date'] === null ? 'date' : 'value_date',
                $all(static fn (string $c): bool => preg_match('/^[*xX\d ]{0,15}[*xX]{2,}[*xX ]*\d{4}$/', $c) === 1) => 'card',
                $all(static fn (string $c): bool => preg_match('/^[A-Z]{3}$/', $c) === 1) => $columns['currency'] === null ? 'currency' : 'orig_currency',
                $all(static fn (string $c): bool => preg_match('/^[-+\x{2212}]?\s*[\d.,]*\d[.,]\d{1,2}\s*-?$/u', $c) === 1) => 'amount',
                default => null,
            };
            if ($field !== null && $columns[$field] === null) {
                $columns[$field] = $index;
                $sure[$field] = false;
                $used[$index] = true;
            }
        }

        return $columns;
    }
}

<?php

declare(strict_types=1);

use App\Domain\Banking\CamtStatement;
use App\Domain\Banking\CsvStatement;
use App\Domain\Banking\MatchScore;
use App\Domain\Banking\StatementColumns;
use App\Domain\Banking\StatementImport;
use App\Domain\Banking\StatementImporter;

// Points 137, 138, 143 (A289–A293, A297, A301, A309): mapping, Revolut, CAMT and the sign of amounts. Synthetic data only.

const REVOLUT_BUSINESS = ['Date started (UTC)', 'Date completed (UTC)', 'ID', 'Type', 'State', 'Description', 'Reference', 'Payer', 'Card number', 'Card label', 'Card state',
    'Orig currency', 'Orig amount', 'Payment currency', 'Amount', 'Total amount', 'Exchange rate', 'Fee', 'Fee currency', 'Balance', 'Account',
    'International account number', 'Beneficiary account number', 'Beneficiary sort code or routing number', 'Beneficiary IBAN', 'Beneficiary BIC',
    'Beneficiary name', 'MCC', 'Related transaction id', 'Spend program', 'Sender account', 'Sender name', 'Card references'];

/**
 * @param  list<array<string, string>>  $rows
 */
function revolutCsv(array $rows): string
{
    $lines = [implode(',', REVOLUT_BUSINESS)];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(static fn (string $name): string => $row[$name] ?? '', REVOLUT_BUSINESS));
    }

    return implode("\n", $lines)."\n";
}

it('maps Revolut Business without questions: dates, total amount, counterparty, card, extras (A292)', function (): void {
    $statement = CsvStatement::parse(revolutCsv([
        ['Date started (UTC)' => '2026-03-01 09:00:00', 'Date completed (UTC)' => '2026-03-02 10:00:00', 'ID' => 'tx-1', 'Type' => 'CARD_PAYMENT', 'State' => 'COMPLETED',
            'Description' => 'Tankstelle Muster', 'Card number' => '459999******1234', 'Orig currency' => 'EUR', 'Orig amount' => '50.00', 'Payment currency' => 'EUR',
            'Amount' => '-50.00', 'Total amount' => '-50.00', 'Exchange rate' => '1.0', 'Balance' => '950.00', 'Account' => 'Main', 'MCC' => '5541'],
        ['Date started (UTC)' => '2026-03-03 09:00:00', 'ID' => 'tx-2', 'Type' => 'TRANSFER', 'State' => 'PENDING', 'Description' => 'An Muster GmbH', 'Reference' => 'Rechnung 4711',
            'Payment currency' => 'EUR', 'Amount' => '-100.00', 'Total amount' => '-100.00', 'Account' => 'Main', 'Beneficiary IBAN' => 'DE89370400440532013000', 'Beneficiary name' => 'Muster GmbH'],
    ]));
    $guess = StatementColumns::guess($statement);

    expect($guess['source'])->toBe('revolut_business')
        ->and($guess['sure'])->toHaveKeys(['date', 'amount']);

    $lines = StatementImport::read($statement, $guess['columns'])['lines'];

    expect($lines[0])->toMatchArray([
        'booking_date' => '2026-03-02', 'value_date' => '2026-03-01', 'amount_minor' => -5000, 'booking_id' => 'tx-1', 'kind' => 'CARD_PAYMENT',
        'card_last4' => '1234', 'mcc' => '5541', 'balance_minor' => 95000, 'sub_account' => 'Main', 'currency' => 'EUR', 'bank_state' => 'completed',
    ])->and($lines[0]['extra'])->toBe(['Exchange rate' => '1.0'])
        ->and($lines[0]['raw']['Card number'])->toBe('459999******1234')
        // Not completed yet: booked on the day it started; the counterparty from "Beneficiary name".
        ->and($lines[1])->toMatchArray(['booking_date' => '2026-03-03', 'counterparty' => 'Muster GmbH', 'purpose2' => 'Rechnung 4711', 'bank_state' => 'pending',
            'counterparty_iban' => 'DE89370400440532013000']);
});

it('repairs the sign: a fee is money going out, the balance tells the rest (143)', function (): void {
    $statement = CsvStatement::parse(revolutCsv([
        // Newest first, as Revolut exports: balance 900 after a fee of 5, 905 after an incoming 105, 800 before.
        ['Date completed (UTC)' => '2026-03-05', 'ID' => 'a', 'Type' => 'FEE', 'Payment currency' => 'EUR', 'Amount' => '5.00', 'Total amount' => '5.00', 'Balance' => '900.00'],
        ['Date completed (UTC)' => '2026-03-04', 'ID' => 'b', 'Type' => 'TRANSFER', 'Payment currency' => 'EUR', 'Amount' => '105.00', 'Total amount' => '105.00', 'Balance' => '905.00'],
        ['Date completed (UTC)' => '2026-03-03', 'ID' => 'c', 'Type' => 'TRANSFER', 'Payment currency' => 'EUR', 'Amount' => '20.00', 'Total amount' => '20.00', 'Balance' => '800.00'],
        ['Date completed (UTC)' => '2026-03-02', 'ID' => 'd', 'Type' => 'TOPUP', 'Payment currency' => 'EUR', 'Amount' => '820.00', 'Total amount' => '820.00', 'Balance' => '820.00'],
    ]));
    $lines = StatementImport::read($statement, StatementColumns::guess($statement)['columns'])['lines'];

    expect(array_column($lines, 'amount_minor'))->toBe([-500, 10500, -2000, 82000]);
});

it('finds fields by German and English header names and by content (A301)', function (): void {
    $statement = CsvStatement::parse("Buchungstag;Wertstellung;Empfänger;Empfänger-IBAN;Verwendungszweck;Gläubiger-ID;Saldo;Betrag;Kennung\n"
        ."01.02.2026;02.02.2026;Stadtwerke;DE89 3704 0044 0532 0130 00;Abschlag;DE98ZZZ09999999999;1.000,00;-89,00;EUR\n");
    $guess = StatementColumns::guess($statement);

    expect($guess['columns'])->toMatchArray(['date' => 0, 'value_date' => 1, 'counterparty' => 2, 'iban' => 3, 'purpose' => 4, 'creditor_id' => 5, 'balance' => 6, 'amount' => 7, 'currency' => 8])
        ->and($guess['sure'])->toHaveKey('date')
        ->and($guess['sure']['currency'])->toBeFalse();
});

it('reads CAMT.053 with the bank reference as ID (A297)', function (): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>
<Acct><Id><IBAN>DE02120300000000202051</IBAN></Id><Svcr><FinInstnId><Nm>Musterbank</Nm></FinInstnId></Svcr></Acct>
<Ntry><Amt Ccy="EUR">12.30</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2026-02-01</Dt></BookgDt><ValDt><Dt>2026-02-02</Dt></ValDt>
<AcctSvcrRef>REF-1</AcctSvcrRef><NtryDtls><TxDtls><Refs><MndtId>M-77</MndtId></Refs><RltdPties><Cdtr><Nm>Muster GmbH</Nm></Cdtr>
<CdtrAcct><Id><IBAN>DE89370400440532013000</IBAN></Id></CdtrAcct></RltdPties><RmtInf><Ustrd>Rechnung 4711</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>
</Stmt></BkToCstmrStmt></Document>
XML;
    $statement = CsvStatement::read($xml);
    $guess = StatementColumns::guess($statement);
    $line = StatementImport::read($statement, $guess['columns'])['lines'][0];

    expect(CamtStatement::looksLike($xml))->toBeTrue()
        ->and($guess['source'])->toBe('camt')
        ->and($line)->toMatchArray(['booking_date' => '2026-02-01', 'value_date' => '2026-02-02', 'amount_minor' => -1230, 'booking_id' => 'REF-1',
            'counterparty' => 'Muster GmbH', 'counterparty_iban' => 'DE89370400440532013000', 'purpose' => 'Rechnung 4711', 'reference' => 'M-77', 'bank_state' => 'completed'])
        ->and(StatementImporter::proposeName($statement, 'camt', null, 'EUR'))->toBe('Musterbank …2051');
});

it('names Revolut accounts after account and currency (142)', function (): void {
    $statement = CsvStatement::parse(revolutCsv([['Date completed (UTC)' => '2026-03-02', 'ID' => 'x', 'Amount' => '1.00', 'Payment currency' => 'GBP']]));

    expect(StatementImporter::proposeName($statement, 'revolut_business', 'Main', 'EUR'))->toBe('Revolut Business EUR')
        ->and(StatementImporter::proposeName($statement, 'revolut_business', 'Reisekasse', 'GBP'))->toBe('Revolut Business Reisekasse GBP');
});

it('matches a payment in another currency by its original amount (A291)', function (): void {
    $tx = ['date' => '2026-03-02', 'amount' => -4610, 'orig_amount' => -5000, 'purpose' => null];

    expect(MatchScore::score($tx, ['amount' => 5000])['reasons'])->toHaveKey('amount', 40)
        ->and(MatchScore::worthScoring($tx, ['amount' => 5000]))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Domain\Banking\CsvStatement;
use App\Domain\Banking\MatchScore;
use App\Domain\Banking\StatementColumns;
use App\Domain\Banking\StatementImport;
use App\Domain\Ocr\Invoice\InvoiceLines;
use App\Domain\Ocr\Invoice\TimeReader;
use App\Domain\Ocr\InvoiceReader;

// Point 219: the time of a booking and of a receipt – reading, and the bonus in the score. Synthetic data only.

it('reads the time behind a date, local; none without one or at midnight', function (string $value, bool $utc, ?string $time): void {
    expect(CsvStatement::normaliseTime($value, $utc))->toBe($time);
})->with([
    ['14.03.2026 09:41', false, '09:41'],
    ['14.03.2026 9:41 Uhr', false, '09:41'],
    ['2026-03-14 09:41:12', false, '09:41'],
    ['2026-03-14T09:41:00.000', false, '09:41'],
    // Revolut Business: UTC → Berlin (winter +1, summer +2).
    ['2026-03-14 08:41:12', true, '09:41'],
    ['2026-07-14 07:41:12', true, '09:41'],
    // CAMT DtTm with an offset.
    ['2026-03-14T09:41:00+01:00', false, '09:41'],
    ['2026-03-14T08:41:00Z', false, '09:41'],
    // Local time on another day than the date: the date wins, no time.
    ['2026-03-14 23:30:00', true, null],
    ['14.03.2026', false, null],
    ['2026-03-14 00:00:00', false, null],
    ['14.03.2026 25:10', false, null],
    ['keine Zeit', false, null],
]);

it('keeps the time of Revolut and CAMT bookings, null where the bank has none', function (): void {
    $head = ['Date started (UTC)', 'Date completed (UTC)', 'ID', 'Type', 'State', 'Description', 'Amount', 'Total amount', 'Payment currency', 'Account'];
    $csv = implode(',', $head)."\n"
        ."2026-03-01 08:00:00,2026-03-02 10:15:00,t1,CARD_PAYMENT,COMPLETED,Cafe,-3.50,-3.50,EUR,Main\n"
        ."2026-03-03 11:05:00,,t2,TRANSFER,PENDING,Miete,-500.00,-500.00,EUR,Main\n";
    $statement = CsvStatement::parse($csv);
    $lines = StatementImport::read($statement, StatementColumns::guess($statement)['columns'])['lines'];

    expect($lines[0]['booked_time'])->toBe('11:15')
        ->and($lines[1]['booked_time'])->toBe('12:05');

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>
<Acct><Id><IBAN>DE02120300000000202051</IBAN></Id></Acct>
<Ntry><Amt Ccy="EUR">12.30</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><DtTm>2026-02-01T14:20:00+01:00</DtTm></BookgDt><ValDt><Dt>2026-02-02</Dt></ValDt><AcctSvcrRef>R1</AcctSvcrRef></Ntry>
<Ntry><Amt Ccy="EUR">5.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2026-02-03</Dt></BookgDt><AcctSvcrRef>R2</AcctSvcrRef></Ntry>
</Stmt></BkToCstmrStmt></Document>
XML;
    $camt = CsvStatement::read($xml);
    $camtLines = StatementImport::read($camt, StatementColumns::guess($camt)['columns'])['lines'];

    expect($camtLines[0])->toMatchArray(['booking_date' => '2026-02-01', 'booked_time' => '14:20', 'value_date' => '2026-02-02'])
        ->and($camtLines[1]['booked_time'])->toBeNull();

    $plain = CsvStatement::parse("Buchungstag;Betrag;Verwendungszweck\n14.03.2026 09:41;-12,30;Baeckerei\n15.03.2026;-5,00;Kiosk\n");
    $plainLines = StatementImport::read($plain, StatementColumns::guess($plain)['columns'])['lines'];

    expect($plainLines[0])->toMatchArray(['booking_date' => '2026-03-14', 'booked_time' => '09:41'])
        ->and($plainLines[1]['booked_time'])->toBeNull();
});

it('adds points when booking and receipt times are close, scaled down to two hours', function (?string $receipt, int $points): void {
    $tx = ['date' => '2026-03-14', 'time' => '09:41', 'amount' => -1230];
    $doc = ['amount' => 1230, 'invoice_date' => '2026-03-14', 'time' => $receipt];

    expect(MatchScore::score($tx, $doc)['reasons']['time'] ?? 0)->toBe($points);
})->with([
    ['09:38', 10],
    ['09:56', 10],
    ['10:30', 6],
    ['11:30', 3],
    ['12:00', 0],
    [null, 0],
]);

it('compares the full moments and leaves the score alone without a time', function (): void {
    // Booked the next morning: more than two hours apart.
    expect(MatchScore::score(['date' => '2026-03-15', 'time' => '09:40', 'amount' => -1230], ['amount' => 1230, 'invoice_date' => '2026-03-14', 'time' => '09:38'])['reasons'])
        ->not->toHaveKey('time')
        // Just after midnight: still close.
        ->and(MatchScore::score(['date' => '2026-03-15', 'time' => '00:05', 'amount' => -1230], ['amount' => 1230, 'invoice_date' => '2026-03-14', 'time' => '23:55'])['reasons']['time'])->toBe(10)
        ->and(MatchScore::score(['date' => '2026-03-14', 'amount' => -1230], ['amount' => 1230, 'invoice_date' => '2026-03-14', 'time' => '09:38']))
        ->toBe(['score' => 55, 'reasons' => ['amount' => 40, 'date' => 15]]);
});

it('reads the time printed on a receipt near its date, never opening hours or amounts', function (): void {
    $read = static fn (string $text): ?string => (new InvoiceReader)->read(null, $text)->receiptTime?->value;

    expect($read("Baeckerei Muster\nMo-Fr 06:00-18:00\nBrot 3,50\nSumme 3,50\n14.03.2026 09:38 Bon 1234"))->toBe('09:38')
        ->and($read("Kiosk Muster\nDatum: 14.03.2026\nUhrzeit: 9:38 Uhr\nSumme 5,00"))->toBe('09:38')
        ->and($read("Tankstelle Muster\n14.03.2026\n09:38:12\nSumme 50,00"))->toBe('09:38')
        ->and($read("Muster GmbH\nRechnungsdatum 14.03.2026\nSumme 12.30 EUR"))->toBeNull()
        ->and($read("Laden Muster\nGeöffnet 08:00 bis 20:00\nDatum 14.03.2026\nSumme 9,99"))->toBeNull()
        ->and(TimeReader::times('Zeit 9.38 Uhr, Betrag 9.38'))->toBe([['09:38', 5, 9]])
        ->and(TimeReader::read(InvoiceLines::fromText("Summe 3,50\n13:02"), null))->toBeNull();
});

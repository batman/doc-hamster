<?php

declare(strict_types=1);

use App\Domain\Banking\CsvStatement;
use App\Domain\Banking\StatementImport;

it('finds delimiter and header below account details, in Windows-1252 too', function (): void {
    $csv = "Konto;DE00 0000 0000 0000 0000 00\n\nBuchungstag;Empf\xE4nger;Verwendungszweck;Betrag\n01.02.2026;Muster GmbH;Rechnung 1;-12,30\n";

    $statement = CsvStatement::parse($csv);

    expect($statement->header)->toBe(['Buchungstag', 'Empfänger', 'Verwendungszweck', 'Betrag'])
        ->and($statement->rows)->toHaveCount(1)
        ->and($statement->guessColumns())->toBe(['date' => 0, 'amount' => 3, 'counterparty' => 1, 'purpose' => 2]);
});

it('reads comma-separated files with quoted cells', function (): void {
    $statement = CsvStatement::parse("Date,Description,Amount\n2026-02-01,\"Coffee, large\",-3.50\n");

    expect($statement->rows[0])->toBe(['2026-02-01', 'Coffee, large', '-3.50']);
});

it('turns bank amounts into minor units', function (string $value, ?int $minor): void {
    expect(CsvStatement::amountToMinor($value))->toBe($minor);
})->with([
    ['1.234,56', 123456],
    ['1,234.56', 123456],
    ['-12,30', -1230],
    ['12,30-', -1230],
    ['12.30 EUR', 1230],
    ['1.234', 123400],
    ['5,5', 550],
    ['100', 10000],
    ['', null],
    ['abc', null],
]);

it('normalises dates and rejects nonsense', function (string $value, ?string $date): void {
    expect(CsvStatement::normaliseDate($value))->toBe($date);
})->with([
    ['01.02.2026', '2026-02-01'],
    ['01.02.26', '2026-02-01'],
    ['2026-02-01', '2026-02-01'],
    ['1.2.2026', '2026-02-01'],
    ['2026-02-01 12:34:56', '2026-02-01'],
    ['31.02.2026', null],
    ['morgen', null],
]);

it('is not fooled by commas in a purpose or by padded account details', function (): void {
    $csv = "Konto;DE00 0000 0000 0000 0000 00;;\n"
        ."Datum;Name;Verwendungszweck;Betrag\n"
        ."01.02.2026;Muster GmbH;\"a, b, c, d, e, f, g, h\";-12,30\n";

    $statement = CsvStatement::parse($csv);

    expect($statement->header)->toBe(['Datum', 'Name', 'Verwendungszweck', 'Betrag'])
        ->and($statement->rows[0][2])->toBe('a, b, c, d, e, f, g, h');
});

it('keeps a quoted purpose over several lines in one row', function (): void {
    $statement = CsvStatement::parse("Datum;Zweck;Betrag\n01.02.2026;\"Zeile 1\nZeile 2\";-1,00\n02.02.2026;Zwei;-2,00\n");

    expect($statement->rows)->toHaveCount(2)
        ->and($statement->rows[0][1])->toBe("Zeile 1\nZeile 2");
});

it('finds no table in plain text', function (): void {
    expect(CsvStatement::parse("nur ein Satz\nund noch einer\n")->header)->toBe([]);
});

it('keeps two equal bookings of one day apart, and the same ones in a second file', function (): void {
    $columns = ['date' => 0, 'amount' => 2, 'counterparty' => 1, 'purpose' => null];
    $first = StatementImport::read(CsvStatement::parse("Datum;Name;Betrag\n01.02.2026;Cafe;-3,50\n01.02.2026;Cafe;-3,50\nSumme;;-7,00\n"), $columns);
    $second = StatementImport::read(CsvStatement::parse("Datum;Name;Betrag\n01.02.2026;CAFE;-3,50\n"), $columns);

    expect($first['lines'])->toHaveCount(2)
        ->and($first['unreadable'])->toBe(1)
        ->and($first['lines'][0]['fingerprint'])->not->toBe($first['lines'][1]['fingerprint'])
        ->and($second['lines'][0]['fingerprint'])->toBe($first['lines'][0]['fingerprint']);
});

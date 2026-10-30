<?php

declare(strict_types=1);

use App\Domain\Banking\CsvStatement;

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
    ['31.02.2026', null],
    ['morgen', null],
]);

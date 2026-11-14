<?php

declare(strict_types=1);

use App\Domain\Properties\PropertyValues;

/*
| Points 122.1, 165: property values in German and English formats.
*/

it('reads amounts into minor units and a currency', function (string $raw, ?array $expected): void {
    expect(PropertyValues::parseAmount($raw))->toBe($expected);
})->with([
    ['1.234,56 €', [123456, 'EUR']],
    ['12,50', [1250, 'EUR']],
    ['-12,50 EUR', [-1250, 'EUR']],
    ['USD 3.5', [350, 'USD']],
    ['1.234', [123400, 'EUR']],
    ['12,345', [1234500, 'EUR']],
    ['1,2,3', null],
    ['1.234.567,89', [123456789, 'EUR']],
    ['12,3456', null],
    ['abc', null],
    ['1,234.56', [123456, 'EUR']],
    ['1 234,56', [123456, 'EUR']],
    ['1234.5', [123450, 'EUR']],
    ['-12,00', [-1200, 'EUR']],
    ['(12,00)', [-1200, 'EUR']],
    ['(12,00) CHF', [-1200, 'CHF']],
    ['$1,234.56', [123456, 'USD']],
    ['£ 5', [500, 'GBP']],
    ['EUR12,00', [1200, 'EUR']],
    ['12,-', [1200, 'EUR']],
]);

it('reads dates', function (string $raw, ?string $expected): void {
    expect(PropertyValues::parseDate($raw))->toBe($expected);
})->with([
    ['12.03.2026', '2026-03-12'],
    ['12.3.26', '2026-03-12'],
    ['2026-03-12', '2026-03-12'],
    ['31.02.2026', null],
    ['12/2026', null],
    ['17 Oct 2025', '2025-10-17'],
    ['17. Oktober 2025', '2025-10-17'],
    ['Oct 17, 2025', '2025-10-17'],
    ['October 17 2025', '2025-10-17'],
    ['17-Oct-25', '2025-10-17'],
    ['17/10/2025', '2025-10-17'],
    ['17.10.25', '2025-10-17'],
    ['3. Mai 2021', '2021-05-03'],
    ['1. März 2024', '2024-03-01'],
    ['1. Maerz 2024', '2024-03-01'],
    ['Sept. 3rd, 2024', '2024-09-03'],
    ['Fri, 17 Oct 2025', '2025-10-17'],
    ['03/04/2025', '2025-04-03'],
    ['17 Foo 2025', null],
]);

it('reads an ambiguous numeric date in every valid order, day first', function (): void {
    expect(PropertyValues::dateReadings('03/04/2021'))->toBe(['dmy' => '2021-04-03', 'mdy' => '2021-03-04'])
        ->and(PropertyValues::parseDate('03/04/2021', 'mdy'))->toBe('2021-03-04')
        ->and(PropertyValues::dateReadings('13/04/2021'))->toBe(['dmy' => '2021-04-13'])
        ->and(PropertyValues::dateReadings('03.04.2021'))->toBe(['dmy' => '2021-04-03'])
        ->and(PropertyValues::dateReadings('05/05/2021'))->toBe(['dmy' => '2021-05-05'])
        ->and(array_keys(PropertyValues::dateReadings('03/04/21')))->toBe(['dmy', 'mdy', 'ymd']);
});

it('checks IBANs with mod-97', function (): void {
    expect(PropertyValues::parseIban('de89 3704 0044 0532 0130 00'))->toBe('DE89370400440532013000')
        ->and(PropertyValues::parseIban('DE88 3704 0044 0532 0130 00'))->toBeNull()
        ->and(PropertyValues::parseIban('DE00 0000 0000 0000 0000 00'))->toBeNull();
});

it('reads numbers times 10000 and writes them back in German', function (): void {
    expect(PropertyValues::parseNumber('1.234,5'))->toBe(12345000)
        ->and(PropertyValues::parseNumber('0,0001'))->toBe(1)
        ->and(PropertyValues::parseNumber('1,23456'))->toBeNull()
        ->and(PropertyValues::formatNumber(12345000))->toBe('1.234,5')
        ->and(PropertyValues::formatAmount(-123456))->toBe('-1.234,56 €');
});

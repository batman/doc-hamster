<?php

declare(strict_types=1);

use App\Domain\Ocr\Invoice\DateReader;
use App\Domain\Ocr\Invoice\InvoiceLines;
use App\Domain\Ocr\InvoiceReader;
use Carbon\CarbonImmutable;

/*
| Points 234, A398-A400, A402: invoice and due date in many formats and languages, and how candidates rank.
| Synthetic documents only.
*/

beforeEach(fn () => CarbonImmutable::setTestNow('2026-02-10 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function readDate(string $text): ?string
{
    return (new InvoiceReader)->read(null, $text)->invoiceDate?->value;
}

/**
 * Words at given x positions, one row per line: [[text, x], …].
 *
 * @param  list<list<array{string, float}>>  $rows
 * @return array{pages: list<array{n: int, words: list<array<string, mixed>>}>}
 */
function dateColumns(array $rows): array
{
    $words = [];
    foreach ($rows as $l => $row) {
        foreach ($row as [$text, $x]) {
            foreach (explode(' ', $text) as $i => $t) {
                $words[] = ['t' => $t, 'x' => $x + $i * 0.06, 'y' => 0.1 + $l * 0.03, 'w' => 0.05, 'h' => 0.012, 'c' => 95, 'l' => $l, 'b' => 0];
            }
        }
    }

    return ['pages' => [['n' => 1, 'words' => $words]]];
}

it('reads the date format', function (string $text, string $date, string $order = 'dmy'): void {
    expect(DateReader::dates($text, $order)[0][0] ?? null)->toBe($date);
})->with([
    'DD.MM.YYYY' => ['Rechnungsdatum: 06.02.2026', '2026-02-06'],
    'D.M.YY' => ['Datum 6.2.26', '2026-02-06'],
    'spaced dots' => ['06. 02. 2026', '2026-02-06'],
    'DD/MM/YYYY' => ['06/02/2026', '2026-02-06'],
    'MM/DD/YYYY' => ['02/06/2026', '2026-02-06', 'mdy'],
    'DD-MM-YYYY' => ['06-02-2026', '2026-02-06'],
    'YYYY-MM-DD' => ['2026-02-06', '2026-02-06'],
    'YYYY/MM/DD' => ['2026/02/06', '2026-02-06'],
    'YYYY.MM.DD' => ['2026.02.06', '2026-02-06'],
    'ISO with time' => ['2026-02-06T10:15:00Z', '2026-02-06'],
    'German month' => ['6. Februar 2026', '2026-02-06'],
    'short month' => ['6 Feb 2026', '2026-02-06'],
    'dashed month' => ['06-Feb-2026', '2026-02-06'],
    'compact month' => ['6FEB26', '2026-02-06'],
    'month first' => ['Feb 6, 2026', '2026-02-06'],
    'month first ordinal' => ['February 6th, 2026', '2026-02-06'],
    'month first dotted' => ['Feb. 6th, 2026', '2026-02-06'],
    'weekday German' => ['Freitag, 6. Februar 2026', '2026-02-06'],
    'weekday English' => ['Friday, February 6, 2026', '2026-02-06'],
    '1st' => ['1st March 2026', '2026-03-01'],
    '2nd' => ['2nd March 2026', '2026-03-02'],
    '3rd' => ['3rd March 2026', '2026-03-03'],
    '1er' => ['1er février 2026', '2026-02-01'],
    'French' => ['6 février 2026', '2026-02-06'],
    'Spanish' => ['6 de febrero de 2026', '2026-02-06'],
    'Italian' => ['6 febbraio 2026', '2026-02-06'],
    'Dutch' => ['6 maart 2026', '2026-03-06'],
    'Portuguese' => ['6 de março de 2026', '2026-03-06'],
    'OCR O for 0' => ['O6.O2.2O26', '2026-02-06'],
    'OCR l for 1' => ['0l.02.2026', '2026-02-01'],
    'OCR comma' => ['06,02,2026', '2026-02-06'],
    'OCR spaces' => ['06 .02 .2026', '2026-02-06'],
]);

it('takes no month-year, digit run or number part for a date', function (string $text): void {
    expect(DateReader::dates($text))->toBe([]);
})->with([
    'month and year' => ['Februar 2026'],
    'month/year' => ['Leistung 02/2026'],
    'digit run' => ['Auftrag 20260206'],
    'invoice number' => ['RE-2026-0206'],
    'amounts' => ['12,50 13,00'],
]);

it('never takes a range as the invoice date', function (): void {
    expect(readDate("Zeitraum 01.01.–31.01.2026\nMuster GmbH\n\n\n\nText 06.02.2026"))->toBe('2026-02-06')
        ->and(readDate("Zeitraum 01.01.2026 - 31.01.2026\nText 06.02.2026"))->toBe('2026-02-06');
});

it('reads the issue date by its label in many languages, over delivery and order dates', function (string $label): void {
    $r = (new InvoiceReader)->read(null, "Lieferdatum: 01.02.2026\nBestelldatum: 30.01.2026\n{$label}");
    expect($r->invoiceDate?->value)->toBe('2026-02-06')->and($r->invoiceDate?->confidence)->toBeGreaterThanOrEqual(0.9);
})->with([
    'Rechnungsdatum: 06.02.2026', 'Datum: 06.02.2026', 'Belegdatum 06.02.2026', 'Ausstellungsdatum 06.02.2026',
    'ausgestellt am 6. Februar 2026', 'Invoice Date: 06/02/2026', 'Date of Issue: 6 Feb 2026', 'Issue Date 06.02.2026',
    'Issued: 6 Feb 2026', 'Billed On 6 Feb 2026', 'Dated 06.02.2026', 'Date de facture : 06/02/2026',
    'Fecha de emisión: 06/02/2026', 'Fecha de factura 06/02/2026', 'Data fattura 06/02/2026', 'Factuurdatum 06-02-2026',
]);

it('never takes a paid, Skonto or other date for the issue date', function (string $label): void {
    expect(readDate("{$label} 01.02.2026\nMuster GmbH\n\n\n\nText 06.02.2026"))->toBe('2026-02-06');
})->with([
    'bezahlt am', 'Paid on', 'Payment Date', 'Abrechnungszeitraum', 'Billing period', 'Gedruckt am', 'Printed on',
    'Gültig bis', 'Valid until', 'Kunde seit', 'Customer since', 'Geburtsdatum', 'Date of birth', 'Steuerjahr', 'Skonto bis',
]);

it('reads the label on the line above, as a column header and two columns on one line', function (): void {
    expect(readDate("Rechnungsdatum\n06.02.2026"))->toBe('2026-02-06')
        ->and(readDate("Lieferdatum Rechnungsdatum\n01.02.2026 06.02.2026"))->toBe('2026-02-06');

    $words = dateColumns([[['Lieferdatum', 0.1], ['Rechnungsdatum', 0.6]], [['01.02.2026', 0.1], ['06.02.2026', 0.6]]]);
    expect((new InvoiceReader)->read($words)->invoiceDate?->value)->toBe('2026-02-06');

    $r = (new InvoiceReader)->read(null, "Billed On   Feb 6, 2026   Due On   Feb 20, 2026\nPAID on Feb 9, 2026");
    expect($r->invoiceDate?->value)->toBe('2026-02-06')->and($r->dueDate?->value)->toBe('2026-02-20');
});

it('ranks a receipt date with time above other unlabelled dates', function (): void {
    expect(readDate("Muster Markt\nAktion bis 28.02.2026\nBrot 2,40\nSUMME 2,40\n\n\n06.02.2026 10:15 Bon 123"))->toBe('2026-02-06');
});

it('ranks an unlabelled date high on page 1 near the invoice word, then earlier and repeated dates', function (): void {
    expect(readDate("Muster GmbH\nRechnung Nr. 4711\n06.02.2026\nText\nText\nText\nText\nText\nHinweis 01.02.2026"))->toBe('2026-02-06');

    $filler = "Muster GmbH\nText\nText\nText\nText\nText\n";
    expect(readDate($filler."Text 03.02.2026\nText 05.02.2026"))->toBe('2026-02-03')
        ->and(readDate($filler."Text 03.02.2026\nText 05.02.2026\nText 05.02.2026"))->toBe('2026-02-05');
});

it('falls back to the delivery date, then the order date', function (): void {
    expect(readDate("Bestelldatum: 01.02.2026\nLieferdatum: 03.02.2026"))->toBe('2026-02-03')
        ->and(readDate("Muster GmbH\nBestelldatum: 01.02.2026"))->toBe('2026-02-01');
});

it('penalises dates far in the future, before 2000 and next to another label', function (): void {
    expect(readDate("20.06.2026\nText\nText 06.02.2026"))->toBe('2026-02-06')
        ->and(readDate("01.01.1999\nText\nText 06.02.2026"))->toBe('2026-02-06')
        ->and(readDate("Lieferdatum 01.02.2026 02.02.2026\nText\nText\nText\nText 06.02.2026"))->toBe('2026-02-06');
});

it('takes the next candidate when the due date lies before the chosen one', function (): void {
    expect(readDate("06.03.2026\nFällig am: 20.02.2026\nText\nText 06.02.2026"))->toBe('2026-02-06');
});

it('reads slash dates by a part above 12, the document, US hints, else day first', function (): void {
    expect(readDate('Datum 02/13/2026'))->toBe('2026-02-13')
        ->and(readDate("Datum 01/02/2026\nLieferung 13/01/2026"))->toBe('2026-02-01')
        ->and(readDate("Lieferung 01/13/2026\nDatum 01/02/2026"))->toBe('2026-01-02')
        ->and(readDate("Invoice\nInvoice Date 02/06/2026\nAmount Due \$1,234.56"))->toBe('2026-02-06')
        ->and(readDate("Rechnung\nDatum 06/02/2026\nGesamtbetrag 10,00 €"))->toBe('2026-02-06')
        ->and(readDate('Datum 06/02/2026'))->toBe('2026-02-06');
});

it('reads the due date by its label', function (string $due): void {
    expect((new InvoiceReader)->read(null, "Rechnungsdatum 06.02.2026\n{$due}")->dueDate?->value)->toBe('2026-02-20');
})->with(['Due Date: 20.02.2026', 'Zahlbar bis 20.02.2026', 'Payment Due Feb 20, 2026', 'Fällig am 20.02.2026', 'Fälligkeitsdatum 20.02.2026']);

it('keeps the slash order for all dates of a document', function (): void {
    $lines = InvoiceLines::fromText("Invoice\nTotal \$5.00\n02/06/2026");
    expect(DateReader::slashOrder($lines))->toBe('mdy')
        ->and(DateReader::slashOrder(InvoiceLines::fromText("Rechnung\n02/06/2026")))->toBe('dmy');
});

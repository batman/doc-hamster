<?php

declare(strict_types=1);

use App\Domain\Ocr\Invoice\AmountParser;
use App\Domain\Ocr\InvoiceProposal;
use App\Domain\Ocr\InvoiceReader;

/*
| Points 235, A398-A400, A402: the invoice amount in many formats, how candidates rank and how sure the reader is.
| Synthetic documents only.
*/

function readAmount(string $text): ?InvoiceProposal
{
    return (new InvoiceReader)->read(null, $text)->amount;
}

it('reads the amount format', function (string $text, int $minor, ?string $currency): void {
    $found = AmountParser::scan($text, ',');
    expect($found[0]['minor'] ?? null)->toBe($minor)->and($found[0]['currency'] ?? null)->toBe($currency);
})->with([
    'German with thousands' => ['1.234,56 €', 123456, 'EUR'],
    'code behind' => ['1234,56 EUR', 123456, 'EUR'],
    'symbol in front' => ['€ 1.234,56', 123456, 'EUR'],
    'dash for cents' => ['1.234,- €', 123400, 'EUR'],
    'English euro' => ['€1,234.56', 123456, 'EUR'],
    'dollar' => ['$1,234.56', 123456, 'USD'],
    'Swiss apostrophe' => ["CHF 1'234.56", 123456, 'CHF'],
    'space thousands' => ['1 234,56 €', 123456, 'EUR'],
    'no thousands' => ['1234.56', 123456, null],
    'code lower case' => ['12,50 eur', 1250, 'EUR'],
    'pound' => ['£12.50', 1250, 'GBP'],
    'GBP behind' => ['12.50 gbp', 1250, 'GBP'],
    'whole with currency' => ['500 €', 50000, 'EUR'],
    'minus in front' => ['-12,50 €', -1250, 'EUR'],
    'minus behind' => ['12,50- €', -1250, 'EUR'],
    'brackets' => ['(12.50)', -1250, null],
    'OCR O and l' => ['l2,5O €', 1250, 'EUR'],
    'OCR space' => ['12 ,50 €', 1250, 'EUR'],
    'OCR € as E' => ['12,50 E', 1250, 'EUR'],
]);

it('takes no percentage, bare whole number, date, IBAN or unit price for an amount', function (string $text): void {
    expect(AmountParser::scan($text, ','))->toBe([]);
})->with(['19 %', 'Menge 500', '06.02.2026', 'DE89 3704 0044 0532 0130 00', 'Diesel 1,789 €/l']);

it('ranks zu zahlen over Gesamtbetrag over Summe, and a labelled total is sure', function (): void {
    $a = readAmount("Summe 100,00 €\nGesamtbetrag 119,00 €\nZu zahlen 99,00 €");
    expect($a?->minor)->toBe(9900)->and($a?->confidence)->toBeGreaterThanOrEqual(0.9)
        ->and(readAmount("Summe 100,00 €\nGesamtbetrag 119,00 €")?->minor)->toBe(11900)
        ->and(readAmount("Total 80.00\nAmount Due 75.00")?->minor)->toBe(7500)
        ->and(readAmount('Grand Total $1,234.56')?->currency)->toBe('USD');
});

it('takes the SUMME of a receipt, not the cash given or the change, and a card payment confirms it', function (): void {
    expect(readAmount("Muster Markt\nBrot 2,40\nMilch 1,20\nSUMME EUR 3,60\nBar 10,00\nRückgeld 6,40")?->minor)->toBe(360);

    $card = readAmount("Muster Markt\nBrot 2,40\nSUMME 2,40\nEC-Karte 2,40");
    expect($card?->minor)->toBe(240)->and($card?->confidence)->toBeGreaterThanOrEqual(0.9);
});

it('prefers an amount in larger print', function (): void {
    $rows = ['Muster GmbH', 'Ihr Betrag', '120,00 €', 'Text', 'Text', 'Text', 'Hinweis 200,00 €'];
    $words = [];
    foreach ($rows as $l => $row) {
        foreach (explode(' ', $row) as $i => $t) {
            $words[] = ['t' => $t, 'x' => 0.05 + $i * 0.1, 'y' => 0.05 + $l * 0.12, 'w' => 0.08, 'h' => $l === 2 ? 0.03 : 0.012, 'c' => 95, 'l' => $l, 'b' => 0];
        }
    }
    expect((new InvoiceReader)->read(['pages' => [['n' => 1, 'words' => $words]]])->amount?->minor)->toBe(12000);
});

it('falls back to the largest amount low on the last page, then the largest at all', function (): void {
    $low = readAmount("Muster GmbH\nText 500,00\nText\nText\nText\nText\n120,00 €\n80,00 €");
    expect($low?->minor)->toBe(12000)->and($low?->confidence)->toBeLessThan(0.9)
        ->and(readAmount("Muster GmbH\nText 30,00\nText 50,00\nText\nText\nText\nText\nText")?->minor)->toBe(5000);
});

it('is sure when net and VAT add up, and takes the next candidate when they do not', function (): void {
    $sure = readAmount("Netto 100,00 €\nMwSt 19 % 19,00 €\nBrutto 119,00 €");
    expect($sure?->minor)->toBe(11900)->and($sure?->confidence)->toBeGreaterThanOrEqual(0.95)
        ->and(readAmount("Netto 100,00 €\nMwSt 19 % 19,00 €\nGesamt 129,00 €\nBetrag 119,00 €")?->minor)->toBe(11900)
        ->and(readAmount('Netto 340,00 MwSt 64,60 Brutto 404,60')?->minor)->toBe(40460)
        ->and(readAmount("Nettobetrag 300,00 €\nMwSt 57,00 €\nGesamtbetrag 357,00 €\nabzgl. Anzahlung 100,00 €\nZu zahlen 257,00 €")?->confidence)->toBeGreaterThanOrEqual(0.95);

    $mismatch = readAmount("Netto 100,00 €\nMwSt 19,00 €\nGesamtbetrag 125,00 €");
    expect($mismatch?->minor)->toBe(12500)->and($mismatch?->confidence)->toBeLessThan(0.9);
});

it('raises the confidence when the line items add up or the amount repeats', function (): void {
    expect(readAmount("Pos Menge Preis Betrag\n1 2 10,00 20,00\n2 1 30,00 30,00\nSumme 50,00 €")?->confidence)->toBeGreaterThanOrEqual(0.9)
        ->and(readAmount("Summe 50,00 €\nBezahlt 50,00 €")?->confidence)->toBeGreaterThanOrEqual(0.9)
        ->and(readAmount('Summe 50,00 €')?->confidence)->toBeLessThan(0.9);
});

it('never takes Skonto, deposit, change, Pfand, tip, balance or a credit as the total', function (): void {
    expect(readAmount("Pfand 0,25\nTrinkgeld 5,00\nSaldo 900,00\nGuthaben 800,00\nSumme 12,00")?->minor)->toBe(1200)
        ->and(readAmount("Gesamtbetrag 200,00 €\nbei Zahlung bis 10.02.2026 mit 2 % Skonto 196,00 €")?->minor)->toBe(20000)
        ->and(readAmount("Gutschrift -50,00 €\nGesamtbetrag 200,00 €")?->minor)->toBe(20000);
});

it('lowers amounts in number lines and falls back to the net amount', function (): void {
    expect(readAmount("Muster GmbH\nRechnungsnr. 2026,15\nText 50,00 €")?->minor)->toBe(5000);

    $net = readAmount("Nettobetrag 100,00 €\nVersand frei");
    expect($net?->minor)->toBe(10000)->and($net?->confidence)->toBeLessThan(0.9);
});

it('stores the currency with the amount: its own, the document\'s, else EUR', function (): void {
    expect(readAmount("Subtotal 90.00 USD\nTotal 99.00")?->currency)->toBe('USD')
        ->and(readAmount('Total 99,00')?->currency)->toBe('EUR')
        ->and(readAmount('Gesamtbetrag CHF 1\'234.50')?->minor)->toBe(123450);
});

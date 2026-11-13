<?php

declare(strict_types=1);

use App\Domain\Ocr\InvoiceReader;

/*
| Points 120, 120.1-120.4, A236, A237, A247, A248, A250, A251: reading a synthetic German invoice.
*/

/**
 * Words like OcrWords JSON from text lines: one line per row, words left to right, rows spread over the page.
 *
 * @param  list<string>  $lines
 * @return array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}
 */
function invoiceWords(array $lines): array
{
    $words = [];
    $step = 0.9 / max(1, count($lines));
    foreach ($lines as $l => $line) {
        $x = 0.05;
        foreach (preg_split('/ /u', $line) ?: [] as $t) {
            if ($t === '') {
                continue;
            }
            $w = round(mb_strlen($t) * 0.011, 4);
            $words[] = ['t' => $t, 'x' => round($x, 4), 'y' => round(0.03 + $l * $step, 4), 'w' => $w, 'h' => 0.012, 'c' => 95, 'l' => $l, 'b' => 0];
            $x += $w + 0.01;
        }
    }

    return ['v' => 1, 'source' => 'tesseract', 'pages' => [['n' => 1, 'words' => $words]]];
}

function sampleInvoice(): array
{
    return [
        'Muster Handwerk GmbH · Werkstraße 5 · 12345 Musterstadt',
        'Max Mustermann',
        'Beispielweg 1',
        '54321 Beispielstadt',
        'Rechnung',
        'Rechnungsnummer: RE-2026-0042 Kunden-Nr.: 4711',
        'Rechnungsdatum: 03.11.2026',
        'Pos Menge Beschreibung Einzelpreis Gesamt',
        '1 2 Montage 50,00 € 100,00 €',
        '2 1 Material 250,00 € 250,00 €',
        'Zwischensumme 350,00 €',
        'Rabatt 10,00 €',
        'Nettobetrag 340,00 €',
        'zzgl. 19 % MwSt 64,60 €',
        'Gesamtbetrag 404,60 €',
        'Zahlbar innerhalb 14 Tagen ohne Abzug.',
        'Bei Zahlung bis 10.11.2026 gewähren wir 3 % Skonto.',
        'Bankverbindung: Musterbank',
        'Kontoinhaber: Muster Handwerk GmbH',
        'IBAN: DE89 3704 0044 0532 0130 00 BIC: COBADEFFXXX',
    ];
}

it('reads amount, numbers, dates, Skonto, payee and IBAN from a German invoice', function (): void {
    $r = (new InvoiceReader)->read(invoiceWords(sampleInvoice()));

    expect($r->amount?->minor)->toBe(40460)->and($r->amount?->currency)->toBe('EUR')
        ->and($r->amount?->confidence)->toBeGreaterThanOrEqual(0.95)   // 340,00 + 64,60 = 404,60
        ->and($r->amount?->page)->toBe(1)->and($r->amount?->box)->not->toBeNull()
        ->and(array_map(fn ($p) => $p->minor, $r->amounts))->toContain(34000)->not->toContain(6460)
        ->and($r->invoiceNumber?->value)->toBe('RE-2026-0042')
        ->and($r->customerNumber?->value)->toBe('4711')
        ->and($r->invoiceDate?->value)->toBe('2026-11-03')
        ->and($r->dueDate?->value)->toBe('2026-11-17')
        ->and($r->discountPercent?->value)->toBe('3')
        ->and($r->discountUntil?->value)->toBe('2026-11-10')
        ->and($r->sender?->value)->toBe('Muster Handwerk GmbH')
        ->and($r->iban?->value)->toBe('DE89370400440532013000')
        ->and($r->bic?->value)->toBe('COBADEFFXXX')
        ->and($r->currency)->toBe('EUR')
        ->and(array_keys($r->suggestions()))->toContain('iban', 'invoice_amount', 'due_date', 'discount_until');
});

it('weighs a keyword above the largest amount and falls back to the lowest large amount', function (): void {
    $keyword = (new InvoiceReader)->read(null, "Rechnung\nArtikel 1.200,00 €\nGutschrift 1.500,00 €\nZu zahlen: 980,00 €\nSonstiges 2.000,00");
    expect($keyword->amount?->minor)->toBe(98000);

    $plain = (new InvoiceReader)->read(null, "Rechnung\nKaffee 3,50\nKuchen 4,20\n\n\n\n\n12,40 EUR");
    expect($plain->amount?->minor)->toBe(1240)->and($plain->amount?->confidence)->toBeLessThan(0.6);
});

it('skips the own IBAN and repairs an OCR swap only when it is the one valid candidate (A247)', function (): void {
    $text = "IBAN: DE89 3704 0044 0532 0130 00\nUnser Konto: DE02 1203 0000 0000 2020 51";
    $reader = new InvoiceReader(['DE89370400440532013000']);
    expect($reader->read(null, $text)->iban?->value)->toBe('DE02120300000000202051');

    $swap = (new InvoiceReader)->read(null, 'IBAN: DE89 37O4 OO44 O532 Ol3O 00');
    expect($swap->iban)->toBeNull()
        ->and($swap->ibanSwap?->value)->toBe('DE89370400440532013000')
        ->and($swap->ibanSwap?->original)->toBe('DE89 37O4 OO44 O532 Ol3O 00');
    expect(InvoiceReader::repair('DE8937040044053201300O'))->toBe('DE89370400440532013000');
});

it('prefers what a sender taught before (A236)', function (): void {
    $text = "Muster Strom AG\nAbschlag 50,00 €\nRechnungsbetrag 120,00 €\nGuthaben aus Vorjahr 70,00 €";
    $hints = fn (string $sender): ?array => $sender === 'Muster Strom AG' ? ['amount_keyword' => 'abschlag'] : null;

    expect((new InvoiceReader)->read(null, $text)->amount?->minor)->toBe(12000)
        ->and((new InvoiceReader([], $hints))->read(null, $text)->amount?->minor)->toBe(5000);
});

it('reads a due date and Skonto counted in days, and a foreign currency (A250, A251)', function (): void {
    $r = (new InvoiceReader)->read(null, "Datum: 01.12.2026\nGesamtbetrag 99,00 USD\nInnerhalb von 7 Tagen 2,5 % Skonto, 30 Tage netto");

    expect($r->dueDate?->value)->toBe('2026-12-31')
        ->and($r->discountPercent?->value)->toBe('2,5')
        ->and($r->discountUntil?->value)->toBe('2026-12-08')
        ->and($r->amount?->currency)->toBe('USD')->and($r->currency)->toBe('USD');
});

it('skips label and term lines as sender and takes the letterhead (point 133)', function (): void {
    $english = [
        'Terms : Due on Receipt',
        'Northwind Traders Ltd',
        'Invoice # INV-000123',
        'Bill To',
        'Max Mustermann',
        '1 Example Street, London',
        'Item Qty Rate Amount',
        'Consulting 1 500.00 500.00',
        'Total 500.00',
        'Balance Due 500.00',
    ];
    $r = (new InvoiceReader)->read(invoiceWords($english));
    expect($r->sender?->value)->toBe('Northwind Traders Ltd')->and($r->sender?->confidence)->toBe(0.65);

    $plain = (new InvoiceReader)->read(null, "Datum: 01.12.2026\nTerms : Due on Receipt\nRechnung Nr. 4711\nKunde: Max Mustermann");
    expect($plain->sender)->toBeNull();
});

it('prefers the large letterhead name and an upper-case legal form (point 133)', function (): void {
    $words = invoiceWords(['Terms : Due on Receipt', 'Thank you for your business', 'Total 99.00']);
    // The letterhead in large print, below a small terms line.
    $words['pages'][0]['words'][] = ['t' => 'ACME', 'x' => 0.05, 'y' => 0.2, 'w' => 0.1, 'h' => 0.03, 'c' => 95, 'l' => 9, 'b' => 1];
    $words['pages'][0]['words'][] = ['t' => 'LTD', 'x' => 0.17, 'y' => 0.2, 'w' => 0.06, 'h' => 0.03, 'c' => 95, 'l' => 9, 'b' => 1];

    $r = (new InvoiceReader)->read($words);
    expect($r->sender?->value)->toBe('ACME LTD')->and($r->sender?->confidence)->toBe(0.75);
});

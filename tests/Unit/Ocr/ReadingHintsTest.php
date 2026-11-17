<?php

declare(strict_types=1);

use App\Domain\Ocr\Invoice\FileNameHints;
use App\Domain\Ocr\InvoiceProposal;
use App\Domain\Ocr\InvoiceReader;
use Carbon\CarbonImmutable;

/*
| A40-A44: the own company is never the sender, the total wins over article numbers and line items, the account's
| currency is the default, the file name and the upload day check what was read. Synthetic documents only.
*/

it('never takes the own company as sender', function (): void {
    $text = "Musterhandel Ltd\nMarktstr. 1, 1000 Musterstadt\nCASH INVOICE\nMUSTERFIRMA 10 LIMITED\nHauptstr. 2\n8000 Musterort\nTotal: 12,50";
    $sender = (new InvoiceReader(ownNames: ['Musterfirma Two Ltd']))->read(null, $text)->sender;

    expect($sender?->value)->toBe('Musterhandel Ltd');
});

it('reads the account name of the bank block as sender', function (): void {
    $text = "Thank you\nTotal: €10.00\nACCOUNT NAME : MUSTER GARAGE LTD - IBAN DE00 0000 0000 0000 0000 00";

    expect((new InvoiceReader)->read(null, $text)->sender?->value)->toBe('MUSTER GARAGE LTD');
});

it('prefers the repeated till total over an article number with a stray currency sign', function (): void {
    $text = "Tablet 11\" WiFi \"45210 \$ Grey\n555111 1 399,90 0,00 0% 399,90\nGesamt 399,90\nKarte 399,90\n0% 399,90 0,00";
    $reading = (new InvoiceReader)->read(null, $text);

    expect($reading->amount?->minor)->toBe(39990)->and($reading->currency)->toBe('EUR');
});

it('takes the gross of an unlabelled net, tax, gross line', function (): void {
    $text = "Waschmaschine 1 500,00 420,17\nService 1 79,83\n420,17 79,83 500,00\n210,00 £";

    expect((new InvoiceReader)->read(null, $text)->amount?->minor)->toBe(50000);
});

it('reads a Greek total label', function (): void {
    expect((new InvoiceReader)->read(null, "Είδος 1 10,00\nΣύνολο 25,50\nΜετρητά 30,00")->amount?->minor)->toBe(2550);
});

it('uses the account currency when the document names none twice', function (): void {
    expect((new InvoiceReader(currency: 'CHF'))->read(null, 'Total 12.50')->currency)->toBe('CHF');
});

it('reads date and amount from a file name', function (string $name, ?string $date, ?int $minor): void {
    expect(FileNameHints::date($name))->toBe($date)->and(FileNameHints::amount($name))->toBe($minor);
})->with([
    'export name' => ['2025-03-14 Sonstige Fahrzeugkosten  120.0 100000000000000001.pdf', '2025-03-14', 12000],
    'two decimals' => ['2025-04-02 Rechts- und Beratungskosten  1234.56 1000001.pdf', '2025-04-02', 123456],
    'invoice with date' => ['INV-00020 Muster 2026-11-01.pdf', '2026-11-01', null],
    'print date is no invoice date' => ['Druck vom 13.11.2026 07:55', null, null],
    'amount in brackets' => ['Gebühr (EUR 45.50).pdf', null, 4550],
    'nothing' => ['Rechnung MX12345.pdf', null, null],
]);

it('checks what was read against the file name and the upload day', function (): void {
    $uploaded = CarbonImmutable::parse('2026-11-07');
    $read = [
        'invoice_date' => new InvoiceProposal('2005-03-14', 0.8),
        'invoice_amount' => new InvoiceProposal('18,00 €', 0.9, minor: 1800, currency: 'EUR'),
    ];

    $named = FileNameHints::apply($read, '2025-03-14 Kosten 120.0 123.pdf', $uploaded, 'EUR');
    expect($named['invoice_date']->value)->toBe('2025-03-14')
        ->and($named['invoice_amount']->minor)->toBe(12000)
        ->and($named['invoice_amount']->confidence)->toBe(FileNameHints::NAME);

    $plain = FileNameHints::apply($read, 'scan.pdf', $uploaded, 'EUR');
    expect($plain['invoice_date']->confidence)->toBe(FileNameHints::DOUBT)
        ->and($plain['invoice_amount']->confidence)->toBe(0.9);

    $agree = FileNameHints::apply(['invoice_amount' => new InvoiceProposal('120,00 €', 0.5, minor: 12000, currency: 'EUR')], 'x 120.0.pdf', $uploaded, 'EUR');
    expect($agree['invoice_amount']->confidence)->toBe(FileNameHints::AGREE);
});

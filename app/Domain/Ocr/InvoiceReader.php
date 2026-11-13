<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use App\Domain\Ocr\Invoice\AmountReader;
use App\Domain\Ocr\Invoice\DateReader;
use App\Domain\Ocr\Invoice\IbanReader;
use App\Domain\Ocr\Invoice\InvoiceLines;
use App\Domain\Ocr\Invoice\NumberReader;
use App\Domain\Ocr\Invoice\SenderReader;
use App\Domain\Ocr\Invoice\SkontoReader;
use Closure;

/**
 * Reads what a transfer needs from a recognised invoice (points 120, 120.1-120.4, A236, A237, A247, A248, A250, A251):
 * IBAN (mod-97, the organisation's own skipped, an OCR swap O↔0, I/l↔1, S↔5, B↔8, Z↔2 repaired), BIC, the amount by
 * weight (Zahlbetrag … beats Brutto, beats the largest amount low on the page; Netto, MwSt, Zwischensumme, Rabatt and
 * line items lose; netto + tax = brutto makes it sure), invoice and customer number, invoice and due date ("zahlbar
 * bis …", "innerhalb N Tagen"), the payee (bank block, else the letterhead), Skonto and the currency. Pure: the words
 * (OcrWords JSON) or plain text in, proposals with confidence and place out. What a sender taught before (A236)
 * comes in through the `hints` closure: fn (string $sender): ?array{iban?: ?string, amount_keyword?: ?string,
 * amount_page?: ?int, amount_box?: ?array{x: float, y: float, w: float, h: float}}.
 *
 * A315: a facade over the small readers in App\Domain\Ocr\Invoice (IbanReader, AmountReader, DateReader, NumberReader,
 * SenderReader, SkontoReader). Point 133: the sender skips label and term lines and prefers the letterhead.
 */
final class InvoiceReader
{
    private readonly IbanReader $ibans;

    /**
     * @param  list<string>  $ownIbans  the organisation's own accounts – never the payee
     * @param  (Closure(string): ?array<string, mixed>)|null  $hints  A236: what a sender taught before
     */
    public function __construct(array $ownIbans = [], private readonly ?Closure $hints = null)
    {
        $this->ibans = new IbanReader($ownIbans);
    }

    /**
     * @param  array{pages: list<array{n?: int, words: list<array<string, mixed>>}>}|null  $words  OcrWords::forDocument()
     * @param  string  $text  plain text, used when there are no word positions
     * @param  string|null  $sender  a sender known already (a confirmed property) – used for the hints
     */
    public function read(?array $words, string $text = '', ?string $sender = null): InvoiceReading
    {
        $lines = $words !== null ? InvoiceLines::fromWords($words) : InvoiceLines::fromText($text);
        if ($lines === []) {
            return new InvoiceReading;
        }
        $currency = AmountReader::documentCurrency($lines);
        $invoiceDate = DateReader::invoiceDate($lines);
        $payee = SenderReader::read($lines);
        $name = $sender ?? $payee?->value;
        $hint = ($name !== null && $this->hints !== null) ? ($this->hints)($name) : null;
        $hint = is_array($hint) ? $hint : [];

        [$iban, $swap, $ibanLine] = $this->ibans->iban($lines, is_string($hint['iban'] ?? null) ? $hint['iban'] : null);
        [$amount, $others] = AmountReader::amounts($lines, $currency, $hint);
        [$percent, $until] = SkontoReader::read($lines, $invoiceDate);

        return new InvoiceReading(
            iban: $iban,
            ibanSwap: $swap,
            bic: IbanReader::bic($lines, $ibanLine),
            amount: $amount,
            amounts: $others,
            invoiceNumber: NumberReader::labelled($lines, NumberReader::INVOICE),
            customerNumber: NumberReader::labelled($lines, NumberReader::CUSTOMER),
            invoiceDate: $invoiceDate,
            dueDate: DateReader::dueDate($lines, $invoiceDate),
            sender: $payee,
            discountPercent: $percent,
            discountUntil: $until,
            currency: $amount->currency ?? $currency,
        );
    }

    /** A247: the one valid IBAN behind typical OCR swaps, else null. */
    public static function repair(string $compact): ?string
    {
        return IbanReader::repair($compact);
    }

    /**
     * What kind of amount a line holds, by its label: total, gross, net, tax, exclude or plain.
     *
     * @return array{string, int, string|null} kind, weight, keyword
     */
    public static function classify(string $text): array
    {
        return AmountReader::classify($text);
    }

    /**
     * The dates on a line as Y-m-d with their byte offsets.
     *
     * @return list<array{string, int, int}>
     */
    public static function dates(string $text): array
    {
        return DateReader::dates($text);
    }
}

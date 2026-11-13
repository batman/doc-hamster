<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

/**
 * What InvoiceReader found on one document (point 120): one proposal per field, the other amounts with their places
 * (120.4: offered as chips) and a repaired IBAN (A247) when the one on the document fails its check digits.
 */
final readonly class InvoiceReading
{
    /**
     * @param  list<InvoiceProposal>  $amounts  the other amounts, most likely first
     */
    public function __construct(
        public ?InvoiceProposal $iban = null,
        public ?InvoiceProposal $ibanSwap = null,
        public ?InvoiceProposal $bic = null,
        public ?InvoiceProposal $amount = null,
        public array $amounts = [],
        public ?InvoiceProposal $invoiceNumber = null,
        public ?InvoiceProposal $customerNumber = null,
        public ?InvoiceProposal $invoiceDate = null,
        public ?InvoiceProposal $dueDate = null,
        public ?InvoiceProposal $sender = null,
        public ?InvoiceProposal $discountPercent = null,
        public ?InvoiceProposal $discountUntil = null,
        public ?string $currency = null,
    ) {}

    /**
     * The proposals by property key (Property::DEFAULTS), for PropertyValues::set(..., 'suggested').
     *
     * @return array<string, InvoiceProposal>
     */
    public function suggestions(): array
    {
        return array_filter([
            'iban' => $this->iban,
            'bic' => $this->bic,
            'invoice_amount' => $this->amount,
            'invoice_number' => $this->invoiceNumber,
            'customer_number' => $this->customerNumber,
            'invoice_date' => $this->invoiceDate,
            'due_date' => $this->dueDate,
            'sender' => $this->sender,
            'discount_percent' => $this->discountPercent,
            'discount_until' => $this->discountUntil,
        ], static fn (?InvoiceProposal $p): bool => $p !== null);
    }
}

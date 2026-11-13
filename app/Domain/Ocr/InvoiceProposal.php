<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

/**
 * One value InvoiceReader read from a document (point 120): the raw text a property takes (PropertyValues::set), how
 * sure the reader is (0..1) and where it stands (page, box relative 0..1 like the OCR words; null without word positions).
 */
final readonly class InvoiceProposal
{
    /**
     * @param  array{x: float, y: float, w: float, h: float}|null  $box
     */
    public function __construct(
        public string $value,
        public float $confidence,
        public ?int $page = null,
        public ?array $box = null,
        /** Amounts: the value in minor units and its currency. */
        public ?int $minor = null,
        public ?string $currency = null,
        /** A247: the text on the document when the value is a repaired IBAN (OCR swap). */
        public ?string $original = null,
        /** Amounts: the label on the amount's line ("zahlbetrag"), learnt per sender (A236). */
        public ?string $keyword = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

/**
 * One picture page of a receipt in the pack: a temporary JPEG, or – when the receipt can't be shown as a picture –
 * the note that stands in its place (e.g. "Beleg ist ein PDF – Originaldatei liegt im Zugang bei").
 */
final readonly class PackPicture
{
    public function __construct(
        public string $title,
        public int $page,
        public int $pages,
        public ?string $jpeg = null,
        public ?string $note = null,
        public string $receiptId = '',
    ) {}
}

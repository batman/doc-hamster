<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

use App\Domain\Documents\PageTransform;

/**
 * One document in a receipt pack: its name and the stored files the pack turns into pictures (PackImages).
 */
final readonly class PackReceipt
{
    /**
     * @param  list<array{path: string, mime: string, transforms?: array<int, PageTransform>}>  $files
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $files = [],
        public ?string $date = null,
    ) {}
}

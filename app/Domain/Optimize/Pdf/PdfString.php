<?php

declare(strict_types=1);

namespace App\Domain\Optimize\Pdf;

/** Point 236: a PDF string, kept raw (the optimiser never needs its text). */
final readonly class PdfString
{
    public function __construct(public string $raw) {}
}

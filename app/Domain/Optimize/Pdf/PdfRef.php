<?php

declare(strict_types=1);

namespace App\Domain\Optimize\Pdf;

/** Point 236: an indirect reference "n g R" in a PDF. */
final readonly class PdfRef
{
    public function __construct(public int $num, public int $gen = 0) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Optimize\Pdf;

/**
 * Point 236: one page of a scan PDF – its boxes and rotation (kept as they are), its drawing (which places the picture
 * by a matrix, so a smaller picture lands on the same spot) and the JPEG picture itself.
 */
final readonly class ScanPage
{
    /**
     * @param  array{float, float, float, float}  $mediaBox
     * @param  array{float, float, float, float}|null  $cropBox
     */
    public function __construct(
        public array $mediaBox,
        public ?array $cropBox,
        public int $rotate,
        public string $content,
        public string $imageName,
        public string $jpeg,
        public int $width,
        public int $height,
    ) {}

    public function widthPt(): float
    {
        return abs($this->mediaBox[2] - $this->mediaBox[0]);
    }

    public function heightPt(): float
    {
        return abs($this->mediaBox[3] - $this->mediaBox[1]);
    }
}

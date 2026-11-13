<?php

declare(strict_types=1);

namespace App\Jobs\Local\Ocr;

use App\Domain\Ocr\OcrWords;
use App\Infrastructure\Environment\OcrProgress;
use RuntimeException;

/**
 * Reads one image on the server with Tesseract: at full resolution, and A276 once more scaled down to 2000 px on the
 * long side when the full read ran over its time limit (word positions are relative, so they fit the original).
 */
final class ImageReader
{
    /** A276: the long side of the downscaled retry. */
    public const int RETRY_SIDE = 2000;

    public function __construct(private readonly OcrTools $tools) {}

    /**
     * @return array{0: string, 1: array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null} text, words
     */
    public function read(string $path, string $documentId, Deadline $deadline): array
    {
        OcrProgress::page($documentId, 1, 1);
        [$text, $page] = $this->tools->tesseract($path, 1, $deadline);

        return [$text, OcrWords::make('tesseract', [$page])];
    }

    /** Whether the image is larger than the retry size and GD can scale it. */
    public static function canShrink(string $path): bool
    {
        $size = @getimagesize($path);

        return function_exists('imagescale') && $size !== false && max($size[0], $size[1]) > self::RETRY_SIDE;
    }

    /**
     * @return array{0: string, 1: array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null} text, words
     */
    public function readShrunk(string $path, string $documentId, Deadline $deadline): array
    {
        $small = self::shrink($path);
        try {
            return $this->read($small, $documentId, $deadline);
        } finally {
            OcrTools::unlink([$small]);
        }
    }

    /** A temporary PNG with the long side at RETRY_SIDE. */
    private static function shrink(string $path): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            throw new RuntimeException('gd: Bild nicht lesbar');
        }
        $factor = self::RETRY_SIDE / max(imagesx($image), imagesy($image));
        $small = imagescale($image, max(1, (int) round(imagesx($image) * $factor)), max(1, (int) round(imagesy($image) * $factor)));
        $file = tempnam(sys_get_temp_dir(), 'shr');
        if ($small === false || $file === false) {
            throw new RuntimeException('gd: Verkleinern fehlgeschlagen');
        }
        // Tesseract goes by the file's extension for some formats; the empty placeholder of tempnam goes.
        OcrTools::unlink([$file]);
        imagepng($small, $file.'.png');

        return $file.'.png';
    }
}

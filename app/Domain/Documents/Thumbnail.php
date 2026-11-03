<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Small preview of a file, made once and stored next to it (design point 14):
 * images with GD, the first page of a PDF only where Imagick exists. Without
 * one the list shows the type icon.
 */
final class Thumbnail
{
    private const SIZE = 480;

    public static function make(DocumentFile $file): ?string
    {
        $disk = Storage::disk('local');
        $source = $disk->path($file->path);

        try {
            $image = match (true) {
                $file->isImage() && function_exists('imagecreatefromstring') => @imagecreatefromstring((string) file_get_contents($source)),
                $file->mime === 'application/pdf' && class_exists(\Imagick::class) => self::firstPage($source),
                default => false,
            };
        } catch (Throwable) {
            $image = false;
        }
        if ($image === false) {
            return null;
        }

        $scale = min(1, self::SIZE / max(imagesx($image), imagesy($image)));
        $thumb = imagescale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
        if ($thumb === false) {
            return null;
        }

        $path = $file->path.'.thumb.jpg';
        ob_start();
        imagejpeg($thumb, null, 80);
        $disk->put($path, (string) ob_get_clean());

        return $path;
    }

    /** @return \GdImage|false */
    private static function firstPage(string $source): mixed
    {
        $pdf = new \Imagick;
        $pdf->setResolution(72, 72);
        $pdf->readImage($source.'[0]');
        $pdf->setImageFormat('png');

        return @imagecreatefromstring($pdf->getImageBlob());
    }
}

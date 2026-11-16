<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use Throwable;

/**
 * Point 238 (A415): a page picture as it is shown – crop first, then turn clockwise (PageTransform) – for the
 * thumbnail, the receipt pack and the text recognition. GD only; the stored file is never touched.
 */
final class PageImage
{
    public static function apply(\GdImage $image, PageTransform $transform): \GdImage
    {
        if ($transform->crop !== null) {
            [$x, $y, $w, $h] = $transform->cropPixels(imagesx($image), imagesy($image));
            $cropped = imagecrop($image, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
            $image = $cropped === false ? $image : $cropped;
        }
        if ($transform->rotation !== 0) {
            // imagerotate() turns counter-clockwise.
            $turned = imagerotate($image, 360 - $transform->rotation, 0);
            $image = $turned === false ? $image : $turned;
        }

        return $image;
    }

    /**
     * A temporary PNG of the shown page (a photo turned upright by its EXIF note first); null when GD can't read it.
     * The caller deletes it.
     */
    public static function shownPng(string $source, PageTransform $transform, bool $jpeg): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        try {
            $image = @imagecreatefromstring((string) file_get_contents($source));
            if ($image === false) {
                return null;
            }
            if ($jpeg) {
                $image = Thumbnail::upright($image, $source);
            }
            $image = self::apply($image, $transform);
            $file = tempnam(sys_get_temp_dir(), 'pgt');
            if ($file === false) {
                return null;
            }
            // Tesseract goes by the extension for some formats; the empty placeholder of tempnam goes.
            @unlink($file);

            return imagepng($image, $file.'.png', 1) ? $file.'.png' : null;
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Thumbnail;
use App\Domain\Optimize\Pdf\ScanPdf;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Pictures of a page for an assistant (D-040, stage 2): what it may look at (right "Seitenbilder ansehen") and the
 * straightened receipt it proposed (right "Geraderücken vorschlagen"). The assistant measures, docHamster only carries
 * it out: the receipt is turned by `angle` degrees (clockwise tilt seen in the photo, undone counter-clockwise) and cut
 * out as the rectangle the assistant gave – its centre (cx, cy) and its size (w, h) as parts of the photo's width and
 * height, measured along the receipt itself. GD only. Photos (JPEG, PNG, WebP) can be straightened; scan PDFs can be
 * looked at page by page.
 */
final class Straightener
{
    /** Longest side of a picture handed to the assistant. */
    public const int LOOK_SIDE = 1600;

    /** Largest straightened picture (pixels); more is scaled down. */
    private const int MAX_PIXELS = 12_000_000;

    public const array PHOTOS = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return array{jpeg: string, pages: int, width: int, height: int}|null a page as JPEG for looking at; null = no picture */
    public static function look(DocumentFile $file, int $page): ?array
    {
        $path = Storage::disk('local')->path($file->path);
        try {
            if (in_array($file->mime, self::PHOTOS, true)) {
                if ($page !== 1) {
                    return null;
                }
                $image = self::photo($path, $file->mime);

                return [...self::jpeg(self::fit($image, self::LOOK_SIDE)), 'pages' => 1];
            }
            if ($file->mime === 'application/pdf') {
                $scan = ScanPdf::read((string) file_get_contents($path));
                $one = $scan->pages[$page - 1] ?? null;
                $image = $one === null ? false : @imagecreatefromstring($one->jpeg);
                if ($image === false) {
                    // A54: a PDF with a text layer has no scan inside – drawn by Imagick where the server has it.
                    return self::rendered($path, $page);
                }

                return [...self::jpeg(self::fit($image, self::LOOK_SIDE)), 'pages' => count($scan->pages)];
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * A54: one page of a PDF drawn by Imagick (Ghostscript behind it), null without Imagick or when it fails.
     *
     * @return array{jpeg: string, width: int, height: int, pages: int}|null
     */
    private static function rendered(string $path, int $page): ?array
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }
        try {
            $pages = new \Imagick;
            $pages->pingImage($path);
            $count = $pages->getNumberImages();
            $pages->clear();
            if ($page > $count) {
                return null;
            }
            $im = new \Imagick;
            $im->setResolution(150, 150);
            $im->readImage($path.'['.($page - 1).']');
            $im->setImageBackgroundColor('white');
            $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $im->setImageFormat('png');
            $image = @imagecreatefromstring($im->getImageBlob());
            $im->clear();

            return $image === false ? null : [...self::jpeg(self::fit($image, self::LOOK_SIDE)), 'pages' => $count];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The photo turned upright, then straightened and cut out as proposed.
     *
     * @param  array{cx: float, cy: float, w: float, h: float}|null  $box
     */
    public static function straighten(DocumentFile $file, float $angle, ?array $box): \GdImage
    {
        if (! in_array($file->mime, self::PHOTOS, true)) {
            throw new RuntimeException((string) __('proposals.photo_only'));
        }
        $image = self::photo(Storage::disk('local')->path($file->path), $file->mime);
        [$width, $height] = [imagesx($image), imagesy($image)];
        if (abs($angle) >= 0.05) {
            $white = (int) imagecolorallocate($image, 255, 255, 255);
            // imagerotate() turns counter-clockwise: exactly undoing a clockwise tilt.
            $turned = imagerotate($image, $angle, $white);
            $image = $turned === false ? $image : $turned;
        }
        if ($box !== null) {
            // The receipt's centre in the turned picture: the same point, turned about the picture's centre.
            $rad = deg2rad($angle);
            [$dx, $dy] = [$box['cx'] * $width - $width / 2, $box['cy'] * $height - $height / 2];
            $x = imagesx($image) / 2 + cos($rad) * $dx + sin($rad) * $dy;
            $y = imagesy($image) / 2 - sin($rad) * $dx + cos($rad) * $dy;
            [$w, $h] = [$box['w'] * $width, $box['h'] * $height];
            $left = (int) max(0, round($x - $w / 2));
            $top = (int) max(0, round($y - $h / 2));
            $cut = imagecrop($image, ['x' => $left, 'y' => $top,
                'width' => (int) max(16, min(imagesx($image) - $left, round($w))), 'height' => (int) max(16, min(imagesy($image) - $top, round($h)))]);
            $image = $cut === false ? $image : $cut;
        }
        $pixels = imagesx($image) * imagesy($image);

        return $pixels > self::MAX_PIXELS ? self::fit($image, (int) floor(max(imagesx($image), imagesy($image)) * sqrt(self::MAX_PIXELS / $pixels))) : $image;
    }

    /** @return array{jpeg: string, width: int, height: int} */
    public static function jpeg(\GdImage $image, int $quality = 82): array
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return ['jpeg' => (string) ob_get_clean(), 'width' => imagesx($image), 'height' => imagesy($image)];
    }

    /** Scaled down so the longer side is at most $side. */
    public static function fit(\GdImage $image, int $side): \GdImage
    {
        $long = max(imagesx($image), imagesy($image));
        if ($long <= $side) {
            return $image;
        }
        $scaled = imagescale($image, (int) max(1, round(imagesx($image) * $side / $long)), -1, IMG_BICUBIC);

        return $scaled === false ? $image : $scaled;
    }

    private static function photo(string $path, string $mime): \GdImage
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            throw new RuntimeException((string) __('proposals.unreadable'));
        }

        return $mime === 'image/jpeg' ? Thumbnail::upright($image, $path) : $image;
    }
}

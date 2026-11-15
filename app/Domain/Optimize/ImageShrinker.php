<?php

declare(strict_types=1);

namespace App\Domain\Optimize;

use App\Domain\Documents\Thumbnail;
use GdImage;
use RuntimeException;

/**
 * Point 236: the picture work of "Dokumentgröße optimieren" with PHP GD only – scale a picture down to its target
 * size, store a page that is black-and-white or gray as gray, write JPEG at quality 80. A phone photo is turned
 * upright first (EXIF orientation) and keeps its capture date as a small EXIF block (the Belegdatum reads it).
 */
final class ImageShrinker
{
    /** A pixel counts as coloured when its channels differ by more than this. */
    private const int CHROMA = 28;

    /** A page is gray when fewer than this share of its pixels are coloured (a stamp, a coloured logo). */
    private const float COLOURED_SHARE = 0.002;

    /** Above this many pixels nothing is decoded. */
    public const int MAX_PIXELS = 80_000_000;

    /** Whether a picture of this size and its scaled copy fit in the memory left (GD holds ~5 bytes per pixel). */
    public static function fits(int $width, int $height, int $targetPixels): bool
    {
        $pixels = $width * $height;
        if ($pixels <= 0 || $pixels > self::MAX_PIXELS) {
            return false;
        }
        $limit = self::bytes((string) ini_get('memory_limit'));

        return $limit <= 0 || $limit - memory_get_usage() > ($pixels + $targetPixels) * 5 + 32_000_000;
    }

    /**
     * A JPEG of the picture, scaled down to the target size, gray when the picture is.
     *
     * @param  array{int, int}  $target
     */
    public static function encode(GdImage $image, array $target): string
    {
        $width = max(1, $target[0]);
        $height = max(1, $target[1]);
        if ($width !== imagesx($image) || $height !== imagesy($image)) {
            $scaled = imagecreatetruecolor($width, $height);
            imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            $image = $scaled;
        } elseif (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        if (self::isGray($image)) {
            imagefilter($image, IMG_FILTER_GRAYSCALE);
        }
        ob_start();
        imagejpeg($image, null, SizeRule::QUALITY);

        return (string) ob_get_clean();
    }

    /** Whether the picture is black-and-white or gray: almost no pixel has colour (judged on a small copy). */
    public static function isGray(GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, 300 / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $small = imagecreatetruecolor($w, $h);
        // Nearest pixels on purpose: averaging would wash out thin coloured lines.
        imagecopyresized($small, $image, 0, 0, 0, 0, $w, $h, $width, $height);
        $coloured = 0;
        $allowed = (int) floor($w * $h * self::COLOURED_SHARE);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if (max($r, $g, $b) - min($r, $g, $b) > self::CHROMA && ++$coloured > $allowed) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A phone photo (JPEG, PNG, WebP – whatever GD reads) as an upright, scaled-down JPEG with its capture date.
     */
    public static function photo(string $path, string $mime): string
    {
        $size = @getimagesize($path);
        if ($size === false) {
            throw new RuntimeException('picture unreadable');
        }
        $exif = $mime === 'image/jpeg' && function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $turned = is_array($exif) && in_array($exif['Orientation'] ?? 1, [5, 6, 7, 8], true);
        [$tw, $th] = SizeRule::photoTarget($turned ? $size[1] : $size[0], $turned ? $size[0] : $size[1]);
        if (! self::fits($size[0], $size[1], $tw * $th)) {
            throw new RuntimeException('picture too big');
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            throw new RuntimeException('picture unreadable');
        }
        if ($mime === 'image/jpeg') {
            $image = Thumbnail::upright($image, $path);
        }
        // Transparent parts on white, not black.
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        unset($image);
        $jpeg = self::encode($flat, [$tw, $th]);
        $taken = is_array($exif) ? ($exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? null) : null;

        return is_string($taken) ? ExifDate::insert($jpeg, $taken) : $jpeg;
    }

    private static function bytes(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '' || $limit === '-1') {
            return -1;
        }
        $n = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Org\Organization;
use App\Domain\Org\SpeedOptions;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Small preview of a file, made once and stored next to it (design point 14):
 * images with GD, the first page of a PDF only where Imagick exists. Without
 * one the list shows the type icon.
 *
 * GD holds about 4 bytes per pixel, so a 48 MP photo needs ~200 MB. During an
 * upload larger images are skipped and `dochamster:thumbnails` (no memory
 * budget on the command line) makes them later (#56).
 */
final class Thumbnail
{
    /** Pixels decoded during a web request: ~160 MB in GD, below the 256M the installer sets. */
    public const UPLOAD_MAX_PIXELS = 40_000_000;

    public static function make(DocumentFile $file, ?int $maxPixels = self::UPLOAD_MAX_PIXELS): ?string
    {
        $disk = Storage::disk('local');
        $source = $disk->path($file->path);

        if ($maxPixels !== null && $file->isImage()) {
            $size = @getimagesize($source);
            if ($size === false || $maxPixels < $size[0] * $size[1]) {
                return null;
            }
        }

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

        // Point 164.7: Einstellungen -> Geschwindigkeit -> Vorschaubilder (small: 320 px, quality 70).
        $orgId = $file->getAttribute('org_id');
        [$edge, $quality] = SpeedOptions::thumbSize(is_string($orgId) ? Organization::query()->find($orgId) : null);
        // Point 238 (A415): the preview shows the page turned and cropped; the cropped part gets the full edge.
        $transform = $file->pageTransform(1);
        $part = $transform->crop === null ? 1.0 : max($transform->crop['w'], $transform->crop['h']);
        $scale = min(1, $edge / (max(imagesx($image), imagesy($image)) * $part));
        $thumb = imagescale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
        if ($thumb === false) {
            return null;
        }
        if ($file->mime === 'image/jpeg') {
            $thumb = self::upright($thumb, $source);
        }
        $thumb = PageImage::apply($thumb, $transform);

        // A new rotation/crop gets a new path, so the URL changes (DocumentFile::thumbUrl, cached for a year).
        $path = $file->path.($transform->isNone() ? '.thumb.jpg' : '.thumb-'.substr(md5((string) json_encode($transform->toArray())), 0, 8).'.jpg');
        ob_start();
        imagejpeg($thumb, null, $quality);
        $disk->put($path, (string) ob_get_clean());

        return $path;
    }

    /**
     * Point 238: makes the preview again after a rotation/crop and drops the old one (kept when no new one can be made).
     */
    public static function remake(DocumentFile $file, ?int $maxPixels = self::UPLOAD_MAX_PIXELS): void
    {
        $old = $file->thumb_path;
        $new = self::make($file, $maxPixels);
        if ($new === null) {
            return;
        }
        $file->forceFill(['thumb_path' => $new])->save();
        if ($old !== null && $old !== $new) {
            Storage::disk('local')->delete($old);
        }
    }

    /**
     * Phones store a portrait photo sideways plus an EXIF note how to turn it;
     * GD ignores that note, so the thumbnail is turned here (and the receipt pack's pictures, point 211).
     */
    public static function upright(\GdImage $image, string $source): \GdImage
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($source) : false;
        $orientation = is_array($exif) && is_int($exif['Orientation'] ?? null) ? $exif['Orientation'] : 1;

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        // imagerotate() turns counter-clockwise.
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $turned = imagerotate($image, $angle, 0);

        return $turned === false ? $image : $turned;
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

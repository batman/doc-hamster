<?php

declare(strict_types=1);

namespace App\Domain\Optimize;

/**
 * Point 236: GD writes JPEGs without EXIF, so an optimised photo gets a minimal EXIF block back with only its capture
 * date (DateTimeOriginal) – ReceiptDateFile reads it like the phone's own. The orientation is not written: the
 * picture is already turned upright.
 */
final class ExifDate
{
    /** A JPEG with an APP1 "Exif" block right after its start marker; unchanged when the date doesn't look like one. */
    public static function insert(string $jpeg, string $dateTime): string
    {
        if (preg_match('/^\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}$/', $dateTime) !== 1 || ! str_starts_with($jpeg, "\xFF\xD8")) {
            return $jpeg;
        }
        // Little-endian TIFF: IFD0 at 8 with one entry (the Exif IFD pointer → 26), the Exif IFD with one entry
        // (DateTimeOriginal, 20 ASCII bytes at 44).
        $tiff = "II\x2A\x00".pack('V', 8)
            .pack('v', 1).pack('vvVV', 0x8769, 4, 1, 26).pack('V', 0)
            .pack('v', 1).pack('vvVV', 0x9003, 2, 20, 44).pack('V', 0)
            .$dateTime."\x00";
        $segment = "Exif\x00\x00".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($segment) + 2).$segment.substr($jpeg, 2);
    }
}

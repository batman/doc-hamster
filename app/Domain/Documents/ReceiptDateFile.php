<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * Point 214: the date a file carries itself, for a document whose text names none – a photo's capture date (EXIF
 * DateTimeOriginal, else DateTimeDigitized) or a PDF's creation date (/CreationDate in the info dictionary). Reads
 * only the head (and for a PDF the tail) of the file; no date, a broken file or a missing exif extension give null.
 */
final class ReceiptDateFile
{
    /** How much of a PDF's start and end is looked at (the info dictionary sits in one of them). */
    private const PDF_SPAN = 65536;

    /**
     * @return array{string, string}|null [Y-m-d, ReceiptDate::EXIF|ReceiptDate::PDF]
     */
    public static function read(string $path, string $mime): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        if (in_array($mime, ['image/jpeg', 'image/tiff'], true)) {
            $date = self::exif($path);

            return $date === null ? null : [$date, ReceiptDate::EXIF];
        }
        if ($mime === 'application/pdf') {
            $date = self::pdf($path);

            return $date === null ? null : [$date, ReceiptDate::PDF];
        }

        return null;
    }

    /** "2024:03:15 12:00:00" from the photo's EXIF block as Y-m-d. */
    public static function exif(string $path): ?string
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }
        try {
            $data = @exif_read_data($path, 'EXIF');
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($data)) {
            return null;
        }
        foreach (['DateTimeOriginal', 'DateTimeDigitized'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && preg_match('/^(\d{4})[:\-](\d{2})[:\-](\d{2})/', $value, $m) === 1) {
                $date = self::date($m[1], $m[2], $m[3]);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    /** "/CreationDate (D:20240315120000+01'00')" from the PDF's info dictionary as Y-m-d. */
    public static function pdf(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $size = (int) (fstat($handle)['size'] ?? 0);
        $text = (string) fread($handle, self::PDF_SPAN);
        if ($size > self::PDF_SPAN) {
            fseek($handle, max(self::PDF_SPAN, $size - self::PDF_SPAN));
            $text .= (string) fread($handle, self::PDF_SPAN);
        }
        fclose($handle);
        if (preg_match('/\/CreationDate\s*\(\s*(?:D:)?(\d{4})(\d{2})(\d{2})/', $text, $m) !== 1) {
            return null;
        }

        return self::date($m[1], $m[2], $m[3]);
    }

    private static function date(string $year, string $month, string $day): ?string
    {
        $y = (int) $year;

        return $y >= 1900 && checkdate((int) $month, (int) $day, $y) ? sprintf('%04d-%02d-%02d', $y, (int) $month, (int) $day) : null;
    }
}

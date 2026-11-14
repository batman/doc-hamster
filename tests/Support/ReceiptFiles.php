<?php

declare(strict_types=1);

namespace Tests\Support;

/** Point 214: tiny synthetic files that carry their own date – a JPEG with EXIF DateTimeOriginal, a PDF with /CreationDate. */
final class ReceiptFiles
{
    /** A 2×2 JPEG with an EXIF block whose DateTimeOriginal is $stamp ("2024:03:15 12:00:00"). */
    public static function jpegWithExif(string $stamp): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        // TIFF, little endian: IFD0 with the pointer to the EXIF IFD (26), which holds DateTimeOriginal at 44.
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1).pack('vvVV', 0x8769, 4, 1, 26).pack('V', 0)
            .pack('v', 1).pack('vvVV', 0x9003, 2, 20, 44).pack('V', 0)
            .str_pad($stamp, 19, ' ')."\0";
        $app1 = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    /** A minimal PDF whose info dictionary says it was created on $ymd (Ymd). */
    public static function pdfCreated(string $ymd): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n2 0 obj << /Producer (Synthetic) /CreationDate (D:{$ymd}120000+01'00') >> endobj\n"
            ."trailer << /Root 1 0 R /Info 2 0 R >>\n%%EOF\n";
    }

    /** A 2×2 PNG without any date. */
    public static function png(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Scanning;

use InvalidArgumentException;

/**
 * Puts JPEG pages into one PDF, one page per image, without any library
 * (the JPEG data is embedded as it is). Page size follows the scan
 * resolution, so a 300 dpi A4 scan is an A4 page.
 */
final class PdfBuilder
{
    /**
     * @param  list<string>  $jpegs  JPEG bytes, in page order
     */
    public static function fromJpegs(array $jpegs, int $dpi): string
    {
        if ($jpegs === [] || $dpi < 50 || $dpi > 1200) {
            throw new InvalidArgumentException('Need at least one page and a sensible resolution.');
        }

        $objects = [];
        $kids = [];
        // Object numbers: 1 catalog, 2 page tree, then three per page (page, content, image).
        foreach ($jpegs as $index => $jpeg) {
            [$width, $height, $components] = self::inspect($jpeg);
            $pageId = 3 + $index * 3;
            $contentId = $pageId + 1;
            $imageId = $pageId + 2;
            $kids[] = $pageId.' 0 R';

            $w = round($width * 72 / $dpi, 2);
            $h = round($height * 72 / $dpi, 2);
            $content = sprintf('q %s 0 0 %s 0 0 cm /Im0 Do Q', self::num($w), self::num($h));
            $space = [1 => '/DeviceGray', 3 => '/DeviceRGB', 4 => '/DeviceCMYK'][$components];
            $decode = $components === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : '';

            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /XObject << /Im0 %d 0 R >> >> /Contents %d 0 R >>', self::num($w), self::num($h), $imageId, $contentId);
            $objects[$contentId] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content);
            $objects[$imageId] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode%s /Length %d >>\nstream\n%s\nendstream", $width, $height, $space, $decode, strlen($jpeg), $jpeg);
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }

    /**
     * Width, height and colour components from the JPEG's frame header.
     *
     * @return array{int, int, int}
     */
    public static function inspect(string $jpeg): array
    {
        $length = strlen($jpeg);
        if ($length < 4 || ! str_starts_with($jpeg, "\xFF\xD8")) {
            throw new InvalidArgumentException('Not a JPEG.');
        }
        $pos = 2;
        while ($pos + 4 <= $length) {
            if ($jpeg[$pos] !== "\xFF") {
                $pos++;

                continue;
            }
            $marker = ord($jpeg[$pos + 1]);
            if ($marker === 0xFF) {
                $pos++;

                continue;
            }
            if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $pos += 2;

                continue;
            }
            $size = (ord($jpeg[$pos + 2]) << 8) + ord($jpeg[$pos + 3]);
            // SOF0..SOF15 except DHT (C4), JPG (C8) and DAC (CC).
            if ($marker >= 0xC0 && $marker <= 0xCF && ! in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                if ($pos + 10 > $length) {
                    break;
                }
                $height = (ord($jpeg[$pos + 5]) << 8) + ord($jpeg[$pos + 6]);
                $width = (ord($jpeg[$pos + 7]) << 8) + ord($jpeg[$pos + 8]);
                $components = ord($jpeg[$pos + 9]);
                if ($width < 1 || $height < 1 || ! in_array($components, [1, 3, 4], true)) {
                    break;
                }

                return [$width, $height, $components];
            }
            $pos += 2 + $size;
        }

        throw new InvalidArgumentException('Unreadable JPEG.');
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use GdImage;

/**
 * A synthetic invoice page drawn with GD for "Server testen" (point 186.2) and the tests (184.4): a heading with a rule
 * below, a few lines and one line inside a thin rectangle – the case of point 184. Sample data only, no real person.
 * Uses a TrueType font of the system when one is there, else GD's built-in font drawn three times bigger.
 */
final class OcrSamplePage
{
    /** About an A4 page as pdf.js draws it at 2.5×. */
    public const int WIDTH = 1700;

    public const int HEIGHT = 2400;

    /** The text lines in reading order; the last one sits in the box. */
    public const array LINES = [
        'Rechnung 2026-0042',
        'Max Mustermann',
        'Musterweg 1, 12345 Musterstadt',
        'Betrag: 123,45 EUR',
        'IBAN DE00 0000 0000 0000 0000 00',
    ];

    /** Fonts tried in this order (Debian/Ubuntu, Fedora, Alpine, Windows). */
    private const array FONTS = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/ttf-dejavu/DejaVuSans.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
    ];

    /** The thin box around the last line [x0, y0, x1, y1]; that line's baseline lies 60 px below its top. */
    public const array BOX = [100, 540, 1500, 625];

    public static function make(bool $builtInFont = false): GdImage
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 20, 20, 20);
        imagefill($image, 0, 0, $white);
        $font = $builtInFont ? null : self::font();
        $y = 110;
        foreach (self::LINES as $i => $line) {
            $size = $i === 0 ? 44 : 32;
            $boxed = $i === count(self::LINES) - 1;
            if ($boxed) {
                $y = self::BOX[1] + 60;
            }
            self::text($image, $font, $size, 120, $y, $line, $black);
            if ($i === 0) {
                // A rule under the heading, over most of the width.
                imagefilledrectangle($image, 120, $y + 30, self::WIDTH - 120, $y + 32, $black);
                $y += 40;
            }
            if ($boxed) {
                // The thin one-line box of point 184.
                imagesetthickness($image, 2);
                imagerectangle($image, self::BOX[0], self::BOX[1], self::BOX[2], self::BOX[3], $black);
                imagesetthickness($image, 1);
            }
            $y += 100;
        }

        return $image;
    }

    /** The expected text, lines joined by \n. */
    public static function expected(): string
    {
        return implode("\n", self::LINES);
    }

    private static function font(): ?string
    {
        if (! function_exists('imagettftext')) {
            return null;
        }
        foreach (self::FONTS as $font) {
            if (@is_file($font)) {
                return $font;
            }
        }

        return null;
    }

    /** One line with its baseline at $y. */
    private static function text(GdImage $image, ?string $font, int $size, int $x, int $y, string $text, int $color): void
    {
        if ($font !== null && imagettftext($image, $size, 0, $x, $y, $color, $font, $text) !== false) {
            return;
        }
        // GD font 5 is 9×15 px: drawn small, then copied three (heading four) times bigger without smoothing.
        $scale = $size > 40 ? 4 : 3;
        $small = imagecreatetruecolor(9 * strlen($text) + 2, 17);
        imagefill($small, 0, 0, (int) imagecolorallocate($small, 255, 255, 255));
        imagestring($small, 5, 1, 1, $text, (int) imagecolorallocate($small, 20, 20, 20));
        imagecopyresized($image, $small, $x, $y - 15 * $scale, 0, 0, imagesx($small) * $scale, 17 * $scale, imagesx($small), 17);
    }
}

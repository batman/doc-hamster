<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack\Pdf;

/**
 * One A4 page of drawing commands. Coordinates count from the TOP left in points (easier for layouts); text is
 * UTF-8 and becomes WinAnsi (unknown characters "?"). Colours are [r, g, b] from 0 to 1.
 */
final class PdfCanvas
{
    public const array BLACK = [0.13, 0.13, 0.12];

    public const array MUTED = [0.42, 0.42, 0.40];

    public const array RED = [0.78, 0.13, 0.11];

    public const array GREEN = [0.10, 0.50, 0.25];

    public const array LINE = [0.82, 0.82, 0.80];

    public const array SHADE = [0.95, 0.95, 0.94];

    /** @var list<string> */
    private array $ops = [];

    /** @var array<string, true> */
    private array $images = [];

    /**
     * @param  array{float, float, float}|list<float>  $color
     */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, array $color = self::BLACK): void
    {
        $ansi = self::ansi($text);
        if ($ansi === '') {
            return;
        }
        $this->ops[] = self::rgb($color, 'rg').' BT /'.($bold ? 'F2' : 'F1').' '.self::n($size).' Tf '.self::n($x).' '.self::n(PdfWriter::HEIGHT - $y).' Td ('.self::escape($ansi).') Tj ET';
    }

    /**
     * Text that ends at $right (amounts, page numbers).
     *
     * @param  array{float, float, float}|list<float>  $color
     */
    public function textRight(float $right, float $y, string $text, float $size = 10, bool $bold = false, array $color = self::BLACK): void
    {
        $this->text($right - self::width($text, $size, $bold), $y, $text, $size, $bold, $color);
    }

    /**
     * Text cut to $width with "…".
     *
     * @param  array{float, float, float}|list<float>  $color
     */
    public function textFit(float $x, float $y, string $text, float $width, float $size = 10, bool $bold = false, array $color = self::BLACK): void
    {
        $this->text($x, $y, self::fit($text, $width, $size, $bold), $size, $bold, $color);
    }

    /** ✓ (green) or ✗ (red) from ZapfDingbats, centred on $x. */
    public function mark(float $x, float $y, bool $ok, float $size = 10): void
    {
        $this->ops[] = self::rgb($ok ? self::GREEN : self::RED, 'rg').' BT /F3 '.self::n($size).' Tf '.self::n($x - $size * 0.4).' '.self::n(PdfWriter::HEIGHT - $y).' Td ('.($ok ? '4' : '8').') Tj ET';
    }

    /**
     * @param  array{float, float, float}|list<float>  $color
     */
    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $color = self::LINE): void
    {
        $this->ops[] = self::rgb($color, 'RG').' '.self::n($width).' w '.self::n($x1).' '.self::n(PdfWriter::HEIGHT - $y1).' m '.self::n($x2).' '.self::n(PdfWriter::HEIGHT - $y2).' l S';
    }

    /**
     * A filled box, $y = its top edge.
     *
     * @param  array{float, float, float}|list<float>  $color
     */
    public function box(float $x, float $y, float $w, float $h, array $color = self::SHADE): void
    {
        $this->ops[] = self::rgb($color, 'rg').' '.self::n($x).' '.self::n(PdfWriter::HEIGHT - $y - $h).' '.self::n($w).' '.self::n($h).' re f';
    }

    /**
     * A picture fitted into the box (top-left $x/$y, $w × $h), centred, never enlarged beyond 1.5×.
     *
     * @param  array{name: string, width: int, height: int}  $image
     */
    public function image(array $image, float $x, float $y, float $w, float $h): void
    {
        $scale = min($w / max(1, $image['width']), $h / max(1, $image['height']), 1.5);
        $dw = $image['width'] * $scale;
        $dh = $image['height'] * $scale;
        $left = $x + ($w - $dw) / 2;
        $bottom = PdfWriter::HEIGHT - $y - $dh;
        $this->ops[] = 'q '.self::n($dw).' 0 0 '.self::n($dh).' '.self::n($left).' '.self::n($bottom).' cm /'.$image['name'].' Do Q';
        $this->images[$image['name']] = true;
        $this->ops[] = self::rgb(self::LINE, 'RG').' 0.5 w '.self::n($left).' '.self::n($bottom).' '.self::n($dw).' '.self::n($dh).' re S';
    }

    /**
     * Lines of at most $width points (words kept whole where they fit).
     *
     * @return list<string>
     */
    public static function wrap(string $text, float $width, float $size = 10, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) ?: [] as $word) {
                $try = $line === '' ? $word : $line.' '.$word;
                if ($line !== '' && self::width($try, $size, $bold) > $width) {
                    $lines[] = $line;
                    $try = $word;
                }
                $line = self::width($try, $size, $bold) > $width ? self::fit($try, $width, $size, $bold) : $try;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    public static function width(string $text, float $size = 10, bool $bold = false): float
    {
        return HelveticaWidths::of(self::ansi($text), $size, $bold);
    }

    public static function fit(string $text, float $width, float $size = 10, bool $bold = false): string
    {
        if (self::width($text, $size, $bold) <= $width) {
            return $text;
        }
        $cut = $text;
        while ($cut !== '' && self::width($cut."\u{2026}", $size, $bold) > $width) {
            $cut = mb_substr($cut, 0, -1);
        }

        return rtrim($cut)."\u{2026}";
    }

    public function stream(): string
    {
        return implode("\n", $this->ops);
    }

    /** @return list<string> */
    public function images(): array
    {
        return array_keys($this->images);
    }

    /** UTF-8 → WinAnsi; control characters and line breaks dropped. */
    private static function ansi(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub($text, 'UTF-8'));
        $text = strtr($text, ["\u{2212}" => '-', "\u{2713}" => 'x', "\u{00A0}" => ' ']);

        return (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    private static function escape(string $ansi): string
    {
        return strtr($ansi, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    /**
     * @param  array{float, float, float}|list<float>  $color
     */
    private static function rgb(array $color, string $op): string
    {
        return self::n($color[0] ?? 0).' '.self::n($color[1] ?? 0).' '.self::n($color[2] ?? 0).' '.$op;
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}

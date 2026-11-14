<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * A small PDF made from plain text (A328: text or a link shared from the phone becomes a document).
 * Written here because the app has no PDF library: A4 pages, Helvetica (WinAnsi, so German umlauts
 * work; anything else becomes "?"), a bold title, the text wrapped and spread over as many pages as needed.
 */
final class TextPdf
{
    private const WIDTH = 595;

    private const HEIGHT = 842;

    private const MARGIN = 56;

    private const SIZE = 11;

    private const LEADING = 15;

    private const TITLE_SIZE = 15;

    /** Characters per line – a safe guess for Helvetica 11 pt on the 483 pt wide text area. */
    private const BODY_CHARS = 86;

    private const TITLE_CHARS = 60;

    public static function make(string $title, string $body): string
    {
        // WinAnsi first, so wrapping counts one byte per character and never cuts a character in two.
        $titleLines = self::wrap(self::ansi($title), self::TITLE_CHARS);
        $bodyLines = self::wrap(self::ansi($body), self::BODY_CHARS);

        // Pages of drawing commands: the title only on the first one.
        $pages = [];
        $ops = [];
        $y = self::HEIGHT - self::MARGIN - self::TITLE_SIZE;
        foreach ($titleLines as $line) {
            $ops[] = self::text('F2', self::TITLE_SIZE, $y, $line);
            $y -= self::TITLE_SIZE + 5;
        }
        $y -= 8;
        foreach ($bodyLines as $line) {
            if ($y < self::MARGIN) {
                $pages[] = $ops;
                $ops = [];
                $y = self::HEIGHT - self::MARGIN - self::SIZE;
            }
            if ($line !== '') {
                $ops[] = self::text('F1', self::SIZE, $y, $line);
            }
            $y -= self::LEADING;
        }
        $pages[] = $ops;

        // Objects: 1 catalog, 2 page tree, 3/4 fonts, then page + content per page.
        $objects = [];
        $kids = [];
        foreach ($pages as $i => $pageOps) {
            $pageId = 5 + $i * 2;
            $kids[] = $pageId.' 0 R';
            $stream = implode("\n", $pageOps);
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::WIDTH.' '.self::HEIGHT.'] '
                .'/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.($pageId + 1).' 0 R >>';
            $objects[$pageId + 1] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private static function text(string $font, int $size, int $y, string $line): string
    {
        return 'BT /'.$font.' '.$size.' Tf '.self::MARGIN.' '.$y.' Td ('.self::escape($line).') Tj ET';
    }

    /**
     * Lines of at most $width characters; paragraphs and empty lines stay, long words are cut.
     *
     * @return list<string>
     */
    private static function wrap(string $text, int $width): array
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $paragraph = rtrim($paragraph);
            if ($paragraph === '') {
                $lines[] = '';

                continue;
            }
            foreach (explode("\n", wordwrap($paragraph, $width, "\n", true)) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** UTF-8 → WinAnsi (unknown characters "?"), line breaks and tabs kept, other control characters dropped. */
    private static function ansi(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', mb_scrub($text, 'UTF-8'));

        return (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    private static function escape(string $line): string
    {
        return strtr($line, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}

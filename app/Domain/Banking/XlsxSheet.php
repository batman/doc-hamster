<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use DateTimeImmutable;
use SimpleXMLElement;
use ZipArchive;

/**
 * The first sheet of an Excel workbook (xlsx) as plain text rows (F18, A267). Deliberately small instead of a
 * spreadsheet library: a bank export is one sheet of values; this reads shared and inline strings, numbers,
 * booleans and dates (by the cell's number format) and nothing else – no formulas are evaluated, cached results
 * are taken as they are. Dates come out as "d.m.Y", decimals with a comma ("-12,30"), so the CSV rules read them.
 */
final class XlsxSheet
{
    /** A sheet bigger than this (unpacked) is no bank statement; also stops zip bombs. */
    private const MAX_BYTES = 40 * 1024 * 1024;

    /** Excel's built-in date formats (ECMA-376 18.8.30). */
    private const DATE_FORMATS = [14, 15, 16, 17, 22, 27, 30, 36, 45, 46, 47, 50, 57];

    /**
     * @return list<list<string>>|null null when it is no readable workbook
     */
    public static function rows(string $content): ?array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            return null;
        }
        try {
            file_put_contents($path, $content);
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return null;
            }
            try {
                return self::read($zip);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return list<list<string>>|null
     */
    private static function read(ZipArchive $zip): ?array
    {
        $sheet = self::xml($zip, self::firstSheet($zip));
        if ($sheet === null) {
            return null;
        }
        $strings = self::sharedStrings($zip);
        $dateStyles = self::dateStyles($zip);

        $rows = [];
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $column = $ref === '' ? count($cells) : self::column($ref);
                $cells[$column] = self::value($cell, $strings, $dateStyles);
            }
            if ($cells === []) {
                continue;
            }
            $line = [];
            for ($column = 0, $last = max(array_keys($cells)); $column <= $last; $column++) {
                $line[] = $cells[$column] ?? '';
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /** The path of the workbook's first sheet; sheet1.xml when the workbook doesn't say. */
    private static function firstSheet(ZipArchive $zip): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';
        $book = self::xml($zip, 'xl/workbook.xml');
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        if ($book === null || $rels === null || ! isset($book->sheets->sheet[0])) {
            return $fallback;
        }
        $id = (string) $book->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $id) {
                $target = ltrim((string) $rel['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return $fallback;
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::xml($zip, 'xl/sharedStrings.xml');
        $strings = [];
        foreach ($xml->si ?? [] as $item) {
            $strings[] = self::text($item);
        }

        return $strings;
    }

    /**
     * The style indexes (cellXfs) whose number format is a date.
     *
     * @return array<int, true>
     */
    private static function dateStyles(ZipArchive $zip): array
    {
        $xml = self::xml($zip, 'xl/styles.xml');
        if ($xml === null) {
            return [];
        }
        $custom = [];
        foreach ($xml->numFmts->numFmt ?? [] as $format) {
            // Quoted text and [colour] parts aside, a format with d, m or y is a date ("0.00" isn't).
            $code = (string) preg_replace(['/"[^"]*"/', '/\[[^\]]*\]/'], '', mb_strtolower((string) $format['formatCode']));
            if (preg_match('/[dy]|m(?!s)/', $code) === 1 && preg_match('/^[#0.,\s]*$/', $code) !== 1) {
                $custom[(int) $format['numFmtId']] = true;
            }
        }
        $styles = [];
        $index = 0;
        foreach ($xml->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            if (in_array($id, self::DATE_FORMATS, true) || isset($custom[$id])) {
                $styles[$index] = true;
            }
            $index++;
        }

        return $styles;
    }

    /**
     * @param  list<string>  $strings
     * @param  array<int, true>  $dateStyles
     */
    private static function value(SimpleXMLElement $cell, array $strings, array $dateStyles): string
    {
        $type = (string) $cell['t'];
        $raw = (string) $cell->v;

        return match ($type) {
            's' => $strings[(int) $raw] ?? '',
            'inlineStr' => self::text($cell->is),
            'b' => $raw === '1' ? 'TRUE' : 'FALSE',
            'str', 'e' => $raw,
            default => self::number($raw, isset($dateStyles[(int) $cell['s']])),
        };
    }

    private static function number(string $raw, bool $isDate): string
    {
        if ($raw === '' || ! is_numeric($raw)) {
            return $raw;
        }
        $value = (float) $raw;
        if ($isDate) {
            // Excel day 25569 = 1970-01-01 (the 1900 system, which every bank export uses).
            $date = (new DateTimeImmutable('@0'))->modify('+'.((int) floor($value) - 25569).' days');

            return $date->format('d.m.Y');
        }
        if (floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return number_format($value, 2, ',', '');
    }

    /** Rich text runs (<r><t>) or a plain <t>. */
    private static function text(SimpleXMLElement $item): string
    {
        if (isset($item->t)) {
            return (string) $item->t;
        }
        $text = '';
        foreach ($item->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /** "C12" → 2. */
    private static function column(string $ref): int
    {
        $letters = (string) preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private static function xml(ZipArchive $zip, string $name): ?SimpleXMLElement
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > self::MAX_BYTES) {
            return null;
        }
        $content = $zip->getFromName($name);
        if ($content === false) {
            return null;
        }
        $xml = @simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);

        return $xml === false ? null : $xml;
    }
}

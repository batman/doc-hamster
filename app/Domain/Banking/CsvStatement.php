<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use DateTimeImmutable;
use RuntimeException;

/**
 * Reads a bank's CSV export, whatever the bank (D-021): encoding, delimiter
 * and the header row (banks often put account details above it) are detected.
 */
final readonly class CsvStatement
{
    private const DELIMITERS = [';', ',', "\t", '|'];

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    private function __construct(public array $header, public array $rows) {}

    public static function parse(string $content): self
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        [$delimiter, $width] = self::detect($content);
        if ($width === 0) {
            return new self([], []);
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Could not read the statement.');
        }
        fwrite($stream, $content);
        rewind($stream);

        $header = [];
        $rows = [];
        // fgetcsv, not line by line: a quoted purpose can span several lines.
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $cells = array_map(static fn (?string $cell): string => trim((string) $cell), $cells);

            if ($header === []) {
                // Account details above the table can be padded with empty cells.
                if (count($cells) === $width && count(array_filter($cells)) * 2 > $width) {
                    $header = $cells;
                }
            } elseif (count($cells) > 1) {
                $rows[] = array_slice(array_pad($cells, $width, ''), 0, $width);
            }
        }
        fclose($stream);

        return new self($header, $rows);
    }

    /**
     * The delimiter that gives most rows of one width. Most columns alone is
     * wrong: a purpose full of commas would beat a `;` file.
     *
     * @return array{string, int}
     */
    private static function detect(string $content): array
    {
        $lines = array_slice(array_filter(explode("\n", $content), static fn (string $line): bool => trim($line) !== ''), 0, 50);

        $best = [';', 0];
        $bestScore = 0;
        foreach (self::DELIMITERS as $delimiter) {
            $widths = array_count_values(array_map(static fn (string $line): int => count(str_getcsv($line, $delimiter, '"', '')), $lines));

            foreach ($widths as $width => $count) {
                if ($width > 1 && $width * $count > $bestScore) {
                    $best = [$delimiter, $width];
                    $bestScore = $width * $count;
                }
            }
        }

        return $best;
    }

    /**
     * Best guess of the columns from the header names; null if nothing fits.
     *
     * @return array{date: int|null, amount: int|null, counterparty: int|null, purpose: int|null}
     */
    public function guessColumns(): array
    {
        $patterns = [
            'date' => ['/buchungs?(tag|datum)/', '/^buchung$/', '/^(datum|date|valuta)/', '/datum|date/'],
            'amount' => ['/betrag|amount/', '/umsatz(?!art)/'],
            'counterparty' => ['/empf(ä|ae)nger|auftraggeber|beg(ü|ue)nstigt|zahlungspflichtig|counterparty|payee/', '/name/'],
            'purpose' => ['/verwendung|zweck|purpose|reference|beschreibung|description/'],
        ];

        $map = [];
        foreach ($patterns as $field => $candidates) {
            $map[$field] = null;
            foreach ($candidates as $pattern) {
                foreach ($this->header as $index => $name) {
                    if (preg_match($pattern, mb_strtolower($name)) === 1) {
                        $map[$field] = $index;
                        break 2;
                    }
                }
            }
        }

        /** @var array{date: int|null, amount: int|null, counterparty: int|null, purpose: int|null} $map */
        return $map;
    }

    /** "1.234,56", "1,234.56", "-12,30", "12.30 EUR", "12,30-" → minor units; null if it isn't an amount. */
    public static function amountToMinor(string $value): ?int
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-') || str_ends_with($value, '-') || str_starts_with($value, '−');
        $digits = (string) preg_replace('/[^0-9.,]/', '', $value);

        if ($digits === '' || preg_match('/[0-9]/', $digits) !== 1) {
            return null;
        }

        $last = max((int) strrpos($digits, ','), (int) strrpos($digits, '.'));
        $hasDecimals = strpbrk($digits, ',.') !== false && strlen($digits) - $last - 1 <= 2;
        $whole = $hasDecimals ? substr($digits, 0, $last) : $digits;
        $fraction = $hasDecimals ? substr($digits, $last + 1) : '';

        $minor = ((int) preg_replace('/[^0-9]/', '', $whole)) * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$minor : $minor;
    }

    /** German and ISO dates, a time behind them is dropped → Y-m-d; null if it isn't a date. */
    public static function normaliseDate(string $value): ?string
    {
        $value = (string) preg_replace('/[ T]\d{1,2}:\d{2}.*$/', '', trim($value));

        foreach (['d.m.Y', 'd.m.y', 'j.n.Y', 'j.n.y', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}

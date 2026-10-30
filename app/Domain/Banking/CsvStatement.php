<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use DateTimeImmutable;

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

        $lines = array_values(array_filter(
            (array) preg_split('/\R/', $content),
            static fn ($line): bool => is_string($line) && trim($line) !== '',
        ));

        // The delimiter that gives the most columns; the first row with that many is the header.
        $best = [';', 0];
        foreach (self::DELIMITERS as $delimiter) {
            foreach (array_slice($lines, 0, 30) as $line) {
                $columns = count(str_getcsv((string) $line, $delimiter, '"', ''));
                if ($columns > $best[1]) {
                    $best = [$delimiter, $columns];
                }
            }
        }
        [$delimiter, $width] = $best;

        $header = [];
        $rows = [];
        foreach ($lines as $line) {
            $cells = array_map(static fn ($cell): string => trim((string) $cell), str_getcsv((string) $line, $delimiter, '"', ''));
            if ($header === []) {
                if (count($cells) === $width) {
                    $header = $cells;
                }
            } elseif (count($cells) === $width) {
                $rows[] = $cells;
            }
        }

        return new self($header, $rows);
    }

    /**
     * Best guess of the columns from the header names; null if nothing fits.
     *
     * @return array{date: int|null, amount: int|null, counterparty: int|null, purpose: int|null}
     */
    public function guessColumns(): array
    {
        $patterns = [
            'date' => ['/buchungs?(tag|datum)/', '/^(datum|date|valuta)/', '/datum|date/'],
            'amount' => ['/betrag|umsatz|amount/'],
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

    /** German and ISO dates → Y-m-d; null if it isn't a date. */
    public static function normaliseDate(string $value): ?string
    {
        foreach (['d.m.Y', 'd.m.y', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, trim($value));
            if ($date !== false && $date->format($format) === trim($value)) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}

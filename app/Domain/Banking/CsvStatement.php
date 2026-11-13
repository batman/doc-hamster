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

    /** F18: a CSV or an Excel workbook (xlsx, the first sheet); anything else gives an empty statement. */
    public static function read(string $content): self
    {
        if (str_starts_with($content, "PK\x03\x04")) {
            $rows = XlsxSheet::rows($content);

            return $rows === null ? new self([], []) : self::fromRows($rows);
        }

        // Other binaries (old .xls, pictures) contain NUL bytes, text never does.
        return str_contains($content, "\0") ? new self([], []) : self::parse($content);
    }

    /**
     * A table read elsewhere (Excel): the header is the first row as wide as most rows and mostly filled.
     *
     * @param  list<list<string>>  $rows
     */
    public static function fromRows(array $rows): self
    {
        $rows = array_map(static function (array $cells): array {
            $cells = array_map(static fn (string $cell): string => trim($cell), $cells);
            while ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }

            return $cells;
        }, $rows);

        $width = 0;
        $best = 0;
        foreach (array_count_values(array_map('count', $rows)) as $count => $times) {
            if ($count > 1 && $count * $times > $best) {
                [$width, $best] = [$count, $count * $times];
            }
        }
        if ($width === 0) {
            return new self([], []);
        }

        $header = [];
        $body = [];
        foreach ($rows as $cells) {
            if ($header === []) {
                if (count($cells) >= $width && count(array_filter($cells, static fn (string $c): bool => $c !== '')) * 2 > $width) {
                    $header = array_slice($cells, 0, $width);
                }
            } elseif (count(array_filter($cells, static fn (string $c): bool => $c !== '')) > 1) {
                $body[] = array_slice(array_pad($cells, $width, ''), 0, $width);
            }
        }

        return new self($header, $body);
    }

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
     * @return array{date: int|null, amount: int|null, counterparty: int|null, purpose: int|null, iban: int|null}
     */
    public function guessColumns(): array
    {
        $patterns = [
            'date' => ['/buchungs?(tag|datum)/', '/^buchung$/', '/^(datum|date|valuta)/', '/datum|date/'],
            'amount' => ['/betrag|amount/', '/umsatz(?!art)/'],
            'counterparty' => ['/empf(ä|ae)nger|auftraggeber|beg(ü|ue)nstigt|zahlungspflichtig|zahlungsbeteiligt|counterparty|payee/', '/name/'],
            'purpose' => ['/verwendung|zweck|purpose|reference|beschreibung|description/'],
            // The counterparty's IBAN, not the own account's ("IBAN Auftragskonto").
            'iban' => ['/(empf|beg(ü|ue)nstigt|zahlungsbeteiligt|gegen|auftraggeber|counterparty|payee).*iban|iban.*(empf|beg(ü|ue)nstigt|zahlungsbeteiligt|gegen|auftraggeber|counterparty|payee)/', '/^(kontonummer\s*\/\s*)?iban$/'],
        ];

        $map = [];
        foreach ($patterns as $field => $candidates) {
            $map[$field] = null;
            foreach ($candidates as $pattern) {
                foreach ($this->header as $index => $name) {
                    $name = mb_strtolower($name);
                    if ($field !== 'iban' && preg_match('/iban|bic/', $name) === 1) {
                        continue;
                    }
                    if (preg_match($pattern, $name) === 1) {
                        $map[$field] = $index;
                        break 2;
                    }
                }
            }
        }

        /** @var array{date: int|null, amount: int|null, counterparty: int|null, purpose: int|null, iban: int|null} $map */
        return $map;
    }

    /**
     * "1.234,56", "1,234.56", "-12,30", "EUR -12,30", "12.30 EUR", "12,30-" → minor
     * units; null if it isn't an amount. Exactly three digits behind the last
     * separator are thousands ("1.234", but not "0,005"); any other count are
     * decimals, rounded to cents ("1,2345" → 1,23).
     */
    public static function amountToMinor(string $value): ?int
    {
        $value = trim($value);
        // A minus anywhere before the number (also behind a currency) or right after it.
        $negative = preg_match('/^[^0-9]*[-\x{2212}]/u', $value) === 1 || str_ends_with($value, '-');
        $digits = (string) preg_replace('/[^0-9.,]/', '', $value);

        if ($digits === '' || preg_match('/[0-9]/', $digits) !== 1) {
            return null;
        }

        $last = max((int) strrpos($digits, ','), (int) strrpos($digits, '.'));
        // "0,005" can't be thousands: nothing but a zero stands in front.
        $hasDecimals = strpbrk($digits, ',.') !== false
            && (strlen($digits) - $last - 1 !== 3 || preg_match('/^0*$/', substr($digits, 0, $last)) === 1);
        $whole = $hasDecimals ? substr($digits, 0, $last) : $digits;
        $fraction = $hasDecimals ? substr($digits, $last + 1) : '';

        $cents = (int) str_pad(substr($fraction, 0, 2), 2, '0');
        $roundUp = strlen($fraction) > 2 && (int) $fraction[2] >= 5 ? 1 : 0;
        $minor = ((int) preg_replace('/[^0-9]/', '', $whole)) * 100 + $cents + $roundUp;

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

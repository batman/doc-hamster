<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Reads a bank's CSV export, whatever the bank (D-021): encoding, delimiter
 * and the header row (banks often put account details above it) are detected.
 */
final readonly class CsvStatement
{
    private const DELIMITERS = [';', ',', "\t", '|'];

    /** Point 219: booking times are shown and matched in this zone (Revolut Business gives UTC, CAMT may carry an offset). */
    public const LOCAL_ZONE = 'Europe/Berlin';

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     * @param  string  $preamble  what stands above the table (account details: the own IBAN, A296/142)
     */
    public function __construct(public array $header, public array $rows, public string $preamble = '') {}

    /** F18: a CSV, an Excel workbook (xlsx, the first sheet) or a CAMT.053 XML (A297); anything else gives an empty statement. */
    public static function read(string $content): self
    {
        if (CamtStatement::looksLike($content)) {
            return CamtStatement::toStatement($content) ?? new self([], []);
        }
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
        $preamble = [];
        foreach ($rows as $cells) {
            if ($header === []) {
                if (count($cells) >= $width && count(array_filter($cells, static fn (string $c): bool => $c !== '')) * 2 > $width) {
                    $header = array_slice($cells, 0, $width);
                } else {
                    $preamble[] = implode(' ', array_filter($cells, static fn (string $c): bool => $c !== ''));
                }
            } elseif (count(array_filter($cells, static fn (string $c): bool => $c !== '')) > 1) {
                $body[] = array_slice(array_pad($cells, $width, ''), 0, $width);
            }
        }

        return new self($header, $body, mb_substr(implode("\n", $preamble), 0, 4000));
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
        $preamble = [];
        // fgetcsv, not line by line: a quoted purpose can span several lines.
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $cells = array_map(static fn (?string $cell): string => trim((string) $cell), $cells);

            if ($header === []) {
                // Account details above the table can be padded with empty cells.
                if (count($cells) === $width && count(array_filter($cells)) * 2 > $width) {
                    $header = $cells;
                } else {
                    $preamble[] = implode(' ', array_filter($cells));
                }
            } elseif (count($cells) > 1) {
                $rows[] = array_slice(array_pad($cells, $width, ''), 0, $width);
            }
        }
        fclose($stream);

        return new self($header, $rows, mb_substr(implode("\n", $preamble), 0, 4000));
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
     * Best guess of the columns (A301): by the header names, then by the content; null where nothing fits.
     *
     * @return array<string, int|list<int>|null>
     */
    public function guessColumns(): array
    {
        return StatementColumns::guess($this)['columns'];
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

    /**
     * Point 219: the time behind a date – "14.03.2026 09:41", "2026-03-14 09:41:12", "2026-03-14T09:41:00+01:00" –
     * as local "H:i". $utc: the column is labelled UTC (Revolut Business). Null without a time, at exactly midnight
     * (a date-only export) or when the local time falls on another day than the date (the date stays as the bank
     * gave it).
     */
    public static function normaliseTime(string $value, bool $utc = false): ?string
    {
        $pattern = '/^(.+?)[ T](\d{1,2}):(\d{2})(?::(\d{2})(?:[.,]\d+)?)?\s*(Z|UTC|[+-]\d{2}:?\d{2})?(?:\s*uhr)?$/i';
        if (preg_match($pattern, trim($value), $m) !== 1) {
            return null;
        }
        $date = self::normaliseDate($m[1]);
        [$hour, $minute, $second] = [(int) $m[2], (int) $m[3], (int) ($m[4] ?? 0)];
        if ($date === null || $hour > 23 || $minute > 59 || $second > 59 || $hour + $minute + $second === 0) {
            return null;
        }
        $zone = strtoupper($m[5] ?? '');
        if ($zone === '' && ! $utc) {
            return sprintf('%02d:%02d', $hour, $minute);
        }
        $offset = in_array($zone, ['', 'Z', 'UTC'], true) ? '+00:00' : substr($zone, 0, 3).':'.substr(str_replace(':', '', $zone), 3, 2);
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s P', sprintf('%s %02d:%02d:%02d %s', $date, $hour, $minute, $second, $offset));
        if ($moment === false) {
            return null;
        }
        $local = $moment->setTimezone(new DateTimeZone(self::LOCAL_ZONE));

        return $local->format('Y-m-d') === $date ? $local->format('H:i') : null;
    }
}

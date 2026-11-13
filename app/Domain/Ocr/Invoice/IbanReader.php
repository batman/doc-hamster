<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use App\Domain\Ocr\InvoiceProposal;
use App\Domain\Properties\PropertyValues;

/**
 * IBAN and BIC (points 120.1, A247): mod-97 checked, the organisation's own skipped, an OCR swap O↔0, I/l↔1, S↔5,
 * B↔8, Z↔2 repaired when it gives the one valid IBAN.
 *
 * @phpstan-import-type Line from InvoiceLines
 * @phpstan-import-type Box from InvoiceLines
 */
final class IbanReader
{
    /** IBAN length per country (SEPA). */
    private const IBAN_LENGTHS = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18,
        'EE' => 20, 'ES' => 24, 'FI' => 18, 'FR' => 27, 'GB' => 22, 'GR' => 27, 'HR' => 21, 'HU' => 28, 'IE' => 22,
        'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'MC' => 27, 'MT' => 31, 'NL' => 18,
        'NO' => 15, 'PL' => 28, 'PT' => 25, 'RO' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'VA' => 22,
    ];

    /** Countries whose IBAN holds digits only after the country code: every OCR swap can be undone at once. */
    private const NUMERIC_BBAN = ['AT', 'BE', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'HR', 'HU', 'LT', 'NO', 'PL', 'PT', 'SE', 'SI', 'SK'];

    /** A247: letters OCR reads instead of digits, and back. */
    private const TO_DIGIT = ['O' => '0', 'o' => '0', 'I' => '1', 'i' => '1', 'l' => '1', '|' => '1', 'S' => '5', 's' => '5', 'B' => '8', 'Z' => '2', 'z' => '2'];

    private const TO_LETTER = ['0' => 'O', '1' => 'I', '5' => 'S', '8' => 'B', '2' => 'Z'];

    /** @var list<string> */
    private array $own;

    /** @param list<string> $ownIbans the organisation's own accounts – never the payee */
    public function __construct(array $ownIbans = [])
    {
        $this->own = array_values(array_filter(array_map(static fn (string $i): ?string => PropertyValues::parseIban($i), $ownIbans)));
    }

    /**
     * @param  list<Line>  $lines
     * @return array{InvoiceProposal|null, InvoiceProposal|null, int|null} the IBAN, a repaired one, the line index
     */
    public function iban(array $lines, ?string $hint): array
    {
        $hint = $hint !== null ? PropertyValues::parseIban($hint) : null;
        $found = [];
        $where = [];
        $swaps = [];
        $bank = [];
        foreach ($lines as $line) {
            if (preg_match('/kontoinhaber|zahlungsempf|empfänger|bankverbindung|account holder|\biban\b/iu', $line['text']) === 1) {
                $bank[] = $line['i'];
            }
        }
        foreach ($lines as $line) {
            if (preg_match_all('/(?<![A-Za-z0-9])([A-Z]{2})\s?([0-9OoIilSsZzB|]{2})((?:\s?[A-Za-z0-9|]){8,40})/u', $line['text'], $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($matches as $m) {
                $country = $m[1][0];
                $length = self::IBAN_LENGTHS[$country] ?? null;
                if ($length === null) {
                    continue;
                }
                $raw = $m[0][0];
                [$compact, $used] = self::take($raw, $length);
                if ($compact === null) {
                    continue;
                }
                $start = $m[0][1];
                $box = InvoiceLines::box($line, $start, $start + $used);
                $near = 0;
                foreach ($bank as $b) {
                    if (abs($b - $line['i']) <= 3) {
                        $near = 1;
                    }
                }
                $score = (preg_match('/\biban\b/iu', $line['text']) === 1 ? 3 : 0) + $near;
                $valid = preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $compact) === 1 ? PropertyValues::parseIban($compact) : null;
                if ($valid !== null) {
                    if (! in_array($valid, $this->own, true)) {
                        $found[$valid] = max($found[$valid] ?? 0, $score + ($valid === $hint ? 10 : 0));
                        $where[$valid] ??= [$line, $box];
                    }

                    continue;
                }
                $repaired = self::repair($compact);
                if ($repaired !== null && ! in_array($repaired, $this->own, true)) {
                    $swaps[] = [$repaired, trim($raw), $line, $box, $score];
                }
            }
        }

        if ($found !== []) {
            arsort($found);
            $iban = (string) array_key_first($found);
            [$line, $box] = $where[$iban];
            $confidence = $iban === $hint ? 0.98 : (count($found) > 1 ? 0.6 : 0.9);

            return [InvoiceLines::proposal($iban, $confidence, $line, $box), null, $line['i']];
        }
        if ($swaps !== []) {
            usort($swaps, static fn (array $a, array $b): int => $b[4] <=> $a[4]);
            [$repaired, $original, $line, $box] = $swaps[0];
            $original = substr((string) preg_replace('/\s+/', ' ', $original), 0, 50);

            return [null, InvoiceLines::proposal($repaired, $repaired === $hint ? 0.9 : 0.5, $line, $box, original: $original), $line['i']];
        }

        return [null, null, null];
    }

    /**
     * The first $length characters (spaces skipped) of a match and how many bytes of it they took.
     *
     * @return array{string|null, int}
     */
    private static function take(string $raw, int $length): array
    {
        $out = '';
        $used = 0;
        $chars = str_split($raw);
        foreach ($chars as $char) {
            $used++;
            if ($char === ' ') {
                continue;
            }
            $out .= $char;
            if (strlen($out) === $length) {
                return [$out, $used];
            }
        }

        return [null, 0];
    }

    /** A247: the one valid IBAN behind typical OCR swaps, else null. */
    public static function repair(string $compact): ?string
    {
        $country = strtoupper(substr($compact, 0, 2));
        $rest = substr($compact, 2);
        $check = strtr(substr($rest, 0, 2), self::TO_DIGIT);
        $bban = substr($rest, 2);
        if (in_array($country, self::NUMERIC_BBAN, true)) {
            $candidate = $country.$check.strtr($bban, self::TO_DIGIT);

            return preg_match('/^[A-Z]{2}\d+$/', $candidate) === 1 ? PropertyValues::parseIban($candidate) : null;
        }
        $base = strtoupper($bban);
        $valid = [];
        $plain = PropertyValues::parseIban($country.$check.$base);
        if ($plain !== null) {
            $valid[$plain] = true;
        }
        for ($i = 0, $n = strlen($bban); $i < $n; $i++) {
            $char = $bban[$i];
            $swap = self::TO_DIGIT[$char] ?? self::TO_LETTER[$char] ?? null;
            if ($swap === null) {
                continue;
            }
            $try = PropertyValues::parseIban($country.$check.substr($base, 0, $i).$swap.substr($base, $i + 1));
            if ($try !== null) {
                $valid[$try] = true;
            }
        }

        return count($valid) === 1 ? (string) array_key_first($valid) : null;
    }

    /**
     * @param  list<Line>  $lines
     */
    public static function bic(array $lines, ?int $near): ?InvoiceProposal
    {
        $best = null;
        $distance = PHP_INT_MAX;
        foreach ($lines as $line) {
            if (preg_match('/\b(?:BIC|SWIFT)(?:[\s-]?Code)?\s*[:.]?\s*([A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?)\b/u', $line['text'], $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $d = $near === null ? 0 : abs($line['i'] - $near);
            if ($d < $distance) {
                $distance = $d;
                $best = InvoiceLines::proposal($m[1][0], 0.8, $line, InvoiceLines::box($line, $m[1][1], $m[1][1] + strlen($m[1][0])));
            }
        }

        return $best;
    }
}

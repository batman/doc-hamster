<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use App\Domain\Properties\PropertyValues;
use Closure;
use DateTimeImmutable;

/**
 * Reads what a transfer needs from a recognised invoice (points 120, 120.1-120.4, A236, A237, A247, A248, A250, A251):
 * IBAN (mod-97, the organisation's own skipped, an OCR swap O↔0, I/l↔1, S↔5, B↔8, Z↔2 repaired), BIC, the amount by
 * weight (Zahlbetrag … beats Brutto, beats the largest amount low on the page; Netto, MwSt, Zwischensumme, Rabatt and
 * line items lose; netto + tax = brutto makes it sure), invoice and customer number, invoice and due date ("zahlbar
 * bis …", "innerhalb N Tagen"), the payee (bank block, else the letterhead), Skonto and the currency. Pure: the words
 * (OcrWords JSON) or plain text in, proposals with confidence and place out. What a sender taught before (A236)
 * comes in through the `hints` closure: fn (string $sender): ?array{iban?: ?string, amount_keyword?: ?string,
 * amount_page?: ?int, amount_box?: ?array{x: float, y: float, w: float, h: float}}.
 */
final class InvoiceReader
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

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mär' => 3, 'mae' => 3, 'mar' => 3, 'apr' => 4, 'mai' => 5, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'dez' => 12, 'dec' => 12,
    ];

    private const DATE = '/(?<![\d.])(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4}|\d{2})(?![\d])|(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)|(?<!\d)(\d{1,2})\.?\s*(januar|jan|februar|feb|märz|maerz|mär|march|april|apr|mai|may|juni|june|jun|juli|july|jul|august|aug|september|sept|sep|oktober|october|okt|oct|november|nov|dezember|december|dez|dec)\.?\s+(\d{4})(?!\d)/iu';

    private const AMOUNT = '/(?<![\d.,])(-?(?:\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2}|\d{1,3}(?:,\d{3})+\.\d{2}|\d+\.\d{2}))(?!\d|[.,]\d)(?!\s*%)/u';

    /** @var list<string> */
    private array $own;

    /**
     * @param  list<string>  $ownIbans  the organisation's own accounts – never the payee
     * @param  (Closure(string): ?array<string, mixed>)|null  $hints  A236: what a sender taught before
     */
    public function __construct(array $ownIbans = [], private readonly ?Closure $hints = null)
    {
        $this->own = array_values(array_filter(array_map(static fn (string $i): ?string => PropertyValues::parseIban($i), $ownIbans)));
    }

    /**
     * @param  array{pages: list<array{n?: int, words: list<array<string, mixed>>}>}|null  $words  OcrWords::forDocument()
     * @param  string  $text  plain text, used when there are no word positions
     * @param  string|null  $sender  a sender known already (a confirmed property) – used for the hints
     */
    public function read(?array $words, string $text = '', ?string $sender = null): InvoiceReading
    {
        $lines = $words !== null ? self::linesFromWords($words) : self::linesFromText($text);
        if ($lines === []) {
            return new InvoiceReading;
        }
        $currency = self::documentCurrency($lines);
        $invoiceDate = self::invoiceDate($lines);
        $payee = self::sender($lines);
        $name = $sender ?? $payee?->value;
        $hint = ($name !== null && $this->hints !== null) ? ($this->hints)($name) : null;
        $hint = is_array($hint) ? $hint : [];

        [$iban, $swap, $ibanLine] = $this->iban($lines, is_string($hint['iban'] ?? null) ? $hint['iban'] : null);
        [$amount, $others] = self::amounts($lines, $currency, $hint);
        [$percent, $until] = self::discount($lines, $invoiceDate);

        return new InvoiceReading(
            iban: $iban,
            ibanSwap: $swap,
            bic: self::bic($lines, $ibanLine),
            amount: $amount,
            amounts: $others,
            invoiceNumber: self::labelled($lines, '(?:rechnungs?[\s-]*(?:nr|nummer|no)\b\.?|invoice\s*(?:no\b\.?|number|#)|beleg[\s-]*(?:nr\b\.?|nummer))'),
            customerNumber: self::labelled($lines, '(?:kunden[\s-]*(?:nr|nummer|no)\b\.?|kd[\s.-]*nr\b\.?|customer\s*(?:no\b\.?|number|id))'),
            invoiceDate: $invoiceDate,
            dueDate: self::dueDate($lines, $invoiceDate),
            sender: $payee,
            discountPercent: $percent,
            discountUntil: $until,
            currency: $amount->currency ?? $currency,
        );
    }

    // --- Lines ---------------------------------------------------------------------------------------------------

    /**
     * @param  array{pages: list<array{n?: int, words: list<array<string, mixed>>}>}  $words
     * @return list<array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}>
     */
    private static function linesFromWords(array $words): array
    {
        $out = [];
        $i = 0;
        foreach ($words['pages'] as $index => $page) {
            foreach (OcrWords::lines($page) as $line) {
                $text = '';
                $spans = [];
                $top = 1.0;
                foreach ($line as $word) {
                    $t = (string) ($word['t'] ?? '');
                    if ($t === '') {
                        continue;
                    }
                    $text .= $text === '' ? '' : ' ';
                    $spans[] = ['s' => strlen($text), 'e' => strlen($text) + strlen($t), 'x' => (float) $word['x'], 'y' => (float) $word['y'], 'w' => (float) $word['w'], 'h' => (float) $word['h']];
                    $text .= $t;
                    $top = min($top, (float) $word['y']);
                }
                if ($text !== '') {
                    $out[] = ['page' => (int) ($page['n'] ?? $index + 1), 'i' => $i++, 'y' => $top, 'text' => $text, 'spans' => $spans];
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}>
     */
    private static function linesFromText(string $text): array
    {
        $out = [];
        $i = 0;
        foreach (explode("\f", $text) as $index => $page) {
            $rows = preg_split('/\R/u', $page) ?: [];
            $count = max(1, count($rows));
            foreach ($rows as $row => $line) {
                $line = trim((string) preg_replace('/[ \t]+/u', ' ', $line));
                if ($line !== '') {
                    $out[] = ['page' => $index + 1, 'i' => $i++, 'y' => (float) ($row / $count), 'text' => $line, 'spans' => []];
                }
            }
        }

        return $out;
    }

    /**
     * The box around the words a match covers (byte offsets in the line text).
     *
     * @param  array{spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}  $line
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private static function box(array $line, int $start, int $end): ?array
    {
        $x1 = $y1 = 1.0;
        $x2 = $y2 = 0.0;
        $hit = false;
        foreach ($line['spans'] as $span) {
            if ($span['s'] < $end && $span['e'] > $start) {
                $hit = true;
                $x1 = min($x1, $span['x']);
                $y1 = min($y1, $span['y']);
                $x2 = max($x2, $span['x'] + $span['w']);
                $y2 = max($y2, $span['y'] + $span['h']);
            }
        }

        return $hit ? ['x' => round($x1, 4), 'y' => round($y1, 4), 'w' => round($x2 - $x1, 4), 'h' => round($y2 - $y1, 4)] : null;
    }

    /**
     * @param  array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}  $line
     * @param  array{x: float, y: float, w: float, h: float}|null  $box
     */
    private static function proposal(string $value, float $confidence, array $line, ?array $box = null, ?int $minor = null, ?string $currency = null, ?string $original = null, ?string $keyword = null): InvoiceProposal
    {
        return new InvoiceProposal($value, round(min(1.0, max(0.0, $confidence)), 2), $line['page'], $box, $minor, $currency, $original, $keyword);
    }

    private static function lower(string $text): string
    {
        return mb_strtolower($text);
    }

    // --- IBAN and BIC (120.1, A247) ------------------------------------------------------------------------------

    /**
     * @param  list<array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}>  $lines
     * @return array{InvoiceProposal|null, InvoiceProposal|null, int|null} the IBAN, a repaired one, the line index
     */
    private function iban(array $lines, ?string $hint): array
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
                $box = self::box($line, $start, $start + $used);
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

            return [self::proposal($iban, $confidence, $line, $box), null, $line['i']];
        }
        if ($swaps !== []) {
            usort($swaps, static fn (array $a, array $b): int => $b[4] <=> $a[4]);
            [$repaired, $original, $line, $box] = $swaps[0];
            $original = substr((string) preg_replace('/\s+/', ' ', $original), 0, 50);

            return [null, self::proposal($repaired, $repaired === $hint ? 0.9 : 0.5, $line, $box, original: $original), $line['i']];
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
     * @param  list<array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}>  $lines
     */
    private static function bic(array $lines, ?int $near): ?InvoiceProposal
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
                $best = self::proposal($m[1][0], 0.8, $line, self::box($line, $m[1][1], $m[1][1] + strlen($m[1][0])));
            }
        }

        return $best;
    }

    // --- Amount (120.2, 120.4, A250) -----------------------------------------------------------------------------

    /**
     * What kind of amount a line holds, by its label: total (Zahlbetrag …), gross (Brutto, inkl. MwSt), net, tax,
     * exclude (Rabatt, Skonto …) or plain.
     *
     * @return array{string, int, string|null} kind, weight, keyword
     */
    public static function classify(string $text): array
    {
        $t = self::lower($text);
        $match = static function (string $pattern) use ($t): ?string {
            return preg_match($pattern, $t, $m) === 1 ? trim($m[0]) : null;
        };
        if (($k = $match('/zahlbetrag|zu zahlende[rn]? betrag|zu zahlen|gesamtbetrag|rechnungsbetrag|endbetrag|summe brutto|bruttosumme|bruttobetrag|amount due|total due|fälliger betrag|offener betrag/u')) !== null) {
            return ['total', 100, $k];
        }
        if (($k = $match('/\b(?:inkl|incl|einschl)\.?\s*(?:\d{1,2}\s*%\s*)?(?:mwst|ust|vat|umsatzsteuer|mehrwertsteuer)|\bbrutto\b/u')) !== null) {
            return ['gross', 70, $k];
        }
        if (($k = $match('/\bnetto|nettobetrag|zwischensumme|\bsubtotal\b|summe netto/u')) !== null) {
            return ['net', -50, $k];
        }
        if (($k = $match('/\bmwst\b|\bust\b|umsatzsteuer|mehrwertsteuer|\bvat\b|\bmwst\.|\bust\./u')) !== null) {
            return ['tax', -60, $k];
        }
        if (($k = $match('/rabatt|skonto|abzüglich|\babzgl|nachlass|gutschrift|anzahlung|bereits bezahlt|versandkosten/u')) !== null) {
            return ['exclude', -40, $k];
        }
        if (($k = $match('/gesamtsumme|\bgesamt\b|\btotal\b|\bsumme\b/u')) !== null) {
            return ['gross', $k === 'gesamtsumme' ? 60 : 45, $k];
        }

        return ['plain', 0, null];
    }

    /**
     * @param  list<array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}>  $lines
     * @param  array<string, mixed>  $hint
     * @return array{InvoiceProposal|null, list<InvoiceProposal>}
     */
    private static function amounts(array $lines, string $currency, array $hint): array
    {
        $candidates = [];
        $pending = null;
        $hintKeyword = is_string($hint['amount_keyword'] ?? null) && $hint['amount_keyword'] !== '' ? $hint['amount_keyword'] : null;
        $hintBox = is_array($hint['amount_box'] ?? null) ? $hint['amount_box'] : null;
        $hintPage = is_numeric($hint['amount_page'] ?? null) ? (int) $hint['amount_page'] : null;
        foreach ($lines as $line) {
            [$kind, $weight, $keyword] = self::classify($line['text']);
            $found = [];
            if (preg_match_all(self::AMOUNT, $line['text'], $matches, PREG_OFFSET_CAPTURE) !== false) {
                foreach ($matches[1] as [$raw, $offset]) {
                    $number = PropertyValues::parseNumber($raw, 2);
                    if ($number !== null) {
                        $found[] = [intdiv($number, 100), $raw, $offset];
                    }
                }
            }
            if ($found === []) {
                // A label alone on its line (a table): the amount stands on the next line.
                $pending = in_array($kind, ['total', 'gross'], true) ? [$kind, (int) round($weight * 0.8), $keyword, $line['page']] : null;

                continue;
            }
            if ($kind === 'plain' && $pending !== null && $pending[3] === $line['page']) {
                [$kind, $weight, $keyword] = $pending;
            }
            $pending = null;
            $lineCurrency = self::lineCurrency($line['text']) ?? $currency;
            $last = count($found) - 1;
            foreach ($found as $n => [$minor, $raw, $offset]) {
                $w = $weight;
                $k = $kind;
                if ($kind === 'plain') {
                    // Line items carry several amounts (quantity price, total); one amount low on the page is likelier.
                    $w = count($found) > 1 ? 5 : 15 + ($line['y'] >= 0.5 ? 10 : 0);
                } elseif ($n !== $last) {
                    // A labelled line with several amounts: the label belongs to the last one (netto | MwSt | brutto columns).
                    $w = 5;
                    $k = 'plain';
                }
                $box = self::box($line, $offset, $offset + strlen($raw));
                if ($hintKeyword !== null && str_contains(self::lower($line['text']), $hintKeyword) && $n === $last) {
                    $w += 200;
                }
                if ($hintBox !== null && $box !== null && ($hintPage === null || $hintPage === $line['page']) && self::near($hintBox, $box)) {
                    $w += 150;
                }
                $candidates[] = ['minor' => $minor, 'kind' => $k, 'weight' => $w, 'keyword' => $k === 'plain' ? self::label($line['text']) : $keyword, 'line' => $line, 'box' => $box, 'currency' => $lineCurrency];
            }
        }
        $positive = array_values(array_filter($candidates, static fn (array $c): bool => $c['minor'] > 0));
        if ($positive === []) {
            return [null, []];
        }

        // netto + tax = brutto (120.2): the sum of every net line and one or all tax lines.
        $nets = array_values(array_unique(array_column(array_filter($positive, static fn (array $c): bool => $c['kind'] === 'net'), 'minor')));
        $taxes = array_values(array_unique(array_column(array_filter($positive, static fn (array $c): bool => $c['kind'] === 'tax'), 'minor')));
        $sums = [];
        foreach ($nets as $net) {
            foreach ($taxes as $tax) {
                $sums[] = $net + $tax;
            }
            $sums[] = $net + array_sum($taxes);
        }
        $checked = static fn (int $minor): bool => array_filter($sums, static fn (int $s): bool => abs($s - $minor) <= 1) !== [];

        $pool = array_values(array_filter($positive, static fn (array $c): bool => in_array($c['kind'], ['total', 'gross', 'plain'], true)));
        if ($pool === []) {
            $pool = $positive;
        }
        usort($pool, static fn (array $a, array $b): int => [$b['weight'], $b['minor']] <=> [$a['weight'], $a['minor']]);
        $best = $pool[0];
        if ($best['weight'] < 100) {
            foreach ($pool as $c) {
                if ($c['minor'] !== $best['minor'] && $checked($c['minor']) && $c['weight'] >= 0) {
                    $best = $c;
                    break;
                }
            }
        }
        $confidence = match (true) {
            $best['weight'] >= 150 => 0.92,
            $best['kind'] === 'total' => 0.85,
            $best['kind'] === 'gross' => 0.7,
            default => 0.4,
        };
        if ($checked($best['minor'])) {
            $confidence = max($confidence, 0.95);
        } else {
            foreach ($pool as $c) {
                if ($c !== $best && $c['weight'] === $best['weight'] && $c['minor'] !== $best['minor']) {
                    $confidence *= 0.7;
                    break;
                }
            }
        }
        $amount = self::proposal(PropertyValues::formatAmount($best['minor'], $best['currency']), $confidence, $best['line'], $best['box'], $best['minor'], $best['currency'], keyword: $best['keyword']);

        $others = [];
        $seen = [$best['minor'] => true];
        usort($positive, static fn (array $a, array $b): int => [$b['weight'], $b['minor']] <=> [$a['weight'], $a['minor']]);
        foreach ($positive as $c) {
            if ($c['kind'] === 'tax' || isset($seen[$c['minor']]) || count($others) >= 6) {
                continue;
            }
            $seen[$c['minor']] = true;
            $others[] = self::proposal(PropertyValues::formatAmount($c['minor'], $c['currency']), 0.3, $c['line'], $c['box'], $c['minor'], $c['currency'], keyword: $c['keyword']);
        }

        return [$amount, $others];
    }

    /** The words of a line without numbers and currency ("Abschlag 50,00 €" → "abschlag"), null when nothing is left. */
    private static function label(string $text): ?string
    {
        $label = trim((string) preg_replace(['/[\d.,:;€$£%\-+*\/()]+/u', '/\b(?:eur|chf|usd|gbp)\b/u', '/\s+/u'], [' ', ' ', ' '], self::lower($text)));

        return mb_strlen($label) >= 3 ? mb_substr($label, 0, 60) : null;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array{x: float, y: float, w: float, h: float}  $b
     */
    private static function near(array $a, array $b): bool
    {
        $ax = (float) ($a['x'] ?? 0) + (float) ($a['w'] ?? 0) / 2;
        $ay = (float) ($a['y'] ?? 0) + (float) ($a['h'] ?? 0) / 2;

        return abs($ax - ($b['x'] + $b['w'] / 2)) <= 0.05 && abs($ay - ($b['y'] + $b['h'] / 2)) <= 0.03;
    }

    private static function lineCurrency(string $text): ?string
    {
        return match (true) {
            preg_match('/€|\bEUR\b/u', $text) === 1 => 'EUR',
            preg_match('/\bCHF\b/u', $text) === 1 => 'CHF',
            preg_match('/£|\bGBP\b/u', $text) === 1 => 'GBP',
            preg_match('/\$|\bUSD\b/u', $text) === 1 => 'USD',
            default => null,
        };
    }

    /**
     * @param  list<array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}>  $lines
     */
    private static function documentCurrency(array $lines): string
    {
        $count = [];
        foreach ($lines as $line) {
            $c = self::lineCurrency($line['text']);
            if ($c !== null) {
                $count[$c] = ($count[$c] ?? 0) + 1;
            }
        }
        arsort($count);

        return $count === [] ? 'EUR' : (string) array_key_first($count);
    }

    // --- Numbers and dates (120.3, A251) -------------------------------------------------------------------------

    /**
     * A value right after its label ("Rechnungs-Nr.: RE-2026-001"), or the first token of the next line (tables).
     *
     * @param  list<array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}>  $lines
     */
    private static function labelled(array $lines, string $label): ?InvoiceProposal
    {
        $value = '([A-Z0-9][A-Z0-9\-\/._]{0,30}[A-Z0-9]|\d)';
        foreach ($lines as $n => $line) {
            if (preg_match('/'.$label.'/iu', $line['text']) !== 1) {
                continue;
            }
            if (preg_match('/'.$label.'\s*[:#.]?\s*'.$value.'/iu', $line['text'], $m, PREG_OFFSET_CAPTURE) === 1 && preg_match('/\d/', $m[1][0]) === 1) {
                return self::proposal($m[1][0], 0.85, $line, self::box($line, $m[1][1], $m[1][1] + strlen($m[1][0])));
            }
            $next = $lines[$n + 1] ?? null;
            if ($next !== null && $next['page'] === $line['page'] && preg_match('/^'.$value.'(?=\s|$)/iu', $next['text'], $m, PREG_OFFSET_CAPTURE) === 1 && preg_match('/\d/', $m[1][0]) === 1) {
                return self::proposal($m[1][0], 0.6, $next, self::box($next, $m[1][1], $m[1][1] + strlen($m[1][0])));
            }
        }

        return null;
    }

    /**
     * The dates on a line as Y-m-d with their byte offsets.
     *
     * @return list<array{string, int, int}>
     */
    public static function dates(string $text): array
    {
        if (preg_match_all(self::DATE, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $out = [];
        foreach ($matches as $m) {
            // Groups of the alternatives not taken may be missing: read each one by number.
            $g = static fn (int $i): string => isset($m[$i]) ? $m[$i][0] : '';
            $date = null;
            if ($g(1) !== '') {
                $date = PropertyValues::parseDate($g(1).'.'.$g(2).'.'.$g(3));
            } elseif ($g(4) !== '') {
                $date = PropertyValues::parseDate($g(4).'-'.$g(5).'-'.$g(6));
            } elseif ($g(7) !== '') {
                $month = self::MONTHS[mb_substr(mb_strtolower($g(8)), 0, 3)] ?? null;
                $date = $month !== null ? PropertyValues::parseDate($g(7).'.'.$month.'.'.$g(9)) : null;
            }
            if ($date !== null) {
                $out[] = [$date, $m[0][1], $m[0][1] + strlen($m[0][0])];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}>  $lines
     */
    private static function invoiceDate(array $lines): ?InvoiceProposal
    {
        foreach ([
            ['/rechnungsdatum|rechnungs-datum|datum der rechnung|invoice date|belegdatum/iu', 0.85],
            ['/\bdatum\b|\bdate\b/iu', 0.7],
        ] as [$label, $confidence]) {
            foreach ($lines as $n => $line) {
                if (preg_match($label, $line['text']) !== 1 || preg_match('/liefer|leistung|fällig|zahlbar|bestell|geburt|skonto|due/iu', $line['text']) === 1) {
                    continue;
                }
                $dates = self::dates($line['text']);
                $source = $line;
                if ($dates === [] && isset($lines[$n + 1]) && $lines[$n + 1]['page'] === $line['page']) {
                    $source = $lines[$n + 1];
                    $dates = self::dates($source['text']);
                }
                if ($dates !== []) {
                    return self::proposal($dates[0][0], $confidence, $source, self::box($source, $dates[0][1], $dates[0][2]));
                }
            }
        }
        foreach ($lines as $line) {
            $dates = self::dates($line['text']);
            if ($dates !== [] && preg_match('/fällig|zahlbar|skonto|liefer|leistung/iu', $line['text']) !== 1) {
                $top = $line['page'] === 1 && $line['y'] < 0.4;

                return self::proposal($dates[0][0], $top ? 0.45 : 0.3, $line, self::box($line, $dates[0][1], $dates[0][2]));
            }
        }

        return null;
    }

    private static function plusDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify('+'.$days.' days')->format('Y-m-d');
    }

    /**
     * A251: "zahlbar bis 30.11.2026", "fällig am …", or "zahlbar innerhalb 14 Tagen" counted from the invoice date.
     *
     * @param  list<array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}>  $lines
     */
    private static function dueDate(array $lines, ?InvoiceProposal $invoiceDate): ?InvoiceProposal
    {
        foreach ($lines as $line) {
            if (preg_match('/skonto/iu', $line['text']) === 1) {
                continue;
            }
            if (preg_match('/zahlbar\s+(?:bis|zum|spätestens)|fällig\s+(?:am|bis|zum)|fälligkeit|zahlungsziel|due date|due on|payable by|bitte\s.*\bbis\b/iu', $line['text']) === 1) {
                $dates = self::dates($line['text']);
                if ($dates !== []) {
                    $date = end($dates);

                    return self::proposal($date[0], 0.85, $line, self::box($line, $date[1], $date[2]));
                }
            }
        }
        if ($invoiceDate === null) {
            return null;
        }
        foreach ($lines as $line) {
            $skonto = preg_match('/skonto/iu', $line['text']) === 1;
            $pattern = $skonto
                ? '/(\d{1,3})\s*tage?n?\s*(?:rein\s*)?netto/iu'
                : '/(?:zahlbar|zahlungsziel|binnen|innerhalb|within|payable)\D{0,25}?(\d{1,3})\s*(?:tage?n?|days)/iu';
            if (preg_match($pattern, $line['text'], $m, PREG_OFFSET_CAPTURE) === 1 && (int) $m[1][0] > 0 && (int) $m[1][0] <= 365) {
                return self::proposal(self::plusDays($invoiceDate->value, (int) $m[1][0]), 0.75, $line, self::box($line, $m[0][1], $m[0][1] + strlen($m[0][0])));
            }
            if (! $skonto && preg_match('/zahlbar sofort|sofort zahlbar|sofort fällig|due immediately/iu', $line['text']) === 1) {
                return self::proposal($invoiceDate->value, 0.6, $line);
            }
        }

        return null;
    }

    /**
     * A237: "3 % Skonto bei Zahlung bis 20.11.2026" or "innerhalb 10 Tagen 2 % Skonto" → percent and date.
     *
     * @param  list<array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}>  $lines
     * @return array{InvoiceProposal|null, InvoiceProposal|null}
     */
    private static function discount(array $lines, ?InvoiceProposal $invoiceDate): array
    {
        foreach ($lines as $line) {
            if (preg_match('/skonto/iu', $line['text']) !== 1 || preg_match('/(\d{1,2}(?:[.,]\d{1,2})?)\s*%/u', $line['text'], $p, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $value = str_replace('.', ',', $p[1][0]);
            if ((float) str_replace(',', '.', $value) <= 0 || (float) str_replace(',', '.', $value) >= 30) {
                continue;
            }
            $percent = self::proposal($value, 0.8, $line, self::box($line, $p[0][1], $p[0][1] + strlen($p[0][0])));
            $until = null;
            $dates = self::dates($line['text']);
            if ($dates !== []) {
                $until = self::proposal($dates[0][0], 0.8, $line, self::box($line, $dates[0][1], $dates[0][2]));
            } elseif ($invoiceDate !== null && preg_match('/(?:innerhalb|binnen|within)?\s*(?:von\s*)?(\d{1,3})\s*(?:tage?n?|days)/iu', $line['text'], $d, PREG_OFFSET_CAPTURE) === 1 && (int) $d[1][0] > 0 && (int) $d[1][0] <= 120) {
                $until = self::proposal(self::plusDays($invoiceDate->value, (int) $d[1][0]), 0.75, $line, self::box($line, $d[0][1], $d[0][1] + strlen($d[0][0])));
            }

            return [$percent, $until];
        }

        return [null, null];
    }

    // --- Payee (A248) --------------------------------------------------------------------------------------------

    /**
     * The payee: next to Kontoinhaber / Empfänger in the bank block, else the sender line above the address
     * ("Muster GmbH · Hauptstr. 1 · 12345 Musterstadt"), else a company name or the first line at the top.
     *
     * @param  list<array{page: int, i: int, y: float, text: string, spans: list<array{s: int, e: int, x: float, y: float, w: float, h: float}>}>  $lines
     */
    private static function sender(array $lines): ?InvoiceProposal
    {
        $ibanLines = [];
        foreach ($lines as $line) {
            if (preg_match('/\biban\b/iu', $line['text']) === 1) {
                $ibanLines[] = $line['i'];
            }
        }
        foreach ($lines as $line) {
            if (preg_match('/(?<!\p{L})(kontoinhaber(?:in)?|zahlungsempfänger|empfänger|account holder|inhaber|beneficiary)\s*(:)?\s*(.+)$/iu', $line['text'], $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $near = array_filter($ibanLines, static fn (int $i): bool => abs($i - $line['i']) <= 3) !== [];
            if ($m[2][0] === '' && ! $near) {
                continue;
            }
            $value = trim((string) preg_replace('/\s+(?:IBAN|BIC|Bank|Kreditinstitut)\b.*$/iu', '', $m[3][0]), " \t:,;");
            if (mb_strlen($value) >= 2 && mb_strlen($value) <= 70 && preg_match('/\p{L}{2}/u', $value) === 1 && preg_match('/\d{4}/', $value) !== 1) {
                return self::proposal($value, 0.85, $line, self::box($line, $m[3][1], $m[3][1] + strlen($value)));
            }
        }
        $top = array_values(array_filter($lines, static fn (array $l): bool => $l['page'] === $lines[0]['page'] && $l['y'] < 0.35));
        foreach ($top as $line) {
            $parts = preg_split('/\s+[·•|]\s+|\s+-\s+/u', $line['text']) ?: [];
            if (count($parts) >= 2 && preg_match('/\b\d{5}\b/', $line['text']) === 1 && self::isName($parts[0])) {
                return self::proposal(trim($parts[0]), 0.75, $line, self::box($line, 0, strlen($parts[0])));
            }
        }
        foreach ($top as $line) {
            if (preg_match('/^(.{2,60}?\b(?:GmbH(?:\s*&\s*Co\.?\s*KG)?|AG|UG(?:\s*\(haftungsbeschränkt\))?|KG|OHG|GbR|e\.\s?K\.|e\.\s?V\.|mbH|Ltd\.?|Inc\.?|SE|KGaA))(?=\s|$|,)/u', $line['text'], $m) === 1 && self::isName($m[1])) {
                return self::proposal(trim($m[1]), 0.65, $line, self::box($line, 0, strlen($m[1])));
            }
        }
        foreach ($top as $line) {
            if (self::isName($line['text']) && preg_match('/rechnung|invoice|datum|seite|page/iu', $line['text']) !== 1) {
                return self::proposal(trim(mb_substr($line['text'], 0, 70)), 0.35, $line, self::box($line, 0, strlen($line['text'])));
            }
        }

        return null;
    }

    private static function isName(string $text): bool
    {
        $text = trim($text);
        $length = mb_strlen($text);
        if ($length < 3 || $length > 70 || preg_match('/\p{L}{2}/u', $text) !== 1) {
            return false;
        }
        $digits = preg_match_all('/\d/', $text);

        return $digits <= $length * 0.3;
    }
}

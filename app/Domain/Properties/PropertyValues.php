<?php

declare(strict_types=1);

namespace App\Domain\Properties;

use App\Domain\Documents\Document;
use App\Domain\Org\OrgScope;
use App\Domain\Payments\InvoiceSuggestions;
use App\Domain\Payments\SenderHint;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Reads, checks and stores property values (points 122.1, 165). Input is what a person types or a word on the document
 * says, German or English: "1.234,56 €" / "$1,234.56" / "(12,00) CHF" → minor units + currency, "17 Oct 2025" /
 * "17. Oktober 2025" / "Oct 17, 2025" / "17.10.25" / "2025-10-17" / "03/04/2025" → a date, an IBAN with or without
 * spaces (mod-97 checked), "1.234,5" → a number (×10000). A numeric date that reads two ways (03/04/2025) is read day
 * first unless an order is given; the order used is stored (`date_order`) and remembered per sender (A343).
 * Invalid input throws a ValidationException with the message under `value`. A confirmed value is never overwritten
 * by a suggestion.
 */
final class PropertyValues
{
    /** Currency symbols a typed amount may carry; three capital letters work too. */
    private const SYMBOLS = ['US$' => 'USD', '€' => 'EUR', '$' => 'USD', '£' => 'GBP', '¥' => 'JPY', 'Fr.' => 'CHF'];

    /** Orders a numeric date may be read in, the preferred first (165.2: day first). */
    public const ORDERS = ['dmy', 'mdy', 'ymd'];

    /** Month names and short forms, German and English, without dots. */
    private const MONTHS = [
        'januar' => 1, 'jänner' => 1, 'jan' => 1, 'jän' => 1, 'january' => 1,
        'februar' => 2, 'feb' => 2, 'february' => 2,
        'märz' => 3, 'maerz' => 3, 'marz' => 3, 'mär' => 3, 'mrz' => 3, 'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'mai' => 5, 'may' => 5,
        'juni' => 6, 'jun' => 6, 'june' => 6,
        'juli' => 7, 'jul' => 7, 'july' => 7,
        'august' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'oktober' => 10, 'okt' => 10, 'october' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'dezember' => 12, 'dez' => 12, 'december' => 12, 'dec' => 12,
    ];

    /** Weekday names a date may start with ("Fri, 17 Oct 2025"); they are dropped. */
    private const WEEKDAYS = '/^(?:mo|di|mi|do|fr|sa|so|mon|tue|wed|thu|fri|sat|sun|montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag|monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/u';

    /**
     * Stores the value of one property on a document (one value per property and document).
     *
     * @param  array<string, mixed>|null  $box  {x, y, w, h} relative to the page
     */
    public function set(Document $document, Property|string $propertyOrKey, string $raw, string $state = DocumentProperty::CONFIRMED, ?int $page = null, ?array $box = null, ?string $order = null): DocumentProperty
    {
        $property = $this->property($document, $propertyOrKey);
        $state = $state === DocumentProperty::SUGGESTED ? DocumentProperty::SUGGESTED : DocumentProperty::CONFIRMED;

        $existing = DocumentProperty::query()->withoutGlobalScope(OrgScope::class)
            ->where('document_id', $document->id)->where('property_id', $property->id)->first();
        if ($existing !== null && $state === DocumentProperty::SUGGESTED && ! $existing->isSuggested()) {
            return $existing->setRelation('property', $property);
        }

        $chosen = in_array($order, self::ORDERS, true);
        if ($property->type === 'date' && ! $chosen) {
            $order = $this->senderOrder($document);
        }
        $values = $this->parse($property, $raw, $order);
        $value = $existing ?? new DocumentProperty(['org_id' => $document->org_id, 'document_id' => $document->id, 'property_id' => $property->id]);
        $value->fill([...$values, 'state' => $state, 'page' => $page, 'box' => self::cleanBox($box)]);
        $value->save();
        // A343: an order a person picked for an ambiguous date becomes this sender's default.
        if ($chosen && $values['date_order'] !== null && $state === DocumentProperty::CONFIRMED) {
            $this->rememberOrder($document, $values['date_order']);
        }

        return $value->setRelation('property', $property);
    }

    /** A suggested value becomes confirmed (✓ in the detail card). */
    public function confirm(DocumentProperty $value): DocumentProperty
    {
        $value->forceFill(['state' => DocumentProperty::CONFIRMED])->save();

        return $value;
    }

    public function get(Document $document, string $key): ?DocumentProperty
    {
        return DocumentProperty::query()->withoutGlobalScope(OrgScope::class)->with(['property' => static fn ($q) => $q->withoutGlobalScope(OrgScope::class)])
            ->where('document_id', $document->id)
            ->whereIn('property_id', Property::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $document->org_id)->where('key', $key)->select('id'))
            ->first();
    }

    /** The value as people read it: "1.234,56 €", "12.03.2026", "DE89 3704 …", "1,5". */
    public function display(DocumentProperty $value): string
    {
        return match ($value->property->type) {
            'amount' => self::formatAmount((int) $value->value_int, (string) ($value->currency ?? 'EUR')),
            'date' => $value->value_date?->format('d.m.Y') ?? '',
            'iban' => trim(chunk_split((string) $value->value_text, 4, ' ')),
            'number' => self::formatNumber((int) $value->value_int),
            default => (string) $value->value_text,
        };
    }

    /**
     * The columns of a value of this property, or a ValidationException (`value`).
     *
     * A date that reads two ways is read in $order (dmy|mdy|ymd) when that gives a date, else day first; the order
     * used comes back as `date_order` (null when the date was clear).
     *
     * @return array{value_text: ?string, value_int: ?int, value_date: ?string, currency: ?string, date_order: ?string}
     */
    public function parse(Property $property, string $raw, ?string $order = null): array
    {
        $raw = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $empty = ['value_text' => null, 'value_int' => null, 'value_date' => null, 'currency' => null, 'date_order' => null];
        if ($raw === '') {
            self::fail(__('properties.errors.empty'));
        }

        switch ($property->type) {
            case 'amount':
                $amount = self::parseAmount($raw) ?? self::fail(__('properties.errors.amount'));

                return [...$empty, 'value_int' => $amount[0], 'currency' => $amount[1]];
            case 'number':
                $number = self::parseNumber($raw, 4) ?? self::fail(__('properties.errors.number'));

                return [...$empty, 'value_int' => $number];
            case 'date':
                $readings = self::dateReadings($raw);
                if ($readings === []) {
                    self::fail(__('properties.errors.date'));
                }
                $used = $order !== null && isset($readings[$order]) ? $order : (string) array_key_first($readings);

                return [...$empty, 'value_date' => $readings[$used], 'date_order' => count($readings) > 1 ? $used : null];
            case 'iban':
                $iban = self::parseIban($raw) ?? self::fail(__('properties.errors.iban'));

                return [...$empty, 'value_text' => $iban];
            case 'choice':
                foreach ($property->options ?? [] as $option) {
                    if (mb_strtolower($option) === mb_strtolower($raw)) {
                        return [...$empty, 'value_text' => $option];
                    }
                }
                self::fail(__('properties.errors.choice', ['options' => implode(', ', $property->options ?? [])]));
                // no break: fail() throws
            default:
                if (mb_strlen($raw) > 500) {
                    self::fail(__('properties.errors.text'));
                }

                return [...$empty, 'value_text' => $raw];
        }
    }

    /**
     * "1.234,56 €", "-12,50", "EUR 3,00", "$1,234.56", "(12,00) CHF", "12,-" → [minor units, ISO currency]; EUR when
     * none is named. Brackets mean a negative amount.
     *
     * @return array{int, string}|null
     */
    public static function parseAmount(string $raw): ?array
    {
        $text = trim($raw);
        $currency = 'EUR';
        foreach (self::SYMBOLS as $symbol => $code) {
            if (str_contains($text, $symbol)) {
                $currency = $code;
                $text = str_replace($symbol, ' ', $text);
            }
        }
        if (preg_match('/(?<![A-Za-z])([A-Za-z]{3})(?![A-Za-z])/', $text, $m) === 1) {
            $currency = strtoupper($m[1]);
            $text = str_replace($m[1], ' ', $text);
        }
        $text = trim($text);
        $negative = false;
        if (preg_match('/^\((.*)\)$/u', $text, $m) === 1) {
            $negative = true;
            $text = $m[1];
        }
        // "12,-" / "12.--": whole euros.
        $text = (string) preg_replace('/[.,]-{1,2}$/', '', trim($text));
        $minor = self::parseNumber($text, 2);
        if ($minor === null) {
            return null;
        }
        $minor = intdiv($minor, 100);

        return [$negative ? -abs($minor) : $minor, $currency];
    }

    /**
     * A number in German or plain notation with at most $decimals decimals, as an integer ×10000 (null = not a number).
     * "1.234,56" and "1234.56" and "1,234.56" all work; one separator followed by exactly three digits is a thousands mark.
     */
    public static function parseNumber(string $raw, int $decimals = 4): ?int
    {
        $text = str_replace([' ', "\u{00A0}", "'"], '', trim($raw));
        $negative = false;
        if (preg_match('/^[-−–](.*)$/u', $text, $m) === 1 || preg_match('/^(.*)-$/', $text, $m) === 1) {
            $negative = true;
            $text = $m[1];
        }
        $text = ltrim($text, '+');
        if (preg_match('/^[0-9.,]+$/', $text) !== 1 || preg_match('/\d/', $text) !== 1) {
            return null;
        }
        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false || $lastDot !== false) {
            $mark = $lastComma !== false ? ',' : '.';
            $parts = explode($mark, $text);
            // "1.234" or "1.234.567": thousands marks; "12,5" or "12.50": a decimal mark.
            $decimal = (count($parts) > 2 || strlen(end($parts)) === 3) ? null : $mark;
        } else {
            $decimal = null;
        }
        $pos = $decimal === null ? strlen($text) : (int) strrpos($text, $decimal);
        $intPart = substr($text, 0, $pos);
        $frac = $decimal === null ? '' : substr($text, $pos + 1);
        // Thousands marks only between groups of three digits ("1.234.567", not "1,2,3").
        if (($intPart !== '' && preg_match('/^\d+$|^\d{1,3}([.,]\d{3})+$/', $intPart) !== 1) || ($decimal !== null && preg_match('/^\d+$/', $frac) !== 1)) {
            return null;
        }
        $int = str_replace([',', '.'], '', $intPart);
        if (strlen($frac) > $decimals || ($int === '' && $frac === '') || strlen($int) > 14) {
            return null;
        }
        $value = (int) ($int === '' ? '0' : $int) * 10000 + (int) str_pad($frac, 4, '0');

        return $negative ? -$value : $value;
    }

    /**
     * A date as "Y-m-d", or null when it is no real date. A numeric date that reads two ways is read in $order when
     * that gives a date, else day first.
     */
    public static function parseDate(string $raw, ?string $order = null): ?string
    {
        $readings = self::dateReadings($raw);

        return $readings[$order ?? ''] ?? ($readings === [] ? null : reset($readings));
    }

    /**
     * Every real date a text can mean, keyed by order (dmy|mdy|ymd), the preferred first; more than one entry means
     * the text is ambiguous (03/04/2025). Month names German or English, full or short, with or without a dot
     * ("17 Oct 2025", "3. Mai 2021", "Oct 17, 2025", "17-Oct-25"); numbers with dots are German (day first, never
     * ambiguous); a four-digit year first is ISO (2025-10-17). A two-digit year lies in this century unless that is
     * more than 20 years ahead.
     *
     * @return array<string, string>
     */
    public static function dateReadings(string $raw): array
    {
        $text = mb_strtolower(trim($raw));
        $text = (string) preg_replace('/(\d)(?:st|nd|rd|th)\b/u', '$1', $text);
        if (preg_match_all('/\p{L}+|\d+/u', $text, $m) === false || preg_replace('/\p{L}+|\d+|[\s.,\/\-]+/u', '', $text) !== '') {
            return [];
        }
        $tokens = $m[0];
        if ($tokens !== [] && ! ctype_digit($tokens[0]) && preg_match(self::WEEKDAYS, $tokens[0]) === 1) {
            array_shift($tokens);
        }
        if (count($tokens) !== 3) {
            return [];
        }
        $words = array_values(array_filter($tokens, static fn (string $t): bool => ! ctype_digit($t)));
        $numbers = array_values(array_filter($tokens, 'ctype_digit'));
        $candidates = [];
        if (count($words) === 1 && count($numbers) === 2) {
            $month = self::MONTHS[$words[0]] ?? null;
            if ($month === null) {
                return [];
            }
            [$first, $second] = $numbers;
            [$day, $year] = strlen($first) === 4 ? [$second, $first] : [$first, $second];
            $candidates['dmy'] = [$year, $month, $day];
        } elseif (count($numbers) === 3) {
            [$a, $b, $c] = $numbers;
            if (strlen($a) === 4) {
                $candidates['ymd'] = [$a, $b, $c];
            } elseif (str_contains($text, '.') && preg_match('/[\/\-]/', $text) !== 1) {
                $candidates['dmy'] = [$c, $b, $a];
            } else {
                $candidates = ['dmy' => [$c, $b, $a], 'mdy' => [$c, $a, $b]];
                if (strlen($a) === 2 && strlen($b) === 2 && strlen($c) === 2) {
                    $candidates['ymd'] = [$a, $b, $c];
                }
            }
        } else {
            return [];
        }

        $out = [];
        foreach ($candidates as $order => [$year, $month, $day]) {
            $date = self::realDate((string) $year, (int) $month, (string) $day);
            if ($date !== null && ! in_array($date, $out, true)) {
                $out[$order] = $date;
            }
        }

        return $out;
    }

    /** A date in words for the reading line: "3. April 2021" (properties.date_long). */
    public static function formatDateLong(string $ymd): string
    {
        return (new CarbonImmutable($ymd))->settings(['locale' => app()->getLocale()])->translatedFormat((string) __('properties.date_long'));
    }

    /** A343: the order this document's sender's dates are read in, as a person picked it before (null = day first). */
    public function senderOrder(Document $document): ?string
    {
        $key = $this->senderKey($document);
        if ($key === null) {
            return null;
        }
        $order = SenderHint::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $document->org_id)->where('sender_key', $key)->value('date_order');

        return is_string($order) && in_array($order, self::ORDERS, true) ? $order : null;
    }

    private function rememberOrder(Document $document, string $order): void
    {
        $key = $this->senderKey($document);
        if ($key === null) {
            return;
        }
        $hint = SenderHint::query()->withoutGlobalScope(OrgScope::class)->firstOrNew(['org_id' => $document->org_id, 'sender_key' => $key]);
        $hint->setAttribute('date_order', $order);
        $hint->save();
    }

    private function senderKey(Document $document): ?string
    {
        $sender = $this->get($document, 'sender')?->value_text;
        $key = $sender === null ? '' : InvoiceSuggestions::senderKey($sender);

        return $key === '' ? null : $key;
    }

    private static function realDate(string $year, int $month, string $day): ?string
    {
        if (strlen($day) > 2 || (strlen($year) !== 2 && strlen($year) !== 4)) {
            return null;
        }
        $y = (int) $year;
        if (strlen($year) === 2) {
            // Two digits: this century, unless that lies more than 20 years ahead.
            $y += 2000;
            if ($y > (int) CarbonImmutable::now()->format('Y') + 20) {
                $y -= 100;
            }
        }

        return checkdate($month, (int) $day, $y) && $y >= 1900 ? sprintf('%04d-%02d-%02d', $y, $month, (int) $day) : null;
    }

    /** An IBAN without spaces in capitals when its check digits are right (ISO 13616 mod-97), else null. */
    public static function parseIban(string $raw): ?string
    {
        $iban = strtoupper((string) preg_replace('/[\s-]+/', '', $raw));
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return null;
        }
        $moved = substr($iban, 4).substr($iban, 0, 4);
        $digits = '';
        foreach (str_split($moved) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $rest = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $rest = (int) ($rest.$chunk) % 97;
        }

        return $rest === 1 ? $iban : null;
    }

    public static function formatAmount(int $minor, string $currency = 'EUR'): string
    {
        $text = number_format(abs($minor) / 100, 2, ',', '.');
        $sign = $minor < 0 ? '-' : '';

        return $sign.$text.' '.($currency === 'EUR' ? '€' : $currency);
    }

    /** A number ×10000 in German notation, without trailing zeros: 15000 → "1,5". */
    public static function formatNumber(int $value): string
    {
        $text = number_format(abs($value) / 10000, 4, ',', '.');
        $text = rtrim(rtrim($text, '0'), ',');

        return ($value < 0 ? '-' : '').$text;
    }

    private function property(Document $document, Property|string $propertyOrKey): Property
    {
        if ($propertyOrKey instanceof Property) {
            return $propertyOrKey;
        }

        return Property::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $document->org_id)
            ->where(static fn ($q) => $q->where('key', $propertyOrKey)->orWhere('id', $propertyOrKey))
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>|null  $box
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private static function cleanBox(?array $box): ?array
    {
        if ($box === null) {
            return null;
        }
        $out = [];
        foreach (['x', 'y', 'w', 'h'] as $part) {
            $value = $box[$part] ?? null;
            if (! is_numeric($value)) {
                return null;
            }
            $out[$part] = round(max(0.0, min(1.0, (float) $value)), 4);
        }

        return $out;
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['value' => $message]);
    }
}

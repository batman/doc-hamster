<?php

declare(strict_types=1);

namespace App\Domain\Properties;

use App\Domain\Documents\Document;
use App\Domain\Org\OrgScope;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Reads, checks and stores property values (point 122.1). Input is what a person types or a word on the document
 * says, in German formats: "1.234,56 €" → 123456 EUR, "12.03.26" / "12.03.2026" / "2026-03-12" → a date, an IBAN with
 * or without spaces (mod-97 checked), "1.234,5" → a number (×10000). Invalid input throws a ValidationException
 * with the message under `value`. A confirmed value is never overwritten by a suggestion.
 */
final class PropertyValues
{
    /** Currency symbols a typed amount may carry; three capital letters work too. */
    private const SYMBOLS = ['€' => 'EUR', '$' => 'USD', '£' => 'GBP', '¥' => 'JPY'];

    /**
     * Stores the value of one property on a document (one value per property and document).
     *
     * @param  array<string, mixed>|null  $box  {x, y, w, h} relative to the page
     */
    public function set(Document $document, Property|string $propertyOrKey, string $raw, string $state = DocumentProperty::CONFIRMED, ?int $page = null, ?array $box = null): DocumentProperty
    {
        $property = $this->property($document, $propertyOrKey);
        $state = $state === DocumentProperty::SUGGESTED ? DocumentProperty::SUGGESTED : DocumentProperty::CONFIRMED;

        $existing = DocumentProperty::query()->withoutGlobalScope(OrgScope::class)
            ->where('document_id', $document->id)->where('property_id', $property->id)->first();
        if ($existing !== null && $state === DocumentProperty::SUGGESTED && ! $existing->isSuggested()) {
            return $existing->setRelation('property', $property);
        }

        $values = $this->parse($property, $raw);
        $value = $existing ?? new DocumentProperty(['org_id' => $document->org_id, 'document_id' => $document->id, 'property_id' => $property->id]);
        $value->fill([...$values, 'state' => $state, 'page' => $page, 'box' => self::cleanBox($box)]);
        $value->save();

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
     * @return array{value_text: ?string, value_int: ?int, value_date: ?string, currency: ?string}
     */
    public function parse(Property $property, string $raw): array
    {
        $raw = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $empty = ['value_text' => null, 'value_int' => null, 'value_date' => null, 'currency' => null];
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
                $date = self::parseDate($raw) ?? self::fail(__('properties.errors.date'));

                return [...$empty, 'value_date' => $date];
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
     * "1.234,56 €", "-12,50", "EUR 3,00", "12.5 USD" → [minor units, ISO currency]; EUR when none is named.
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
        if (preg_match('/\b([A-Za-z]{3})\b/', $text, $m) === 1) {
            $currency = strtoupper($m[1]);
            $text = str_replace($m[1], ' ', $text);
        }
        $minor = self::parseNumber($text, 2);

        return $minor === null ? null : [intdiv($minor, 100), $currency];
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

    /** "12.03.26", "12.3.2026", "2026-03-12" → "2026-03-12"; null when it is no real date. */
    public static function parseDate(string $raw): ?string
    {
        $text = trim($raw);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{2}|\d{4})$/', $text, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if (strlen($m[3]) === 2) {
                // Two digits: this century, unless that lies more than 20 years ahead.
                $year += 2000;
                if ($year > (int) CarbonImmutable::now()->format('Y') + 20) {
                    $year -= 100;
                }
            }
        } else {
            return null;
        }

        return checkdate($month, $day, $year) && $year >= 1900 ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
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

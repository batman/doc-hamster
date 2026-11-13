<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Documents\Document;
use App\Domain\Ocr\InvoiceProposal;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use Carbon\CarbonImmutable;

/**
 * What the GiroCode card of a document shows (points 120, 120.4, A237-A239, A247, A249, A250): payee, IBAN, BIC,
 * amount and Verwendungszweck from the properties, what is missing for a code, other amounts as chips while the
 * amount is unsure, a repaired IBAN as a one-click fix, the Skonto and the paid state. The Verwendungszweck is made
 * from invoice and customer number; an edited one is kept as the property payment_reference (empty = made again).
 */
final class GiroCodeCard
{
    /** The fields of the card, in their order: key => [label key, monospace]. */
    public const FIELDS = ['sender' => 'payee', 'iban' => 'iban', 'bic' => 'bic', 'invoice_amount' => 'amount', 'payment_reference' => 'reference'];

    /** Below this the amount counts as unsure (120.4). */
    public const SURE = 0.8;

    public function __construct(private readonly PropertyValues $values, private readonly InvoiceSuggestions $suggestions) {}

    /**
     * @return array{show: bool, rows: list<array{key: string, label: string, text: string, suggested: bool, editable: bool, unsure: bool, page: int|null, box: array<string, float>|null}>,
     *     chips: list<array{text: string, page: int|null, box: array<string, float>|null}>, swap: array{text: string, original: string, page: int|null, box: array<string, float>|null}|null,
     *     missing: list<string>, euro: bool, currency: string, code: bool, skonto: array{percent: string, until: string|null, full: string, reduced: string}|null,
     *     paid: string|null, canPay: bool}
     */
    public function build(Document $document): array
    {
        $allowed = Property::forDocument($document)->filter(static fn (Property $p): bool => $p->key !== null)->keyBy('key');
        $stored = $this->stored($document);
        $get = static fn (string $key): ?DocumentProperty => $stored[$key] ?? null;

        $amount = $get('invoice_amount');
        $needsReading = $get('iban') === null || $amount === null || $amount->isSuggested();
        $reading = $needsReading && ($document->getAttribute('ocr_words') !== null || $document->getAttribute('ocr_text') !== null)
            ? $this->suggestions->read($document) : null;

        $reference = $this->reference($stored);
        $rows = [];
        foreach (self::FIELDS as $key => $label) {
            if (! $allowed->has($key)) {
                continue;
            }
            $value = $get($key);
            $text = $key === 'payment_reference' ? $reference : ($value !== null ? $this->values->display($value) : '');
            $unsure = $key === 'invoice_amount' && $value !== null && $value->isSuggested()
                && ($reading?->amount === null || $reading->amount->confidence < self::SURE || $reading->amount->minor !== $value->value_int);
            $rows[] = [
                'key' => $key, 'label' => __('girocode.fields.'.$label), 'text' => $text,
                'suggested' => $value !== null && $value->isSuggested(), 'editable' => true, 'unsure' => $unsure,
                'page' => $value?->page, 'box' => $value?->box,
            ];
        }

        $chips = [];
        if ($reading !== null && ($amount === null || $amount->isSuggested()) && $allowed->has('invoice_amount')) {
            foreach ([$reading->amount, ...$reading->amounts] as $p) {
                if ($p instanceof InvoiceProposal && $p->minor !== null && $p->minor !== $amount?->value_int && count($chips) < 5) {
                    $chips[] = ['text' => $p->value, 'page' => $p->page, 'box' => $p->box];
                }
            }
        }
        $swap = null;
        if ($get('iban') === null && $reading?->iban === null && $reading?->ibanSwap !== null && $allowed->has('iban')) {
            $s = $reading->ibanSwap;
            $swap = ['text' => trim(chunk_split($s->value, 4, ' ')), 'original' => (string) $s->original, 'page' => $s->page, 'box' => $s->box];
        }

        $currency = (string) ($amount->currency ?? 'EUR');
        $missing = [];
        foreach (['sender' => 'payee', 'iban' => 'iban', 'invoice_amount' => 'amount'] as $key => $label) {
            $v = $get($key);
            if ($v === null || ($key === 'invoice_amount' && (int) $v->value_int <= 0)) {
                $missing[] = __('girocode.fields.'.$label);
            }
        }

        $skonto = null;
        $percent = $get('discount_percent');
        $until = $get('discount_until')?->value_date;
        if ($amount !== null && $percent !== null && (int) $percent->value_int > 0 && (int) $percent->value_int < 1_000_000
            && ($until === null || ! CarbonImmutable::parse($until)->endOfDay()->isPast())) {
            $skonto = [
                'percent' => PropertyValues::formatNumber((int) $percent->value_int),
                'until' => $until?->format('d.m.Y'),
                'full' => PropertyValues::formatAmount((int) $amount->value_int, $currency),
                'reduced' => PropertyValues::formatAmount(GiroCode::discounted((int) $amount->value_int, (int) $percent->value_int), $currency),
            ];
        }
        $paid = $get('paid_at')?->value_date?->format('d.m.Y');

        return [
            'show' => $get('iban') !== null || $reading?->iban !== null || $swap !== null || $paid !== null || $get('payment_reference') !== null || $get('bic') !== null,
            'rows' => $rows,
            'chips' => $chips,
            'swap' => $swap,
            'missing' => $missing,
            'euro' => $currency === 'EUR',
            'currency' => $currency,
            'code' => $missing === [] && $currency === 'EUR',
            'skonto' => $skonto,
            'paid' => $paid,
            'canPay' => $allowed->has('paid_at'),
        ];
    }

    /**
     * The EPC payload from the stored values, each overridable (the card's live preview while typing); null when a
     * part is missing or invalid (A239) or the amount is no euro amount (A250).
     *
     * @param  array<string, string>  $over  name, iban, bic, amount, ref
     */
    public function payload(Document $document, array $over = [], bool $skonto = false): ?string
    {
        $stored = $this->stored($document);
        $name = $over['name'] ?? (string) ($stored['sender']->value_text ?? '');
        $iban = PropertyValues::parseIban($over['iban'] ?? (string) ($stored['iban']->value_text ?? ''));
        $bic = $over['bic'] ?? (string) ($stored['bic']->value_text ?? '');
        if (isset($over['amount'])) {
            $parsed = PropertyValues::parseAmount($over['amount']);
            [$minor, $currency] = $parsed ?? [0, 'EUR'];
        } else {
            $minor = (int) ($stored['invoice_amount']->value_int ?? 0);
            $currency = (string) ($stored['invoice_amount']->currency ?? 'EUR');
        }
        $percent = (int) ($stored['discount_percent']->value_int ?? 0);
        if ($skonto && $percent > 0 && $percent < 1_000_000) {
            $minor = GiroCode::discounted($minor, $percent);
        }
        $bic = strtoupper(trim($bic));
        if (trim($name) === '' || $iban === null || $currency !== 'EUR' || $minor <= 0 || $minor > GiroCode::MAX_MINOR
            || ($bic !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) !== 1)) {
            return null;
        }

        return GiroCode::payload($name, $iban, $bic, $minor, $over['ref'] ?? $this->reference($stored));
    }

    /**
     * @param  array<string, DocumentProperty>  $stored
     */
    private function reference(array $stored): string
    {
        $own = $stored['payment_reference'] ?? null;

        return $own !== null && trim((string) $own->value_text) !== ''
            ? (string) $own->value_text
            : GiroCode::reference($stored['invoice_number']->value_text ?? null, $stored['customer_number']->value_text ?? null);
    }

    /**
     * The document's values by property key.
     *
     * @return array<string, DocumentProperty>
     */
    private function stored(Document $document): array
    {
        $out = [];
        foreach ($document->properties()->with('property')->get() as $value) {
            $key = $value->property->key ?? null;
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Banking\BankAccount;
use App\Domain\Documents\Document;
use App\Domain\Ocr\InvoiceProposal;
use App\Domain\Ocr\InvoiceReader;
use App\Domain\Ocr\InvoiceReading;
use App\Domain\Ocr\OcrWords;
use App\Domain\Org\OrgScope;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use Illuminate\Validation\ValidationException;

/**
 * Turns a recognised document into property suggestions (points 122.5, A253) and learns per sender (A236).
 * suggest(): runs InvoiceReader with the organisation's own IBANs and the sender hints and stores what it finds as
 * suggested values – never over a confirmed one (PropertyValues). learn(): a confirmed IBAN or amount of a document
 * with a known sender is remembered in sender_hints and preferred next time.
 */
final class InvoiceSuggestions
{
    /** Below this a proposal is not stored (only shown as a chip in the GiroCode card). */
    public const MIN_CONFIDENCE = 0.35;

    public function __construct(private readonly PropertyValues $values) {}

    public function read(Document $document): InvoiceReading
    {
        $words = OcrWords::forDocument($document);
        $text = (string) $document->getAttribute('ocr_text');
        $known = $this->values->get($document, 'sender');
        $sender = $known !== null && ! $known->isSuggested() ? $known->value_text : null;

        return $this->reader($document->org_id)->read($words, $text, $sender);
    }

    public function reader(string $orgId): InvoiceReader
    {
        $own = BankAccount::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->whereNotNull('iban')->pluck('iban')
            ->map(static fn ($iban): string => (string) $iban)->values()->all();
        $own = array_values($own);

        return new InvoiceReader($own, function (string $sender) use ($orgId): ?array {
            $hint = SenderHint::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->where('sender_key', self::senderKey($sender))->first();

            return $hint === null ? null : [
                'iban' => $hint->iban, 'amount_keyword' => $hint->amount_keyword, 'amount_page' => $hint->amount_page, 'amount_box' => $hint->amount_box,
            ];
        });
    }

    /** A253: the listener's work – read the document and store the suggestions it may carry (A246). */
    public function suggest(string $documentId): void
    {
        $document = Document::query()->withoutGlobalScopes()->find($documentId);
        if ($document === null) {
            return;
        }
        $reading = $this->read($document);
        $allowed = Property::forDocument($document)->filter(static fn (Property $p): bool => $p->key !== null)->keyBy('key');
        foreach ($reading->suggestions() as $key => $proposal) {
            $property = $allowed->get($key);
            if (! $property instanceof Property || $proposal->confidence < self::MIN_CONFIDENCE) {
                continue;
            }
            try {
                $this->values->set($document, $property, $proposal->value, DocumentProperty::SUGGESTED, $proposal->page, $proposal->box);
            } catch (ValidationException) {
                // A value the property refuses stays a proposal only.
            }
        }
    }

    /** A236: remember the IBAN or where the amount stood, when a person confirmed it for a known sender. */
    public function learn(DocumentProperty $value): void
    {
        if ($value->state !== DocumentProperty::CONFIRMED) {
            return;
        }
        $key = Property::query()->withoutGlobalScope(OrgScope::class)->whereKey($value->property_id)->value('key');
        if (! in_array($key, ['iban', 'invoice_amount'], true)) {
            return;
        }
        $document = Document::query()->withoutGlobalScopes()->find($value->document_id);
        $sender = $document !== null ? $this->values->get($document, 'sender')?->value_text : null;
        if ($document === null || $sender === null || self::senderKey($sender) === '') {
            return;
        }
        $hint = SenderHint::query()->withoutGlobalScope(OrgScope::class)->firstOrNew(['org_id' => $document->org_id, 'sender_key' => self::senderKey($sender)]);
        if ($key === 'iban') {
            $hint->iban = $value->value_text;
        } else {
            $found = $this->amountOnDocument($document, (int) $value->value_int);
            if ($found === null && $value->box === null) {
                return;
            }
            $hint->amount_keyword = $found?->keyword;
            $hint->amount_page = $value->page ?? $found?->page;
            $hint->amount_box = $value->box ?? $found?->box;
        }
        $hint->save();
    }

    /** The place of an amount on the document as the reader sees it (without hints). */
    private function amountOnDocument(Document $document, int $minor): ?InvoiceProposal
    {
        $reading = (new InvoiceReader)->read(OcrWords::forDocument($document), (string) $document->getAttribute('ocr_text'));
        foreach ([$reading->amount, ...$reading->amounts] as $proposal) {
            if ($proposal !== null && $proposal->minor === $minor) {
                return $proposal;
            }
        }

        return null;
    }

    /** "Muster Handwerk GmbH & Co. KG" → "muster handwerk": lower case, legal form and punctuation gone. */
    public static function senderKey(string $sender): string
    {
        $key = mb_strtolower($sender);
        $key = (string) preg_replace('/\b(?:gmbh|mbh|ag|ug|kg|ohg|gbr|kgaa|se|e\.?\s?k|e\.?\s?v|ltd|inc|co|haftungsbeschränkt)\b\.?/u', ' ', $key);
        $key = trim((string) preg_replace(['/[^\p{L}\p{N}]+/u', '/\s+/u'], ' ', $key));

        return mb_substr($key, 0, 120);
    }
}

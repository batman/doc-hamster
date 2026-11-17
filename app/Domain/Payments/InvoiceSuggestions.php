<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Banking\BankAccount;
use App\Domain\Documents\Document;
use App\Domain\Ocr\Invoice\FileNameHints;
use App\Domain\Ocr\InvoiceProposal;
use App\Domain\Ocr\InvoiceReader;
use App\Domain\Ocr\InvoiceReading;
use App\Domain\Ocr\OcrWords;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a recognised document into property suggestions (points 122.5, A253) and learns per sender (A236).
 * suggest(): runs InvoiceReader with the organisation's own IBANs and the sender hints and stores what it finds as
 * suggested values – never over a confirmed one (PropertyValues); a sure invoice date or amount (A401) is stored
 * confirmed. learn(): a confirmed IBAN or amount of a document
 * with a known sender is remembered in sender_hints and preferred next time.
 */
final class InvoiceSuggestions
{
    /** Below this a proposal is not stored (only shown as a chip in the GiroCode card). */
    public const MIN_CONFIDENCE = 0.35;

    /**
     * A401: from here an invoice date or amount is sure (net + tax = gross, or an unambiguous label) and is stored
     * confirmed right away – no ✓ needed; below it stays "vorgeschlagen". A value a person confirmed stays.
     */
    public const SURE = 0.9;

    private const DIRECT = ['invoice_date', 'invoice_amount'];

    /** While suggest() stores a sure value: not a person's confirmation, nothing to learn from. */
    private static bool $applying = false;

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
        // A40: the organisation is the recipient; A42: its accounts' currency is the default.
        $names = array_filter([(string) Organization::query()->whereKey($orgId)->value('name')]);
        $currency = (string) (BankAccount::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)
            ->selectRaw('currency, count(*) as n')->groupBy('currency')->orderByDesc('n')->value('currency') ?? 'EUR');

        return new InvoiceReader($own, ownNames: $names, currency: $currency, hints: function (string $sender) use ($orgId): ?array {
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
        $uploaded = CarbonImmutable::parse($document->created_at ?? 'now');
        $found = FileNameHints::apply($reading->suggestions(), (string) $document->title, $uploaded, $reading->currency ?? 'EUR');
        foreach ($found as $key => $proposal) {
            $property = $allowed->get($key);
            if (! $property instanceof Property || $proposal->confidence < self::MIN_CONFIDENCE) {
                continue;
            }
            $sure = in_array($key, self::DIRECT, true) && $proposal->confidence >= self::SURE;
            $existing = $sure ? $this->values->get($document, $key) : null;
            if ($existing !== null && ! $existing->isSuggested()) {
                continue;
            }
            self::$applying = true;
            try {
                $this->values->set($document, $property, $proposal->value, $sure ? DocumentProperty::CONFIRMED : DocumentProperty::SUGGESTED, $proposal->page, $proposal->box);
            } catch (ValidationException) {
                // A value the property refuses stays a proposal only.
            } finally {
                self::$applying = false;
            }
        }
        // Point 219: the receipt's time, for matching against the booking's time.
        $time = $reading->receiptTime !== null && $reading->receiptTime->confidence >= self::MIN_CONFIDENCE ? $reading->receiptTime->value : null;
        if ($document->doc_time !== $time) {
            DB::table('documents')->where('id', $document->id)->update(['doc_time' => $time]);
        }
    }

    /** A236: remember the IBAN or where the amount stood, when a person confirmed it for a known sender. */
    public function learn(DocumentProperty $value): void
    {
        if ($value->state !== DocumentProperty::CONFIRMED || self::$applying) {
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

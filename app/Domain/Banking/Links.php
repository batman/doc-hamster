<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Documents\Document;
use App\Domain\Org\OrgScope;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a person does with a pair (A268–A272): link by hand (confirmed at once), confirm a suggestion, reject it
 * (never suggested again) or unlink. A confirmed link sets the document's paid_at to the booking day when it has
 * none (A272); unlinking removes it again only if it still is the date this link set – then another confirmed link
 * of the document, if any, sets its own.
 */
final class Links
{
    public function __construct(private readonly PropertyValues $values) {}

    public function link(Transaction $transaction, Document $document, ?string $userId): TransactionMatch
    {
        return DB::transaction(function () use ($transaction, $document, $userId): TransactionMatch {
            MatchRejection::query()->where('transaction_id', $transaction->id)->where('document_id', $document->id)->delete();
            $match = TransactionMatch::query()->firstOrNew(['transaction_id' => $transaction->id, 'document_id' => $document->id]);

            return $this->confirm($match, $userId);
        });
    }

    public function confirm(TransactionMatch $match, ?string $userId): TransactionMatch
    {
        $match->forceFill(['state' => TransactionMatch::CONFIRMED, 'decided_by' => $userId])->save();
        $this->markPaid($match);

        return $match;
    }

    public function reject(TransactionMatch $match): void
    {
        DB::transaction(function () use ($match): void {
            MatchRejection::query()->firstOrCreate(['transaction_id' => $match->transaction_id, 'document_id' => $match->document_id], ['org_id' => $match->org_id]);
            $this->unlink($match);
        });
    }

    public function unlink(TransactionMatch $match): void
    {
        $document = Document::query()->withoutGlobalScope(OrgScope::class)->whereKey($match->document_id)->first();
        $set = $match->paid_at_set?->format('Y-m-d');
        $match->delete();
        if ($document === null || $set === null) {
            return;
        }
        $paid = $this->values->get($document, 'paid_at');
        if ($paid === null || $paid->value_date?->format('Y-m-d') !== $set) {
            return; // changed by hand meanwhile: stays
        }
        $paid->delete();
        // Another confirmed payment of the same document (partial payments, A271) takes over.
        $other = TransactionMatch::query()->where('document_id', $document->id)->where('state', TransactionMatch::CONFIRMED)->oldest()->first();
        if ($other !== null) {
            $other->forceFill(['paid_at_set' => null])->save();
            $this->markPaid($other);
        }
    }

    /** "Kein Beleg nötig" with a reason (A270); null takes it back. */
    public function noReceipt(Transaction $transaction, ?string $reason): void
    {
        $transaction->forceFill(['no_receipt' => $reason !== null, 'no_receipt_reason' => $reason])->save();
    }

    private function markPaid(TransactionMatch $match): void
    {
        if ($match->paid_at_set !== null) {
            return;
        }
        $document = Document::query()->withoutGlobalScope(OrgScope::class)->whereKey($match->document_id)->first();
        $transaction = Transaction::query()->withoutGlobalScope(OrgScope::class)->whereKey($match->transaction_id)->first();
        if ($document === null || $transaction === null || $this->values->get($document, 'paid_at') !== null) {
            return;
        }
        $allowed = Property::forDocument($document)->contains(static fn (Property $p): bool => $p->key === 'paid_at');
        if (! $allowed) {
            return;
        }
        try {
            $date = $transaction->booking_date->format('Y-m-d');
            $this->values->set($document, 'paid_at', $date, DocumentProperty::CONFIRMED);
            $match->forceFill(['paid_at_set' => $date])->save();
        } catch (ValidationException|ModelNotFoundException) {
            // No paid_at property in this organisation: nothing to set.
        }
    }
}

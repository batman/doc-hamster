<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\OrgScope;
use App\Domain\Payments\InvoiceSuggestions;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Automatic matching (A268): after every import (the new bookings against the free documents) and after every
 * recognition (the document against the open bookings). Runs in jobs too, so it never relies on the active
 * organisation: every query names the org. A pair scoring MatchScore::AUTO or more with no close second, on either
 * side, is linked as "vorgeschlagen"; a person confirms it with one click or rejects it (never suggested again).
 * Lower scores are only offered in the booking's "Dokument zuordnen" list (candidates()).
 */
final class Matcher
{
    /** Document facts come from these properties (A268). */
    private const KEYS = ['invoice_amount', 'invoice_date', 'due_date', 'iban', 'sender', 'invoice_number'];

    private const MAX_DOCUMENTS = 3000;

    public function __construct(private readonly InvoiceSuggestions $suggestions) {}

    /**
     * @param  list<string>  $transactionIds
     * @return int how many pairs were linked as suggested
     */
    public function afterImport(string $orgId, array $transactionIds): int
    {
        if ($transactionIds === []) {
            return 0;
        }
        $transactions = $this->openTransactions($orgId)->whereIn('id', $transactionIds)->get();
        $documents = $this->freeDocuments($orgId)->latest()->limit(self::MAX_DOCUMENTS)->get();

        return $this->autoLink($orgId, $transactions, $documents, false);
    }

    /** A new or newly read document against the open bookings. */
    public function afterRecognition(string $documentId): int
    {
        $document = Document::query()->withoutGlobalScope(OrgScope::class)->whereKey($documentId)->first();
        if ($document === null || ! $this->freeDocuments($document->org_id)->whereKey($document->id)->exists()) {
            return 0;
        }
        $transactions = $this->openTransactions($document->org_id)->latest('booking_date')->limit(5000)->get();

        return $this->autoLink($document->org_id, $transactions, collect([$document]), true);
    }

    /**
     * "Dokument zuordnen" (A269): the documents the person may link, best score first, then nearest to the booking day.
     * Without a search only documents near in time or amount are looked at; with one, everything that matches it.
     *
     * @param  Builder<Document>  $visible  documents the person may see
     * @return list<array{document: Document, score: int, reasons: array<string, int>}>
     */
    public function candidates(Transaction $transaction, Builder $visible, ?string $search = null, int $limit = 30): array
    {
        $query = $visible->matchable()->with('files')
            ->whereDoesntHave('matches', static fn (Builder $match) => $match->where('transaction_id', $transaction->id))
            ->whereNotIn('documents.id', MatchRejection::query()->where('transaction_id', $transaction->id)->select('document_id'));

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(static fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('ocr_text', 'like', $like)
                ->orWhereHas('properties', static fn (Builder $p) => $p->where('value_text', 'like', $like)));
            $documents = $query->latest()->limit(200)->get();
        } else {
            $day = $transaction->booking_date;
            $amount = abs($transaction->amount_minor);
            $base = clone $query;
            $documents = $query->where(static fn (Builder $q) => $q
                ->whereBetween('created_at', [$day->copy()->subDays(60)->startOfDay(), $day->copy()->addDays(60)->endOfDay()])
                ->orWhereHas('properties', static fn (Builder $p) => $p->whereBetween('value_int', [$amount - max(1, intdiv($amount, 100)), $amount + max(1, intdiv($amount, 100))])
                    ->whereIn('property_id', Property::query()->where('key', 'invoice_amount')->select('id'))))
                ->latest()->limit(400)->get();
            // Few near ones: the newest documents fill the list, so there is always something to pick.
            if ($documents->count() < $limit) {
                $documents = $documents->concat($base->whereNotIn('documents.id', $documents->pluck('id'))->latest()->limit($limit - $documents->count())->get());
            }
        }

        $facts = $this->facts($documents, false);
        $tx = self::transactionFacts($transaction);
        $scored = $documents->map(static fn (Document $d): array => ['document' => $d, ...MatchScore::score($tx, $facts[$d->id])])->all();
        $day = $transaction->booking_date;
        usort($scored, static fn (array $a, array $b): int => [$b['score'], abs($a['document']->created_at->diffInSeconds($day))]
            <=> [$a['score'], abs($b['document']->created_at->diffInSeconds($day))]);

        return array_slice($scored, 0, $limit);
    }

    /**
     * @return array{date: string, amount: int, counterparty: ?string, purpose: ?string, iban: ?string}
     */
    public static function transactionFacts(Transaction $transaction): array
    {
        return [
            'date' => $transaction->booking_date->format('Y-m-d'),
            'amount' => $transaction->amount_minor,
            'counterparty' => $transaction->counterparty,
            'purpose' => $transaction->purpose,
            'iban' => $transaction->counterparty_iban,
        ];
    }

    /**
     * What the matcher knows about each document: its properties, else (with $read, one document at a time) what
     * the InvoiceReader sees in its text.
     *
     * @param  Collection<int, Document>  $documents
     * @return array<string, array{amount: ?int, amounts: list<int>, invoice_date: ?string, due_date: ?string, uploaded: ?string, iban: ?string, sender: ?string, invoice_number: ?string}>
     */
    public function facts(Collection $documents, bool $read): array
    {
        if ($documents->isEmpty()) {
            return [];
        }
        $orgId = $documents->first()->org_id;
        $keys = Property::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->whereIn('key', self::KEYS)->pluck('key', 'id');
        $values = $documents->pluck('id')->chunk(500)->flatMap(static fn (Collection $ids) => DocumentProperty::query()->withoutGlobalScope(OrgScope::class)
            ->whereIn('document_id', $ids->values())->whereIn('property_id', $keys->keys())->get())
            ->groupBy('document_id');

        $facts = [];
        foreach ($documents as $document) {
            $own = [];
            foreach ($values->get($document->id, collect()) as $value) {
                $own[$keys[$value->property_id]] = $value;
            }
            $fact = [
                'amount' => isset($own['invoice_amount']) ? $own['invoice_amount']->value_int : null,
                'amounts' => [],
                'invoice_date' => (isset($own['invoice_date']) ? $own['invoice_date']->value_date?->format('Y-m-d') : null) ?? $document->doc_date?->format('Y-m-d'),
                'due_date' => isset($own['due_date']) ? $own['due_date']->value_date?->format('Y-m-d') : null,
                'uploaded' => $document->created_at->format('Y-m-d'),
                'iban' => isset($own['iban']) ? $own['iban']->value_text : null,
                'sender' => isset($own['sender']) ? $own['sender']->value_text : null,
                'invoice_number' => isset($own['invoice_number']) ? $own['invoice_number']->value_text : null,
            ];
            if ($read && $fact['amount'] === null) {
                $fact['amounts'] = $this->readAmounts($document);
            }
            $facts[$document->id] = $fact;
        }

        return $facts;
    }

    /**
     * @return list<int>
     */
    private function readAmounts(Document $document): array
    {
        try {
            $reading = $this->suggestions->read($document);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
        $amounts = [];
        foreach ([$reading->amount, ...$reading->amounts] as $proposal) {
            if ($proposal !== null && $proposal->minor !== null) {
                $amounts[] = $proposal->minor;
            }
        }

        return array_values(array_unique($amounts));
    }

    /**
     * Scores every worthwhile pair and links the clear winners greedily (PLAN §9.2).
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Document>  $documents
     */
    private function autoLink(string $orgId, Collection $transactions, Collection $documents, bool $read): int
    {
        if ($transactions->isEmpty() || $documents->isEmpty()) {
            return 0;
        }
        $facts = $this->facts($documents, $read);
        $rejected = MatchRejection::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->get()
            ->mapWithKeys(static fn (MatchRejection $r): array => [$r->transaction_id.'|'.$r->document_id => true]);

        $pairs = [];
        $bestOfTx = [];
        $bestOfDoc = [];
        foreach ($transactions as $transaction) {
            $tx = self::transactionFacts($transaction);
            foreach ($documents as $document) {
                $doc = $facts[$document->id];
                if ($rejected->has($transaction->id.'|'.$document->id) || ! MatchScore::worthScoring($tx, $doc)) {
                    continue;
                }
                $result = MatchScore::score($tx, $doc);
                if ($result['score'] < MatchScore::SUGGEST) {
                    continue;
                }
                $pairs[] = ['tx' => $transaction->id, 'doc' => $document->id, ...$result];
                $bestOfTx[$transaction->id][] = $result['score'];
                $bestOfDoc[$document->id][] = $result['score'];
            }
        }
        usort($pairs, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $second = static function (array $scores, int $score): int {
            rsort($scores);
            $index = array_search($score, $scores, true);
            array_splice($scores, (int) $index, 1);

            return $scores[0] ?? 0;
        };

        $usedTx = [];
        $usedDoc = [];
        $linked = 0;
        foreach ($pairs as $pair) {
            if ($pair['score'] < MatchScore::AUTO || isset($usedTx[$pair['tx']]) || isset($usedDoc[$pair['doc']])) {
                continue;
            }
            if ($second($bestOfTx[$pair['tx']], $pair['score']) > $pair['score'] - MatchScore::UNIQUE_GAP
                || $second($bestOfDoc[$pair['doc']], $pair['score']) > $pair['score'] - MatchScore::UNIQUE_GAP) {
                continue; // not clear: stays an offer in the booking
            }
            TransactionMatch::query()->withoutGlobalScope(OrgScope::class)->firstOrCreate(
                ['transaction_id' => $pair['tx'], 'document_id' => $pair['doc']],
                ['org_id' => $orgId, 'state' => TransactionMatch::SUGGESTED, 'score' => $pair['score'], 'reasons' => $pair['reasons']],
            );
            $usedTx[$pair['tx']] = true;
            $usedDoc[$pair['doc']] = true;
            $linked++;
        }

        return $linked;
    }

    /**
     * @return Builder<Transaction>
     */
    private function openTransactions(string $orgId): Builder
    {
        return Transaction::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)
            ->where('no_receipt', false)->whereDoesntHave('matches', self::anyOrg(...));
    }

    /**
     * @param  Builder<TransactionMatch>  $match
     * @return Builder<TransactionMatch>
     */
    private static function anyOrg(Builder $match): Builder
    {
        return $match->withoutGlobalScope(OrgScope::class);
    }

    /**
     * Documents of folders with bank matching on (issue #77), not linked yet.
     *
     * @return Builder<Document>
     */
    private function freeDocuments(string $orgId): Builder
    {
        return Document::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)
            ->where(static fn (Builder $q) => $q->whereNull('folder_id')
                ->orWhereIn('folder_id', Folder::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->where('bank_matching', true)->select('id')))
            ->whereDoesntHave('matches', self::anyOrg(...));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Documents\Document;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Dauerbeleg" (point 148, A322–A325): "Für alle gleichen Buchungen übernehmen" links a document to every past and
 * future booking of the same counterparty with the same amount and direction (confirmed); the same counterparty with
 * another amount gets it suggested, and confirming that moves the rule's amount (A323). Bookings that already carry
 * another confirmed document, "kein Beleg nötig", refunds and pending ones are left alone. Ending a rule keeps its links.
 */
final class StandingDocuments
{
    public function __construct(private readonly Links $links) {}

    /** The rule from the booking a person just linked the document to; applied at once to the bookings there are. */
    public function create(Transaction $transaction, Document $document, ?string $userId): StandingDocument
    {
        $iban = StandingDocument::iban($transaction->counterparty_iban);
        $name = NoReceiptRule::key($transaction->counterparty);
        $direction = $transaction->amount_minor > 0 ? 'in' : 'out';
        $rule = StandingDocument::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $transaction->org_id)
            ->where('document_id', $document->id)->where('direction', $direction)->where('currency', $transaction->currency)
            ->where('counterparty_iban', $iban)->where('counterparty_name', $name === '' ? null : $name)->first()
            ?? new StandingDocument(['org_id' => $transaction->org_id, 'document_id' => $document->id]);
        $rule->fill([
            'counterparty_iban' => $iban,
            'counterparty_name' => $name === '' ? null : $name,
            'amount_minor' => abs($transaction->amount_minor),
            'currency' => $transaction->currency,
            'direction' => $direction,
            'active' => true,
        ])->save();

        TransactionMatch::query()->withoutGlobalScope(OrgScope::class)->where('transaction_id', $transaction->id)
            ->where('document_id', $document->id)->update(['standing_id' => $rule->id]);
        $this->apply($rule, $this->bookings($rule), $userId);

        return $rule;
    }

    /**
     * New bookings of an import against the active rules (A322). Returns how many were linked or suggested.
     *
     * @param  list<string>  $transactionIds
     */
    public function afterImport(string $orgId, array $transactionIds): int
    {
        if ($transactionIds === []) {
            return 0;
        }
        $rules = StandingDocument::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->where('active', true)->oldest()->get();
        if ($rules->isEmpty()) {
            return 0;
        }
        $count = 0;
        $transactions = $this->open($orgId)->whereIn('id', $transactionIds)->get();
        foreach ($rules as $rule) {
            $count += $this->apply($rule, $transactions, null);
        }

        return $count;
    }

    /** A325: no more links from now on; the ones there are stay. */
    public function end(StandingDocument $rule): void
    {
        $rule->forceFill(['active' => false])->save();
    }

    /** A323: a suggestion of the rule with another amount was confirmed – the rule takes that amount from now on. */
    public static function confirmed(TransactionMatch $match): void
    {
        if ($match->standing_id === null) {
            return;
        }
        $rule = StandingDocument::query()->withoutGlobalScope(OrgScope::class)->whereKey($match->standing_id)->first();
        $transaction = Transaction::query()->withoutGlobalScope(OrgScope::class)->whereKey($match->transaction_id)->first();
        if ($rule === null || $transaction === null || ! $rule->active) {
            return;
        }
        if (abs($transaction->amount_minor) !== $rule->amount_minor) {
            $rule->forceFill(['amount_minor' => abs($transaction->amount_minor)])->save();
        }
        self::guessInterval($rule);
    }

    /**
     * monthly | quarterly | yearly | irregular from the days between the bookings (median); null with fewer than two.
     *
     * @param  iterable<Carbon>  $days
     */
    public static function interval(iterable $days): ?string
    {
        $sorted = collect($days)->map(static fn (Carbon $d): int => (int) $d->copy()->startOfDay()->timestamp)->unique()->sort()->values();
        if ($sorted->count() < 2) {
            return null;
        }
        $gaps = [];
        for ($i = 1; $i < $sorted->count(); $i++) {
            $gaps[] = intdiv($sorted[$i] - $sorted[$i - 1], 86400);
        }
        sort($gaps);
        $median = $gaps[intdiv(count($gaps), 2)];

        return match (true) {
            $median >= 26 && $median <= 35 => 'monthly',
            $median >= 84 && $median <= 98 => 'quarterly',
            $median >= 350 && $median <= 380 => 'yearly',
            default => 'irregular',
        };
    }

    /** The name to show: the counterparty of the newest linked booking, else what the rule knows. */
    public static function label(StandingDocument $rule): string
    {
        $latest = Transaction::query()->withoutGlobalScope(OrgScope::class)
            ->whereIn('id', TransactionMatch::query()->withoutGlobalScope(OrgScope::class)->where('standing_id', $rule->id)->select('transaction_id'))
            ->latest('booking_date')->first();

        return $latest->counterparty ?? $rule->counterparty_name ?? $rule->counterparty_iban ?? '–';
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function apply(StandingDocument $rule, Collection $transactions, ?string $userId): int
    {
        $count = 0;
        foreach ($transactions as $transaction) {
            if (! $rule->sameKind($transaction) || ! $rule->sameCounterparty($transaction) || $transaction->no_receipt || $transaction->parent_id !== null || $transaction->isUnsettled()) {
                continue;
            }
            $matches = TransactionMatch::query()->withoutGlobalScope(OrgScope::class)->where('transaction_id', $transaction->id)->get();
            $own = $matches->firstWhere('document_id', $rule->document_id);
            if ($own !== null && ! $own->isSuggested()) {
                continue; // linked already
            }
            $exact = abs($transaction->amount_minor) === $rule->amount_minor;
            if ($exact) {
                if ($matches->contains(static fn (TransactionMatch $m): bool => ! $m->isSuggested() && $m->document_id !== $rule->document_id)) {
                    continue; // has its own document
                }
                $match = $own ?? new TransactionMatch(['org_id' => $rule->org_id, 'transaction_id' => $transaction->id, 'document_id' => $rule->document_id]);
                $match->forceFill(['standing_id' => $rule->id]);
                $this->links->confirm($match, $userId);
            } else {
                if ($matches->isNotEmpty()) {
                    continue; // a suggestion or a document is there already
                }
                TransactionMatch::query()->withoutGlobalScope(OrgScope::class)->create([
                    'org_id' => $rule->org_id, 'transaction_id' => $transaction->id, 'document_id' => $rule->document_id,
                    'state' => TransactionMatch::SUGGESTED, 'standing_id' => $rule->id, 'reasons' => ['standing' => 0],
                ]);
            }
            $count++;
        }
        self::guessInterval($rule);

        return $count;
    }

    private static function guessInterval(StandingDocument $rule): void
    {
        $days = Transaction::query()->withoutGlobalScope(OrgScope::class)
            ->whereIn('id', TransactionMatch::query()->withoutGlobalScope(OrgScope::class)->where('standing_id', $rule->id)
                ->where('state', TransactionMatch::CONFIRMED)->select('transaction_id'))
            ->pluck('booking_date');
        $interval = self::interval($days->map(static fn ($d): Carbon => Carbon::parse($d)));
        if ($interval !== $rule->interval) {
            $rule->forceFill(['interval' => $interval])->save();
        }
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function bookings(StandingDocument $rule): Collection
    {
        return $this->open($rule->org_id)->where('currency', $rule->currency)
            ->where('amount_minor', $rule->direction === 'in' ? '>' : '<', 0)
            ->when($rule->counterparty_iban !== null, static fn ($q) => $q->whereNotNull('counterparty_iban'), static fn ($q) => $q->whereNotNull('counterparty'))
            ->orderBy('booking_date')->get();
    }

    /**
     * @return Builder<Transaction>
     */
    private function open(string $orgId): Builder
    {
        return Transaction::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->where('no_receipt', false)->whereNull('parent_id');
    }
}

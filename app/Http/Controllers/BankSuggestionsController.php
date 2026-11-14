<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Banking\Matcher;
use App\Domain\Banking\MatchScore;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Belegvorschläge" (point 193, A367): a window beside the booking with the best documents for it – at most ten, best
 * score first, each with its percentage, nothing under MatchScore::SHOW. With a search (`q`) it lists what matches the
 * search instead, score first. A row drags onto the booking or links with its link button; the Bank windows reload.
 */
final class BankSuggestionsController
{
    private const LIMIT = 10;

    private const SEARCH_LIMIT = 30;

    public function show(Request $request, string $id, CurrentOrg $org, Matcher $matcher): View
    {
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $search = trim((string) $request->query('q'));
        $withLinked = $request->boolean('linked');
        $visible = Document::query()->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value));

        if ($search !== '') {
            $suggestions = $matcher->candidates($transaction, $visible, $search, self::SEARCH_LIMIT, $withLinked);
        } else {
            $scored = $matcher->candidates($transaction, $visible, null, 3 * self::LIMIT, $withLinked);
            $suggestions = array_slice(array_values(array_filter($scored, static fn (array $c): bool => $c['score'] >= MatchScore::SHOW)), 0, self::LIMIT);
        }
        $ids = array_map(static fn (array $c): string => $c['document']->id, $suggestions);

        return view('bank.suggestions', [
            'transaction' => $transaction,
            'suggestions' => $suggestions,
            'amounts' => Matcher::invoiceAmounts($ids),
            'elsewhere' => $withLinked ? self::linkedElsewhere($ids) : [],
            'search' => $search,
            'withLinked' => $withLinked,
        ]);
    }

    /**
     * A318: what the documents already got from other bookings - when and from whom, and the sum of their confirmed links.
     *
     * @param  array<string>  $ids
     * @return array<string, array{names: string, sum: int}>
     */
    private static function linkedElsewhere(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TransactionMatch::query()->whereIn('document_id', $ids)->where('state', TransactionMatch::CONFIRMED)->with('transaction')->get()
            ->groupBy('document_id')
            ->map(static fn ($list): array => [
                'names' => $list->map(static fn (TransactionMatch $m): string => $m->transaction->booking_date->format('d.m.Y').' '.($m->transaction->counterparty ?? $m->transaction->purpose ?? '–'))->unique()->take(3)->implode(', '),
                'sum' => (int) $list->sum(static fn (TransactionMatch $m): int => abs($m->transaction->amount_minor)),
            ])->all();
    }
}

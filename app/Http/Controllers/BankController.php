<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bank transactions and their receipts, by hand for now (AK-4.4.5). A person
 * links only receipts they may see; someone else's stays a plain count.
 */
final class BankController
{
    private const VIEWS = ['open', 'matched', 'receipts'];

    private const PER_PAGE = 100;

    public function index(Request $request, CurrentOrg $org): View
    {
        $wanted = $request->query('view');
        $view = is_string($wanted) && in_array($wanted, self::VIEWS, true) ? $wanted : 'open';

        return view('bank.index', [
            'view' => $view,
            'views' => self::VIEWS,
            'transactions' => $view === 'receipts' ? null : $this->transactions($view),
            'documents' => $view === 'receipts' ? $this->receiptsWithoutBooking($org) : null,
        ]);
    }

    public function show(string $id, CurrentOrg $org): View
    {
        $transaction = Transaction::query()->with('account')->whereKey($id)->firstOrFail();

        $linked = $this->visible($org)
            ->with('files')
            ->whereHas('matches', static fn (Builder $match) => $match->where('transaction_id', $transaction->id))
            ->get();

        return view('bank.show', [
            'transaction' => $transaction,
            'linked' => $linked,
            'hidden' => $transaction->matches()->count() - $linked->count(),
            'candidates' => $this->candidates($transaction, $org),
        ]);
    }

    public function match(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['document_id' => ['required', 'string']]);
        $document = $this->visible($org)->whereKey($data['document_id'])->first()
            ?? throw ValidationException::withMessages(['document_id' => __('bank.receipt_gone')]);

        $userId = $org->membership()->user_id;
        TransactionMatch::query()->firstOrCreate(
            ['transaction_id' => $transaction->id, 'document_id' => $document->id],
            ['decided_by' => $userId],
        );
        $audit->record('bank.matched', orgId: $org->id(), userId: $userId, subject: $transaction, meta: ['document' => $document->id], ip: $request->ip());

        return redirect()->route('bank.index')->with('status', __('bank.matched'));
    }

    public function unmatch(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate(['document_id' => ['required', 'string']]);
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $document = $this->visible($org)->whereKey($data['document_id'])->firstOrFail();

        TransactionMatch::query()->where('transaction_id', $transaction->id)->where('document_id', $document->id)->firstOrFail()->delete();
        $audit->record('bank.unmatched', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, meta: ['document' => $document->id], ip: $request->ip());

        return redirect()->route('bank.show', $transaction->id)->with('status', __('bank.unmatched'));
    }

    /**
     * @return Paginator<int, Transaction>
     */
    private function transactions(string $view): Paginator
    {
        $query = Transaction::query()->with('account')->withCount('matches');

        return ($view === 'matched' ? $query->has('matches') : $query->doesntHave('matches'))
            ->orderByDesc('booking_date')
            ->orderBy('id')
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @return Paginator<int, Document>
     */
    private function receiptsWithoutBooking(CurrentOrg $org): Paginator
    {
        return $this->visible($org)->with('files')->doesntHave('matches')->latest()->simplePaginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * Free receipts nearest to the booking day. Two queries, so the database
     * doesn't have to sort everything and any database does it the same way.
     *
     * @return Collection<int, Document>
     */
    private function candidates(Transaction $transaction, CurrentOrg $org): Collection
    {
        $day = $transaction->booking_date->copy()->endOfDay();
        $free = fn () => $this->visible($org)->with('files')->doesntHave('matches');

        return collect($free()->where('created_at', '<=', $day)->latest()->limit(self::PER_PAGE)->get()->all())
            ->concat($free()->where('created_at', '>', $day)->oldest()->limit(self::PER_PAGE)->get()->all())
            ->sortBy(static fn (Document $document): float => abs($document->created_at->diffInSeconds($day)))
            ->values();
    }

    /**
     * @return Builder<Document>
     */
    private function visible(CurrentOrg $org): Builder
    {
        return Document::query()->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value));
    }
}

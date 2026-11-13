<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Links;
use App\Domain\Banking\Matcher;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The Bank module (points 129, A267–A274): the Bank window lists the accounts, an account opens as a window with its
 * bookings, a booking as a window with its documents, the matcher's suggestions and "Dokument zuordnen". A person
 * links only documents they may see; someone else's stay a plain count. Actions answer JSON to the module's script
 * (toast, windows reload) and redirect to the booking without JavaScript.
 */
final class BankController
{
    private const PER_PAGE = 100;

    public function index(CurrentOrg $org): View
    {
        return view('bank.index', $this->base($org));
    }

    public function account(Request $request, string $id, CurrentOrg $org): View
    {
        $account = BankAccount::query()->whereKey($id)->firstOrFail();

        return view('bank.index', [...$this->base($org), ...$this->accountData($request, $account)]);
    }

    /** "Dokumente ohne Buchung": documents to drag onto a booking (A269). */
    public function receipts(Request $request, CurrentOrg $org): View
    {
        $search = trim((string) $request->query('q'));
        $documents = $this->visible($org)->matchable()->with('files')
            ->whereDoesntHave('matches', static fn (Builder $m) => $m->where('state', TransactionMatch::CONFIRMED))
            ->when($search !== '', static fn (Builder $q) => $q->where('title', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%'))
            ->latest()->simplePaginate(self::PER_PAGE)->withQueryString();

        return view('bank.index', [...$this->base($org), 'receipts' => $documents]);
    }

    public function show(Request $request, string $id, CurrentOrg $org, Matcher $matcher): View
    {
        $transaction = Transaction::query()->with('account')->withStatusCounts()->whereKey($id)->firstOrFail();

        $matches = $transaction->matches()->get();
        $linked = $this->visible($org)->with('files')->whereIn('id', $matches->pluck('document_id'))->get()->keyBy('id');
        $amounts = $this->amounts($linked->map(static fn (Document $d): string => $d->id)->values()->all());

        // n:m (A271): what each linked document got from all its confirmed bookings.
        $paid = TransactionMatch::query()->whereIn('document_id', $linked->pluck('id'))->where('state', TransactionMatch::CONFIRMED)
            ->with('transaction')->get()->groupBy('document_id')
            ->map(static fn ($list): array => ['sum' => (int) $list->sum(static fn (TransactionMatch $m): int => abs($m->transaction->amount_minor)), 'count' => $list->count()]);

        $documentsTotal = (int) $linked->sum(static fn (Document $d): int => abs($amounts[$d->id] ?? 0));
        $search = trim((string) $request->query('q'));
        $rows = [];
        foreach ($matches as $match) {
            $document = $linked->get($match->document_id);
            if ($document instanceof Document) {
                $rows[] = ['document' => $document, 'match' => $match, 'amount' => $amounts[$document->id] ?? null, 'paid' => $paid[$document->id] ?? ['sum' => 0, 'count' => 0]];
            }
        }
        // Suggestions first: they wait for a click.
        usort($rows, static fn (array $a, array $b): int => [$b['match']->isSuggested(), $a['document']->created_at] <=> [$a['match']->isSuggested(), $b['document']->created_at]);

        return view('bank.index', [
            ...$this->base($org),
            ...$this->accountData($request, $transaction->account, own: false),
            'transaction' => $transaction,
            'linked' => collect($rows),
            'hidden' => $matches->count() - $linked->count(),
            'sums' => [
                'documents' => $documentsTotal,
                'transaction' => abs($transaction->amount_minor),
                'difference' => abs($transaction->amount_minor) - $documentsTotal,
                'missing' => $linked->contains(static fn (Document $d): bool => ! isset($amounts[$d->id])),
            ],
            'candidates' => $candidates = $matcher->candidates($transaction, $this->visible($org), $search),
            'candidateAmounts' => $this->amounts(array_map(static fn (array $c): string => $c['document']->id, $candidates)),
            'search' => $search,
        ]);
    }

    /** "Zuordnen" in the list of candidates: linked and confirmed at once. */
    public function match(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse|RedirectResponse
    {
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['document_id' => ['required', 'string']]);
        $document = $this->visible($org)->matchable()->whereKey($data['document_id'])->first()
            ?? throw ValidationException::withMessages(['document_id' => __('bank.receipt_gone')]);

        $links->link($transaction, $document, $org->membership()->user_id);
        $audit->record('bank.matched', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, meta: ['document' => $document->id], ip: $request->ip());

        return $this->done($request, $transaction, __('bank.matched'));
    }

    /** Documents dragged onto a booking row (A269): all picked ones at once. */
    public function link(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse|RedirectResponse
    {
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['string']]);
        $documents = $this->visible($org)->matchable()->whereIn('id', $data['ids'])->get();
        if ($documents->isEmpty()) {
            throw ValidationException::withMessages(['ids' => __('bank.receipt_gone')]);
        }
        foreach ($documents as $document) {
            $links->link($transaction, $document, $org->membership()->user_id);
            $audit->record('bank.matched', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, meta: ['document' => $document->id], ip: $request->ip());
        }

        return $this->done($request, $transaction, trans_choice('bank.linked_count', $documents->count(), ['count' => $documents->count()]));
    }

    public function confirm(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse|RedirectResponse
    {
        [$transaction, $match] = $this->pair($request, $id, $org);
        $links->confirm($match, $org->membership()->user_id);
        $audit->record('bank.confirmed', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, meta: ['document' => $match->document_id], ip: $request->ip());

        return $this->done($request, $transaction, __('bank.confirmed'));
    }

    public function reject(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse|RedirectResponse
    {
        [$transaction, $match] = $this->pair($request, $id, $org);
        $links->reject($match);
        $audit->record('bank.rejected', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, meta: ['document' => $match->document_id], ip: $request->ip());

        return $this->done($request, $transaction, __('bank.rejected'));
    }

    public function unmatch(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse|RedirectResponse
    {
        [$transaction, $match] = $this->pair($request, $id, $org);
        $links->unlink($match);
        $audit->record('bank.unmatched', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, meta: ['document' => $match->document_id], ip: $request->ip());

        return $this->done($request, $transaction, __('bank.unmatched'));
    }

    /** A270: "kein Beleg nötig" with a reason; an empty `undo` takes it back. */
    public function noReceipt(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse|RedirectResponse
    {
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $undo = $request->boolean('undo');
        $data = $request->validate(['reason' => [$undo ? 'nullable' : 'required', 'string', 'max:255']]);
        $links->noReceipt($transaction, $undo ? null : trim((string) $data['reason']));
        $audit->record($undo ? 'bank.receipt_needed' : 'bank.no_receipt', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, ip: $request->ip());

        return $this->done($request, $transaction, __($undo ? 'bank.receipt_needed_done' : 'bank.no_receipt_done'));
    }

    /**
     * The Bank window: accounts with their open bookings (A267, A274).
     *
     * @return array<string, mixed>
     */
    private function base(CurrentOrg $org): array
    {
        $accounts = BankAccount::query()
            ->withCount(['transactions', 'transactions as open_count' => static fn (Builder $t) => Transaction::constrainStatus($t, Transaction::OPEN)])
            ->withMax('transactions', 'created_at')
            ->orderBy('name')->get();

        return [
            'accounts' => $accounts,
            'freeReceipts' => $this->visible($org)->matchable()->whereDoesntHave('matches', static fn (Builder $m) => $m->where('state', TransactionMatch::CONFIRMED))->count(),
            'account' => null,
            'transactions' => null,
            'transaction' => null,
            'receipts' => null,
        ];
    }

    /**
     * The account window: newest first, search in counterparty and purpose, status filter (A267, A270).
     *
     * @return array<string, mixed>
     */
    private function accountData(Request $request, BankAccount $account, bool $own = true): array
    {
        // Behind a booking window the query belongs to the booking (its candidate search), not to the account.
        $status = $own ? $request->query('status') : null;
        $status = is_string($status) && in_array($status, Transaction::STATUSES, true) ? $status : null;
        $search = $own ? trim((string) $request->query('q')) : '';
        $counts = [];
        foreach (Transaction::STATUSES as $name) {
            $counts[$name] = Transaction::query()->where('bank_account_id', $account->id)->status($name)->count();
        }

        return [
            'account' => $account,
            'statusCounts' => $counts,
            'status' => $status,
            'accountSearch' => $search,
            'transactions' => $this->transactions($account, $status, $search),
        ];
    }

    /**
     * @return Paginator<int, Transaction>
     */
    private function transactions(BankAccount $account, ?string $status, string $search): Paginator
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return Transaction::query()->where('bank_account_id', $account->id)->withStatusCounts()
            ->when($status !== null, static fn (Builder $q) => $q->status((string) $status))
            ->when($search !== '', static fn (Builder $q) => $q->where(static fn (Builder $w) => $w->where('counterparty', 'like', $like)->orWhere('purpose', 'like', $like)))
            ->orderByDesc('booking_date')->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * The invoice amounts of documents (minor units, confirmed or suggested).
     *
     * @param  array<string>  $ids
     * @return array<string, int>
     */
    private function amounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DocumentProperty::query()->whereIn('document_id', $ids)
            ->whereIn('property_id', Property::query()->where('key', 'invoice_amount')->select('id'))
            ->whereNotNull('value_int')->pluck('value_int', 'document_id')->map(static fn ($v): int => (int) $v)->all();
    }

    /**
     * @return array{Transaction, TransactionMatch}
     */
    private function pair(Request $request, string $id, CurrentOrg $org): array
    {
        $data = $request->validate(['document_id' => ['required', 'string']]);
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $document = $this->visible($org)->whereKey($data['document_id'])->firstOrFail();
        $match = TransactionMatch::query()->where('transaction_id', $transaction->id)->where('document_id', $document->id)->firstOrFail();

        return [$transaction, $match];
    }

    private function done(Request $request, Transaction $transaction, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'transaction' => $transaction->id]);
        }

        return redirect()->route('bank.show', $transaction->id)->with('status', $message);
    }

    /**
     * @return Builder<Document>
     */
    private function visible(CurrentOrg $org): Builder
    {
        return Document::query()->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value));
    }
}

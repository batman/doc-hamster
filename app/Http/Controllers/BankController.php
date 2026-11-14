<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\BankCalendar;
use App\Domain\Banking\BankImport;
use App\Domain\Banking\CsvStatement;
use App\Domain\Banking\Links;
use App\Domain\Banking\Matcher;
use App\Domain\Banking\MccTag;
use App\Domain\Banking\NoReceiptReason;
use App\Domain\Banking\StandingDocument;
use App\Domain\Banking\StandingDocuments;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Documents\Tag;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
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
        $withLinked = $request->boolean('linked');
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
            'candidates' => $candidates = $matcher->candidates($transaction, $this->visible($org), $search, withLinked: $withLinked),
            'candidateAmounts' => $this->amounts(array_map(static fn (array $c): string => $c['document']->id, $candidates)),
            'candidateLinks' => $withLinked ? $this->linkedElsewhere(array_map(static fn (array $c): string => $c['document']->id, $candidates)) : [],
            'search' => $search,
            'withLinked' => $withLinked,
            'reasons' => NoReceiptReason::ordered(true),
            'suggestedReason' => $transaction->suggested_reason_id === null ? null : NoReceiptReason::query()->whereKey($transaction->suggested_reason_id)->first(),
            // A298: a refund hangs on its original; the original lists its refunds.
            'parent' => $transaction->parent,
            'children' => Transaction::query()->where('parent_id', $transaction->id)->orderBy('booking_date')->get(),
            'mccTag' => $transaction->mcc === null ? null : MccTag::ordered()->firstWhere('mcc', $transaction->mcc)?->tag,
        ]);
    }

    /**
     * "Zuordnen" in the list of candidates: linked and confirmed at once. With "Für alle gleichen Buchungen übernehmen"
     * (A322) the document becomes a Dauerbeleg of the counterparty and amount.
     */
    public function match(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links, StandingDocuments $standing): JsonResponse|RedirectResponse
    {
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['document_id' => ['required', 'string'], 'standing' => ['nullable', 'boolean']]);
        $document = $this->visible($org)->matchable()->whereKey($data['document_id'])->first()
            ?? throw ValidationException::withMessages(['document_id' => __('bank.receipt_gone')]);

        $new = $this->unconfirmed($transaction, [$document->id]);
        $links->link($transaction, $document, $org->membership()->user_id);
        $audit->record('bank.matched', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, meta: ['document' => $document->id], ip: $request->ip());
        if ($request->boolean('standing')) {
            $rule = $standing->create($transaction, $document, $org->membership()->user_id);
            $count = TransactionMatch::query()->where('standing_id', $rule->id)->count() - 1;
            $audit->record('bank.standing', orgId: $org->id(), userId: $org->membership()->user_id, subject: $document, meta: ['rule' => $rule->id, 'linked' => $count], ip: $request->ip());

            return $this->done($request, $transaction, trans_choice('bank.standing_done', $count, ['count' => $count]));
        }

        return $this->done($request, $transaction, __('bank.matched'), $new);
    }

    /** Point 147: the booking's note, edited in place (x-edit-in-place answers {"value"}). */
    public function note(Request $request, string $id, AuditLog $audit, CurrentOrg $org): JsonResponse
    {
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['field' => ['required', 'in:note'], 'value' => ['nullable', 'string', 'max:5000']]);
        $value = trim((string) ($data['value'] ?? ''));
        $transaction->forceFill(['note' => $value !== '' ? $value : null])->save();
        $audit->record('bank.noted', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, ip: $request->ip());

        return response()->json(['value' => $value, 'message' => __('bank.note_saved')]);
    }

    /** A325: the Dauerbeleg links nothing more; its links stay. */
    public function endStanding(Request $request, string $id, AuditLog $audit, CurrentOrg $org, StandingDocuments $standing): JsonResponse|RedirectResponse
    {
        $rule = StandingDocument::query()->whereKey($id)->firstOrFail();
        $document = $this->visible($org)->whereKey($rule->document_id)->firstOrFail();
        $standing->end($rule);
        $audit->record('bank.standing_ended', orgId: $org->id(), userId: $org->membership()->user_id, subject: $document, meta: ['rule' => $rule->id], ip: $request->ip());
        if ($request->expectsJson()) {
            return response()->json(['message' => __('bank.standing_ended')]);
        }

        return redirect()->route('documents.show', $rule->document_id)->with('status', __('bank.standing_ended'));
    }

    /**
     * Documents dropped onto a booking (A269, point 160): all picked ones at once, from any window or another browser
     * tab of the same organisation (documents of another one are not found). The answer names the links that are new,
     * so "Rückgängig" removes only those (A339). With the Dauerbeleg tick (A322) a single document becomes a Dauerbeleg.
     */
    public function link(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links, StandingDocuments $standing): JsonResponse|RedirectResponse
    {
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['string'], 'standing' => ['nullable', 'boolean']]);
        $documents = $this->visible($org)->matchable()->whereIn('id', $data['ids'])->get();
        if ($documents->isEmpty()) {
            throw ValidationException::withMessages(['ids' => __('bank.receipt_gone')]);
        }
        $new = $this->unconfirmed($transaction, $documents->modelKeys());
        foreach ($documents as $document) {
            $links->link($transaction, $document, $org->membership()->user_id);
            $audit->record('bank.matched', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, meta: ['document' => $document->id], ip: $request->ip());
        }
        $first = $documents->first();
        if ($request->boolean('standing') && $documents->count() === 1) {
            $rule = $standing->create($transaction, $first, $org->membership()->user_id);
            $count = TransactionMatch::query()->where('standing_id', $rule->id)->count() - 1;
            $audit->record('bank.standing', orgId: $org->id(), userId: $org->membership()->user_id, subject: $first, meta: ['rule' => $rule->id, 'linked' => $count], ip: $request->ip());

            return $this->done($request, $transaction, trans_choice('bank.standing_done', $count, ['count' => $count]));
        }

        return $this->done($request, $transaction, trans_choice('bank.linked_count', $documents->count(), ['count' => $documents->count()]), $new);
    }

    /** "Rückgängig" after a link (A339): the named documents' confirmed links to this booking go. */
    public function unlink(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse|RedirectResponse
    {
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['string']]);
        $matches = TransactionMatch::query()->where('transaction_id', $transaction->id)->where('state', TransactionMatch::CONFIRMED)
            ->whereIn('document_id', $this->visible($org)->whereIn('id', $data['ids'])->select('id'))->get();
        foreach ($matches as $match) {
            $links->unlink($match);
            $audit->record('bank.unmatched', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, meta: ['document' => $match->document_id], ip: $request->ip());
        }

        return $this->done($request, $transaction, trans_choice('bank.unlinked_count', $matches->count(), ['count' => $matches->count()]));
    }

    public function confirm(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse|RedirectResponse
    {
        [$transaction, $match] = $this->pair($request, $id, $org);
        $links->confirm($match, $org->membership()->user_id);
        StandingDocuments::confirmed($match);
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
        // A286/A288: a reason of the list (saved at once), the suggested one (A287) or "Anderer Grund" with a text.
        $data = $request->validate([
            'reason_id' => ['nullable', 'string'],
            'reason' => [$undo || $request->filled('reason_id') ? 'nullable' : 'required', 'string', 'max:255'],
        ]);
        $reason = $undo || ! isset($data['reason_id']) ? null : NoReceiptReason::query()->whereKey($data['reason_id'])->first()
            ?? throw ValidationException::withMessages(['reason_id' => __('bank.reason_gone')]);
        $links->noReceipt($transaction, $reason, $undo || $reason !== null ? null : trim((string) $data['reason']));
        $audit->record($undo ? 'bank.receipt_needed' : 'bank.no_receipt', orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction, ip: $request->ip());

        return $this->done($request, $transaction, __($undo ? 'bank.receipt_needed_done' : 'bank.no_receipt_done'));
    }

    /** A300: the tag the card's category suggests, on a linked document (the tag is made when missing). */
    public function tag(Request $request, string $id, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $transaction = Transaction::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['document_id' => ['required', 'string'], 'tag' => ['required', 'string', 'max:60']]);
        $document = $this->visible($org)->whereKey($data['document_id'])
            ->whereHas('matches', static fn (Builder $m) => $m->where('transaction_id', $transaction->id))->firstOrFail();
        $name = ltrim(trim($data['tag']), '#');
        $tag = Tag::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? Tag::query()->create(['name' => $name, 'color' => Tag::PALETTE[Tag::query()->count() % count(Tag::PALETTE)]]);
        $document->tags()->syncWithoutDetaching([$tag->id]);
        $audit->record('bank.tagged', orgId: $org->id(), userId: $org->membership()->user_id, subject: $document, meta: ['tag' => $tag->name], ip: $request->ip());

        return $this->done($request, $transaction, __('bank.tagged', ['tag' => $tag->name]));
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
     * The account window: newest first, search in counterparty, purposes, reference and the further details (A290),
     * the status filter (A270, A304) and Eingänge/Ausgänge (A311) as button groups in the toolbar, a period in the
     * search panel (159.1); the sums of what is shown (A310) and the imported periods (A296). `view=calendar` shows one
     * month (`month=YYYY-MM`) of the same filtered bookings by day (159.2, A332–A334).
     *
     * @return array<string, mixed>
     */
    private function accountData(Request $request, BankAccount $account, bool $own = true): array
    {
        // Behind a booking window the query belongs to the booking (its candidate search), not to the account.
        $status = $own ? $request->query('status') : null;
        $status = is_string($status) && in_array($status, Transaction::STATUSES, true) ? $status : null;
        $flow = $own ? $request->query('flow') : null;
        $flow = in_array($flow, ['in', 'out'], true) ? $flow : null;
        $from = $own ? CsvStatement::normaliseDate((string) $request->query('from')) : null;
        $to = $own ? CsvStatement::normaliseDate((string) $request->query('to')) : null;
        $search = $own ? trim((string) $request->query('q')) : '';

        $filtered = fn (): Builder => $this->filtered($account, $flow, $from, $to, $search);
        $counts = [];
        foreach (Transaction::STATUSES as $name) {
            $counts[$name] = $filtered()->status($name)->count();
        }
        $shown = $filtered()->when($status !== null, static fn (Builder $q) => $q->status((string) $status))->where('currency', $account->currency);
        // Point 164.4: in and out in one query.
        $sums = (array) (clone $shown)->toBase()->selectRaw('sum(case when amount_minor > 0 then amount_minor else 0 end) as total_in, sum(case when amount_minor < 0 then amount_minor else 0 end) as total_out')->first();
        $in = (int) ($sums['total_in'] ?? 0);
        $out = (int) ($sums['total_out'] ?? 0);
        $listed = static fn (): Builder => $filtered()->withStatusCounts()->when($status !== null, static fn (Builder $q) => $q->status((string) $status));

        // 159.2, A332–A334: the calendar pages only over the months with bookings and shows one month at a time.
        $view = $own && $request->query('view') === 'calendar' ? 'calendar' : 'list';
        $calendar = null;
        if ($view === 'calendar') {
            $range = BankCalendar::range($listed());
            $month = BankCalendar::month($request->query('month'), $range);
            $calendar = [
                'range' => $range,
                'month' => $month,
                'days' => BankCalendar::days($listed()->whereDate('booking_date', '>=', $month->toDateString())->whereDate('booking_date', '<=', $month->endOfMonth()->toDateString())
                    ->orderBy('booking_date')->orderBy('id')->get(), $account->currency),
            ];
        }

        return [
            'account' => $account,
            'statusCounts' => $counts,
            'status' => $status,
            'flow' => $flow,
            'from' => $from,
            'to' => $to,
            'accountSearch' => $search,
            'totals' => ['in' => $in, 'out' => $out, 'sum' => $in + $out],
            'periods' => BankImport::periods($account),
            'accountView' => $view,
            'calendar' => $calendar,
            'transactions' => $view === 'calendar' ? null : $listed()->orderByDesc('booking_date')->orderByDesc('id')
                ->simplePaginate(self::PER_PAGE)->withQueryString(),
        ];
    }

    /**
     * @return Builder<Transaction>
     */
    private function filtered(BankAccount $account, ?string $flow, ?string $from, ?string $to, string $search): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return Transaction::query()->where('bank_account_id', $account->id)
            ->when($flow === 'in', static fn (Builder $q) => $q->where('amount_minor', '>', 0))
            ->when($flow === 'out', static fn (Builder $q) => $q->where('amount_minor', '<', 0))
            ->when($from !== null, static fn (Builder $q) => $q->whereDate('booking_date', '>=', (string) $from))
            ->when($to !== null, static fn (Builder $q) => $q->whereDate('booking_date', '<=', (string) $to))
            ->when($search !== '', static fn (Builder $q) => $q->where(static fn (Builder $w) => $w->where('counterparty', 'like', $like)->orWhere('purpose', 'like', $like)
                ->orWhere('purpose2', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('kind', 'like', $like)->orWhere('extra', 'like', $like)->orWhere('note', 'like', $like)));
    }

    /**
     * A318: what the documents already got from other bookings - when and from whom, and the sum of their confirmed links.
     *
     * @param  array<string>  $ids
     * @return array<string, array{names: string, sum: int}>
     */
    private function linkedElsewhere(array $ids): array
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

    /**
     * Which of the documents have no confirmed link to the booking yet (what an undo may remove again).
     *
     * @param  array<int|string>  $ids
     * @return list<string>
     */
    private function unconfirmed(Transaction $transaction, array $ids): array
    {
        $done = TransactionMatch::query()->where('transaction_id', $transaction->id)->where('state', TransactionMatch::CONFIRMED)
            ->whereIn('document_id', $ids)->pluck('document_id')->map(static fn ($id): string => (string) $id)->all();

        return array_values(array_diff(array_map(static fn ($id): string => (string) $id, $ids), $done));
    }

    /**
     * @param  list<string>  $undo  the documents whose new link "Rückgängig" removes again (A339)
     */
    private function done(Request $request, Transaction $transaction, string $message, array $undo = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'transaction' => $transaction->id,
                'undo' => $undo === [] ? null : ['url' => route('bank.unlink', $transaction->id), 'ids' => $undo]]);
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

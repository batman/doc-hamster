<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Api\AccessKey;
use App\Domain\Api\ApiShape;
use App\Domain\Audit\AuditLog;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\CsvStatement;
use App\Domain\Banking\Links;
use App\Domain\Banking\Matcher;
use App\Domain\Banking\MatchScore;
use App\Domain\Banking\StandingDocuments;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Http\Middleware\AuthenticateAccessKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The interface's bank part (D-040, api/v1): accounts, bookings with their documents and the matcher's suggestions,
 * and – with a "Lesen + Verknüpfen" key and the person's right to work on bookings – link, unlink, confirm and reject.
 * The same rules as the Bank windows (BankController): only documents the person may see, the role's restriction,
 * the organisation's data only. Every change lands in the audit log with the key.
 */
final class BankApiController
{
    private const PER_PAGE = 100;

    private const SUGGESTIONS = 10;

    /** Who the key acts as, and what it may. */
    public function me(Request $request, CurrentOrg $org): JsonResponse
    {
        $key = self::key($request);

        return response()->json([
            'person' => $org->membership()->user?->display_name,
            'organisation' => $org->organization()->name,
            'key' => $key->name,
            'scope' => $key->scope,
            'may_read_bank' => Gate::allows(Permission::ViewBanking->value),
            'may_link' => $key->mayLink() && Gate::allows(Permission::ImportBanking->value),
        ]);
    }

    public function accounts(): JsonResponse
    {
        self::mayRead();
        $accounts = BankAccount::query()->withCount(['transactions', ...array_combine(
            array_map(static fn (string $s): string => 'transactions as '.$s.'_count', Transaction::STATUSES),
            array_map(static fn (string $s): \Closure => static fn (Builder $t) => Transaction::constrainStatus($t, $s), Transaction::STATUSES),
        )])->orderBy('name')->get();

        return response()->json(['data' => $accounts->map(static fn (BankAccount $a): array => [
            'id' => $a->id, 'name' => $a->name, 'kind' => $a->kind, 'currency' => $a->currency, 'iban' => $a->iban,
            'bookings' => (int) $a->getAttribute('transactions_count'),
            'by_status' => array_combine(Transaction::STATUSES, array_map(static fn (string $s): int => (int) $a->getAttribute($s.'_count'), Transaction::STATUSES)),
        ])->all()]);
    }

    /** Bookings, newest first: by account, status (open|suggested|linked|none), in/out, period and a search. */
    public function transactions(Request $request, ApiShape $shape): JsonResponse
    {
        self::mayRead();
        $data = $request->validate([
            'account' => ['nullable', 'string'], 'status' => ['nullable', 'in:'.implode(',', Transaction::STATUSES)],
            'flow' => ['nullable', 'in:in,out'], 'from' => ['nullable', 'string'], 'to' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $from = CsvStatement::normaliseDate((string) ($data['from'] ?? ''));
        $to = CsvStatement::normaliseDate((string) ($data['to'] ?? ''));
        $search = trim((string) ($data['q'] ?? ''));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        $page = Transaction::query()->withStatusCounts()
            ->when(($data['account'] ?? null) !== null, static fn (Builder $q) => $q->where('bank_account_id', $data['account']))
            ->when(($data['status'] ?? null) !== null, static fn (Builder $q) => $q->status((string) $data['status']))
            ->when(($data['flow'] ?? null) === 'in', static fn (Builder $q) => $q->where('amount_minor', '>', 0))
            ->when(($data['flow'] ?? null) === 'out', static fn (Builder $q) => $q->where('amount_minor', '<', 0))
            ->when($from !== null, static fn (Builder $q) => $q->whereDate('booking_date', '>=', (string) $from))
            ->when($to !== null, static fn (Builder $q) => $q->whereDate('booking_date', '<=', (string) $to))
            ->when($search !== '', static fn (Builder $q) => $q->where(static fn (Builder $w) => $w->where('counterparty', 'like', $like)
                ->orWhere('purpose', 'like', $like)->orWhere('purpose2', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('note', 'like', $like)))
            ->orderByDesc('booking_date')->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => array_map($shape->transaction(...), $page->items()),
            'page' => $page->currentPage(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /** One booking: its documents (linked or suggested) and the best other documents for it with their score. */
    public function transaction(string $id, CurrentOrg $org, ApiShape $shape, Matcher $matcher): JsonResponse
    {
        self::mayRead();
        $transaction = Transaction::query()->withStatusCounts()->whereKey($id)->first() ?? abort(404, __('api.booking_missing'));
        $visible = self::visible($org);
        $matches = TransactionMatch::query()->where('transaction_id', $transaction->id)
            ->whereIn('document_id', (clone $visible)->select('id'))->get()->keyBy('document_id');
        $linked = $shape->documents(Document::query()->whereIn('id', $matches->keys())->get());
        $scored = array_slice(array_values(array_filter(
            $matcher->candidates($transaction, $visible, null, 3 * self::SUGGESTIONS),
            static fn (array $c): bool => $c['score'] >= MatchScore::SHOW,
        )), 0, self::SUGGESTIONS);
        $candidates = $shape->documents(collect(array_map(static fn (array $c): Document => $c['document'], $scored)));

        return response()->json([
            ...$shape->transaction($transaction),
            'documents' => array_map(static fn (array $d): array => [...$d, 'state' => $matches->get($d['id'])?->state, 'score' => $matches->get($d['id'])?->score], $linked),
            'suggestions' => array_map(static fn (array $d, array $c): array => [...$d, 'score' => $c['score'], 'reasons' => $c['reasons']], $candidates, $scored),
        ]);
    }

    public function link(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse
    {
        [$key, $transaction, $document] = $this->change($request, $id, $org, true);
        $links->link($transaction, $document, $org->membership()->user_id);
        $this->record($audit, $request, $org, 'bank.matched', $transaction, $document, $key);

        return response()->json(['message' => __('bank.matched'), 'transaction' => $transaction->id, 'document' => $document->id]);
    }

    public function unlink(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse
    {
        [$key, $transaction, $document] = $this->change($request, $id, $org);
        $links->unlink($this->match($transaction, $document));
        $this->record($audit, $request, $org, 'bank.unmatched', $transaction, $document, $key);

        return response()->json(['message' => __('bank.unmatched'), 'transaction' => $transaction->id, 'document' => $document->id]);
    }

    /** A suggestion the matcher made becomes a link. */
    public function confirm(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse
    {
        [$key, $transaction, $document] = $this->change($request, $id, $org, true);
        $match = $this->match($transaction, $document);
        $links->confirm($match, $org->membership()->user_id);
        StandingDocuments::confirmed($match);
        $this->record($audit, $request, $org, 'bank.confirmed', $transaction, $document, $key);

        return response()->json(['message' => __('bank.confirmed'), 'transaction' => $transaction->id, 'document' => $document->id]);
    }

    /** A suggestion goes and is never made again for this pair. */
    public function reject(Request $request, string $id, AuditLog $audit, CurrentOrg $org, Links $links): JsonResponse
    {
        [$key, $transaction, $document] = $this->change($request, $id, $org);
        $links->reject($this->match($transaction, $document));
        $this->record($audit, $request, $org, 'bank.rejected', $transaction, $document, $key);

        return response()->json(['message' => __('bank.rejected'), 'transaction' => $transaction->id, 'document' => $document->id]);
    }

    /**
     * A change needs a "Lesen + Verknüpfen" key and the person's right to work on bookings; a new link ($linking) only
     * a document in a folder with bank matching (like the Bank windows).
     *
     * @return array{AccessKey, Transaction, Document}
     */
    private function change(Request $request, string $id, CurrentOrg $org, bool $linking = false): array
    {
        $key = self::key($request);
        abort_unless($key->mayLink(), 403, __('api.read_only'));
        abort_unless(Gate::allows(Permission::ImportBanking->value), 403, __('api.forbidden'));
        $data = $request->validate(['document_id' => ['required', 'string']]);
        $transaction = Transaction::query()->whereKey($id)->first() ?? abort(404, __('api.booking_missing'));
        $document = self::visible($org)->whereKey($data['document_id'])->first() ?? abort(404, __('api.document_missing'));
        abort_if($linking && ! self::visible($org)->matchable()->whereKey($document->id)->exists(), 422, __('api.not_matchable'));

        return [$key, $transaction, $document];
    }

    private function match(Transaction $transaction, Document $document): TransactionMatch
    {
        return TransactionMatch::query()->where('transaction_id', $transaction->id)->where('document_id', $document->id)->first()
            ?? abort(404, __('api.match_missing'));
    }

    private function record(AuditLog $audit, Request $request, CurrentOrg $org, string $action, Transaction $transaction, Document $document, AccessKey $key): void
    {
        $audit->record($action, orgId: $org->id(), userId: $org->membership()->user_id, subject: $transaction,
            meta: ['document' => $document->id, 'via' => 'api', 'key' => $key->id], ip: $request->ip());
    }

    private static function mayRead(): void
    {
        abort_unless(Gate::allows(Permission::ViewBanking->value), 403, __('api.forbidden'));
    }

    public static function key(Request $request): AccessKey
    {
        $key = $request->attributes->get(AuthenticateAccessKey::ATTRIBUTE);
        abort_unless($key instanceof AccessKey, 401);

        return $key;
    }

    /**
     * @return Builder<Document>
     */
    public static function visible(CurrentOrg $org): Builder
    {
        return Document::query()->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value));
    }
}

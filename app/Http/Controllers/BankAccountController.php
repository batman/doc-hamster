<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\CashBook;
use App\Domain\Banking\CsvStatement;
use App\Domain\Banking\Transaction;
use App\Domain\Org\CurrentOrg;
use App\Domain\Properties\PropertyValues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Accounts set up by hand (point 189) – also before any import: name, kind (Geschäft, Privat, Kasse), currency and
 * the own IBAN, so a later statement with that IBAN lands in it. A cash account (point 190) has no IBAN but an
 * opening balance; its entries are typed in at the top of its window and may be changed or deleted, imported
 * bookings never. Answers JSON to modules/bank.js (toast, windows reload) and redirects without JavaScript.
 */
final class BankAccountController
{
    public function store(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::in(BankAccount::KINDS)],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'iban' => ['nullable', 'string', 'max:42'],
            'opening' => ['nullable', 'string', 'max:30'],
        ]);
        $cash = $data['kind'] === BankAccount::CASH;
        $iban = null;
        if (! $cash && is_string($data['iban'] ?? null) && trim($data['iban']) !== '') {
            $iban = PropertyValues::parseIban($data['iban']) ?? throw ValidationException::withMessages(['iban' => __('properties.errors.iban')]);
            if (BankAccount::query()->where('iban', $iban)->exists()) {
                throw ValidationException::withMessages(['iban' => __('bank.iban_taken')]);
            }
        }
        $opening = $cash ? $this->amount($data['opening'] ?? null, 'opening', true) : 0;

        $account = BankAccount::query()->create([
            'name' => trim($data['name']),
            'kind' => $data['kind'],
            'currency' => strtoupper($data['currency'] ?? 'EUR'),
            'iban' => $iban,
            'opening_balance_minor' => $opening,
        ]);
        $audit->record('bank.account_created', orgId: $org->id(), userId: $org->membership()->user_id, subject: $account, meta: ['kind' => $account->kind], ip: $request->ip());

        return $this->answer($request, __('bank.account_created'), $account, open: true);
    }

    /** Point 189: Geschäft or Privat, saved at once (a cash box stays a cash box). */
    public function kind(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $account = BankAccount::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['kind' => ['required', Rule::in([BankAccount::BUSINESS, BankAccount::PRIVATE])]]);
        if (! $account->isCash()) {
            $account->update(['kind' => $data['kind']]);
        }

        return $request->expectsJson()
            ? response()->json(['message' => __('bank.saved')])
            : redirect()->route('bank.import')->with('status', __('bank.saved'));
    }

    /** Point 190: a cash entry – date, in or out, amount, text; saved at once. */
    public function storeEntry(Request $request, string $id, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $account = BankAccount::query()->whereKey($id)->where('kind', BankAccount::CASH)->firstOrFail();
        [$date, $amount, $text] = $this->entry($request);
        $entry = CashBook::add($account, $date, $amount, $text);
        $audit->record('bank.cash_added', orgId: $org->id(), userId: $org->membership()->user_id, subject: $entry, meta: ['amount' => $amount], ip: $request->ip());

        return $this->answer($request, __('bank.cash_added'), $account);
    }

    public function updateEntry(Request $request, string $id, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $entry = Transaction::query()->whereKey($id)->where('source', Transaction::MANUAL)->firstOrFail();
        [$date, $amount, $text] = $this->entry($request);
        CashBook::change($entry, $date, $amount, $text);
        $audit->record('bank.cash_changed', orgId: $org->id(), userId: $org->membership()->user_id, subject: $entry, meta: ['amount' => $amount], ip: $request->ip());

        return $this->answer($request, __('bank.saved'), $entry->account);
    }

    public function destroyEntry(Request $request, string $id, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $entry = Transaction::query()->whereKey($id)->where('source', Transaction::MANUAL)->firstOrFail();
        $account = $entry->account;
        $audit->record('bank.cash_deleted', orgId: $org->id(), userId: $org->membership()->user_id, subject: $entry, meta: ['amount' => $entry->amount_minor], ip: $request->ip());
        CashBook::remove($entry);

        return $this->answer($request, __('bank.cash_deleted'), $account);
    }

    /**
     * @return array{string, int, string}
     */
    private function entry(Request $request): array
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'flow' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'string', 'max:30'],
            'text' => ['required', 'string', 'max:255'],
        ]);
        $amount = abs($this->amount($data['amount'], 'amount', false));

        return [$data['date'], $data['flow'] === 'out' ? -$amount : $amount, trim($data['text'])];
    }

    /** A typed amount ("12,50", "1.234,00 €") in minor units; the opening balance may be empty (0) or negative. */
    private function amount(?string $value, string $field, bool $optional): int
    {
        $value = trim((string) $value);
        if ($optional && $value === '') {
            return 0;
        }
        $minor = CsvStatement::amountToMinor($value);
        if ($minor === null || (! $optional && $minor === 0)) {
            throw ValidationException::withMessages([$field => __('bank.amount_invalid')]);
        }

        return $minor;
    }

    private function answer(Request $request, string $message, BankAccount $account, bool $open = false): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'account' => $account->id, 'open' => $open ? route('bank.account', $account->id) : null]);
        }

        return redirect()->route('bank.account', $account->id)->with('status', $message);
    }
}

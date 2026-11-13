<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\CsvStatement;
use App\Domain\Banking\Matcher;
use App\Domain\Banking\StatementImport;
use App\Domain\Banking\XlsxSheet;
use App\Domain\Org\CurrentOrg;
use App\Domain\Properties\PropertyValues;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Bank statements from any bank (D-021), CSV or Excel (F18): upload – from Einstellungen → Bank or dropped onto the
 * Bank module (A267) –, choose the columns with a look at the first rows, import. The columns are remembered per
 * account by their names; an account whose columns are known imports the next file without asking. A file dropped
 * onto the Bank window finds its account by the account's IBAN in the file. The file stays only until the import
 * ran; nothing but the transactions is kept. Every import ends with the matcher (A268).
 */
final class BankImportController
{
    private const FIELDS = ['date', 'amount', 'counterparty', 'purpose', 'iban'];

    public function form(): View
    {
        return view('bank.import', ['accounts' => BankAccount::query()->withCount('transactions')->orderBy('name')->get()]);
    }

    public function upload(Request $request, AuditLog $audit, CurrentOrg $org, Matcher $matcher): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'account' => ['nullable', Rule::in(BankAccount::query()->pluck('id')->all())],
            'name' => ['required_without:account', 'nullable', 'string', 'max:150'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            // Point 120.1: the account's own IBAN, never proposed as the payee of an invoice.
            'iban' => ['nullable', 'string', 'max:42', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && PropertyValues::parseIban($value) === null) {
                    $fail(__('properties.errors.iban'));
                }
            }],
        ]);

        $upload = $request->file('file');
        $content = $upload instanceof UploadedFile ? (string) file_get_contents($upload->getRealPath()) : '';

        $statement = CsvStatement::read($content);
        if ($statement->header === [] || $statement->rows === []) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('bank.wrong_type')], 422);
            }

            return back()->withInput()->withErrors(['file' => __('bank.wrong_type')]);
        }

        $accountId = $data['account'] ?? $this->accountByIban($content);

        $this->forget($request);
        $this->clearOldFiles($org);

        $path = 'bank-imports/'.$org->id().'/'.Str::lower((string) Str::ulid()).'.upload';
        Storage::disk('local')->put($path, $content);

        $pending = [
            'org' => $org->id(),
            'path' => $path,
            'account' => $accountId,
            'name' => $data['name'] ?? null,
            'currency' => Str::upper($data['currency'] ?? 'EUR'),
            'iban' => is_string($data['iban'] ?? null) ? PropertyValues::parseIban($data['iban']) : null,
        ];
        $request->session()->put('bank_import', $pending);

        // A267: the second import of an account asks nothing.
        $account = $accountId === null ? null : BankAccount::query()->whereKey($accountId)->first();
        $known = $account === null ? null : $this->knownColumns($statement, $account);
        if ($account !== null && $known !== null) {
            $result = $this->import($request, $audit, $org, $matcher, $pending, $statement, $known, null);
            if ($result !== null) {
                [$account, $message] = $result;

                return $request->expectsJson()
                    ? response()->json(['message' => $message, 'account' => $account->id, 'url' => route('bank.account', $account->id)])
                    : redirect()->route('bank.account', $account->id)->with('status', $message);
            }
        }

        return $request->expectsJson()
            ? response()->json(['columns' => route('bank.import.columns')])
            : redirect()->route('bank.import.columns');
    }

    public function columns(Request $request, CurrentOrg $org): View|RedirectResponse
    {
        $pending = $this->pending($request, $org);
        if ($pending === null) {
            return redirect()->route('bank.import')->withErrors(['file' => __('bank.expired')]);
        }

        $statement = $this->statement($pending['path']);
        $account = $pending['account'] === null ? null : BankAccount::query()->whereKey($pending['account'])->firstOrFail();

        return view('bank.columns', [
            'statement' => $statement,
            'preview' => array_slice($statement->rows, 0, 5),
            'fields' => self::FIELDS,
            'columns' => $this->preselect($statement, $account),
            'account' => $account,
            'accountName' => $account === null ? $pending['name'] : $account->name,
        ]);
    }

    public function run(Request $request, AuditLog $audit, CurrentOrg $org, Matcher $matcher): RedirectResponse
    {
        $pending = $this->pending($request, $org);
        if ($pending === null) {
            return redirect()->route('bank.import')->withErrors(['file' => __('bank.expired')]);
        }

        $statement = $this->statement($pending['path']);
        $last = count($statement->header) - 1;
        $data = $request->validate([
            'date' => ['required', 'integer', 'between:0,'.$last],
            'amount' => ['required', 'integer', 'between:0,'.$last],
            'counterparty' => ['nullable', 'integer', 'between:0,'.$last],
            'purpose' => ['nullable', 'integer', 'between:0,'.$last],
            'iban' => ['nullable', 'integer', 'between:0,'.$last],
            'name' => ['nullable', 'string', 'max:150'],
        ]);
        $columns = ['date' => (int) $data['date'], 'amount' => (int) $data['amount']];
        foreach (['counterparty', 'purpose', 'iban'] as $field) {
            $columns[$field] = isset($data[$field]) ? (int) $data[$field] : null;
        }

        $result = $this->import($request, $audit, $org, $matcher, $pending, $statement, $columns, is_string($data['name'] ?? null) && trim($data['name']) !== '' ? trim($data['name']) : null);
        if ($result === null) {
            return back()->withErrors(['date' => __('bank.none_readable')]);
        }
        [$account, $message] = $result;

        return redirect()->route('bank.account', $account->id)->with('status', $message);
    }

    /**
     * Gets rid of a wrong import too: the bookings can be imported again, the links can't.
     */
    public function destroyAccount(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $account = BankAccount::query()->withCount('transactions')->whereKey($id)->firstOrFail();
        $count = (int) $account->getAttribute('transactions_count');

        if ($count > 0 && ! $request->boolean('confirmed')) {
            return redirect()->route('bank.import')->with('confirm_delete', ['id' => $account->id, 'name' => $account->name, 'count' => $count]);
        }

        $audit->record('bank.account_deleted', orgId: $org->id(), userId: $org->membership()->user_id, subject: $account, meta: ['name' => $account->name, 'transactions' => $count], ip: $request->ip());
        $account->delete();

        return redirect()->route('bank.import')->with('status', __('bank.account_deleted'));
    }

    /**
     * Stores the readable rows, remembers the columns and lets the matcher run; null if no row was readable.
     *
     * @param  array{org: string, path: string, account: string|null, name: string|null, currency: string, iban?: string|null}  $pending
     * @param  array{date: int, amount: int, counterparty: int|null, purpose: int|null, iban: int|null}  $columns
     * @return array{BankAccount, string}|null
     */
    private function import(Request $request, AuditLog $audit, CurrentOrg $org, Matcher $matcher, array $pending, CsvStatement $statement, array $columns, ?string $name): ?array
    {
        $read = StatementImport::read($statement, $columns);
        if ($read['lines'] === []) {
            return null;
        }

        $account = $pending['account'] === null
            ? BankAccount::query()->create(['name' => $name ?? $pending['name'], 'currency' => $pending['currency']])
            : BankAccount::query()->whereKey($pending['account'])->firstOrFail();

        // By name, not by position: a changed layout then falls back to the guess.
        $account->update(['import_profile' => array_map(
            static fn (int $index): string => $statement->header[$index],
            array_filter($columns, static fn (?int $index): bool => $index !== null),
        ), ...(is_string($pending['iban'] ?? null) ? ['iban' => $pending['iban']] : [])]);

        $new = StatementImport::store($account, $read['lines']);
        $this->forget($request);
        $suggested = $matcher->afterImport($org->id(), $new);

        $summary = ['new' => count($new), 'known' => count($read['lines']) - count($new), 'unreadable' => $read['unreadable']];
        $audit->record('bank.imported', orgId: $org->id(), userId: $org->membership()->user_id, subject: $account, meta: [...$summary, 'suggested' => $suggested], ip: $request->ip());

        $message = __('bank.imported', $summary);
        if ($suggested > 0) {
            $message .= ' '.trans_choice('bank.suggested_count', $suggested, ['count' => $suggested]);
        }

        return [$account, $message];
    }

    /**
     * The remembered columns when date and amount are found again by their names; null means ask.
     *
     * @return array{date: int, amount: int, counterparty: int|null, purpose: int|null, iban: int|null}|null
     */
    private function knownColumns(CsvStatement $statement, BankAccount $account): ?array
    {
        $profile = $account->import_profile ?? [];
        $columns = [];
        foreach (self::FIELDS as $field) {
            $index = isset($profile[$field]) ? array_search($profile[$field], $statement->header, true) : false;
            $columns[$field] = $index === false ? null : (int) $index;
        }
        if ($columns['date'] === null || $columns['amount'] === null) {
            return null;
        }

        /** @var array{date: int, amount: int, counterparty: int|null, purpose: int|null, iban: int|null} $columns */
        return $columns;
    }

    /** A file dropped without an account: the account whose own IBAN stands in it (banks print it above the table). */
    private function accountByIban(string $content): ?string
    {
        $text = str_starts_with($content, "PK\x03\x04")
            ? implode(' ', array_map(static fn (array $row): string => implode(' ', $row), array_slice(XlsxSheet::rows($content) ?? [], 0, 30)))
            : mb_substr($content, 0, 4000);
        $compact = strtoupper((string) preg_replace('/\s+/', '', $text));

        foreach (BankAccount::query()->whereNotNull('iban')->get(['id', 'iban']) as $account) {
            if ($account->iban !== null && $account->iban !== '' && str_contains($compact, $account->iban)) {
                return $account->id;
            }
        }

        return null;
    }

    /**
     * @return array<string, int|null>
     */
    private function preselect(CsvStatement $statement, ?BankAccount $account): array
    {
        $columns = $statement->guessColumns();

        foreach ($account->import_profile ?? [] as $field => $name) {
            $index = array_search($name, $statement->header, true);
            if ($index !== false && in_array($field, self::FIELDS, true)) {
                $columns[$field] = $index;
            }
        }

        return $columns;
    }

    private function statement(string $path): CsvStatement
    {
        return CsvStatement::read((string) Storage::disk('local')->get($path));
    }

    /**
     * The upload of this session, for the active organisation only.
     *
     * @return array{org: string, path: string, account: string|null, name: string|null, currency: string, iban?: string|null}|null
     */
    private function pending(Request $request, CurrentOrg $org): ?array
    {
        /** @var array{org: string, path: string, account: string|null, name: string|null, currency: string, iban?: string|null}|null $pending */
        $pending = $request->session()->get('bank_import');

        return $pending !== null && $pending['org'] === $org->id() && Storage::disk('local')->exists($pending['path']) ? $pending : null;
    }

    private function forget(Request $request): void
    {
        /** @var array{path: string}|null $pending */
        $pending = $request->session()->pull('bank_import');

        if ($pending !== null) {
            Storage::disk('local')->delete($pending['path']);
        }
    }

    /** Uploads nobody finished: a statement shouldn't lie around. */
    private function clearOldFiles(CurrentOrg $org): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files('bank-imports/'.$org->id()) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\CsvStatement;
use App\Domain\Banking\Links;
use App\Domain\Banking\Matcher;
use App\Domain\Banking\StandingDocuments;
use App\Domain\Banking\StatementColumns;
use App\Domain\Banking\StatementImporter;
use App\Domain\Banking\Transaction;
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
use Illuminate\Validation\ValidationException;

/**
 * Bank statements from any bank (D-021): CSV, Excel (F18) or CAMT.053 (A297). Upload – from Einstellungen → Bank or
 * dropped onto the Bank module (A267) –, the columns are mapped (A289, A301: known layouts like Revolut without
 * questions, A292), the dedupe plan is shown when something is unclear or differs (A295, A302), import. The columns
 * are remembered per account by their names. A file dropped onto the Bank window finds its account by the account's
 * IBAN in the file. The file stays only until the import ran. Every import ends with the matcher (A268) and the
 * remembered "kein Beleg nötig" counterparties (A287).
 */
final class BankImportController
{
    public function form(): View
    {
        return view('bank.import', ['accounts' => BankAccount::query()->withCount('transactions')->orderBy('name')->get()]);
    }

    public function upload(Request $request, AuditLog $audit, CurrentOrg $org, Matcher $matcher): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'account' => ['nullable', Rule::in(BankAccount::query()->pluck('id')->all())],
            'name' => ['nullable', 'string', 'max:150'],
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

        $name = is_string($data['name'] ?? null) && trim($data['name']) !== '' ? trim($data['name']) : null;
        $pending = [
            'org' => $org->id(),
            'path' => $path,
            'account' => $accountId,
            'name' => $name,
            'currency' => Str::upper($data['currency'] ?? 'EUR'),
            'iban' => is_string($data['iban'] ?? null) ? PropertyValues::parseIban($data['iban']) : null,
            'columns' => null,
        ];

        // A267/A292: known columns (remembered, or a layout like Revolut) ask nothing; a new account then gets its name itself (142).
        $account = $accountId === null ? null : BankAccount::query()->whereKey($accountId)->first();
        $guess = StatementColumns::guess($statement, $this->profile($statement, $account));
        $sure = ($guess['sure']['date'] ?? false) && ($guess['sure']['amount'] ?? false);
        if ($sure && ($account !== null || $guess['source'] !== null)) {
            $pending['columns'] = $guess['columns'];
        }
        $request->session()->put('bank_import', $pending);

        if ($pending['columns'] !== null) {
            return $this->proceed($request, $audit, $org, $matcher, $pending, $statement, []);
        }

        return $request->expectsJson()
            ? response()->json(['columns' => route('bank.import.columns')])
            : redirect()->route('bank.import.columns');
    }

    /** A301: each column with three sample values and the proposed field, sure green, unsure orange. */
    public function columns(Request $request, CurrentOrg $org): View|RedirectResponse
    {
        $pending = $this->pending($request, $org);
        if ($pending === null) {
            return redirect()->route('bank.import')->withErrors(['file' => __('bank.expired')]);
        }

        $statement = $this->statement($pending['path']);
        $account = $pending['account'] === null ? null : BankAccount::query()->whereKey($pending['account'])->firstOrFail();
        $guess = StatementColumns::guess($statement, $this->profile($statement, $account));

        $byColumn = [];
        foreach ($guess['columns'] as $field => $index) {
            foreach ((array) $index as $i) {
                $byColumn[$i] ??= $field;
            }
        }
        $samples = [];
        foreach (array_keys($statement->header) as $index) {
            $values = [];
            foreach ($statement->rows as $row) {
                if (trim($row[$index] ?? '') !== '') {
                    $values[] = Str::limit(trim($row[$index]), 40);
                }
                if (count($values) === 3) {
                    break;
                }
            }
            $samples[$index] = $values;
        }

        return view('bank.columns', [
            'statement' => $statement,
            'samples' => $samples,
            'fields' => StatementColumns::FIELDS,
            'byColumn' => $byColumn,
            'sure' => $guess['sure'],
            'account' => $account,
            'accountName' => $account === null
                ? ($pending['name'] ?? StatementImporter::proposeName($statement, $guess['source'], null, $pending['currency']))
                : $account->name,
        ]);
    }

    public function run(Request $request, AuditLog $audit, CurrentOrg $org, Matcher $matcher): RedirectResponse
    {
        $pending = $this->pending($request, $org);
        if ($pending === null) {
            return redirect()->route('bank.import')->withErrors(['file' => __('bank.expired')]);
        }

        $statement = $this->statement($pending['path']);
        $data = $request->validate([
            'map' => ['nullable', 'array'],
            'map.*' => ['nullable', Rule::in(StatementColumns::FIELDS)],
            'name' => ['nullable', 'string', 'max:150'],
            // The fields one by one (older form, tests): field → column.
            ...array_fill_keys(StatementColumns::FIELDS, ['nullable', 'integer', 'between:0,'.(count($statement->header) - 1)]),
        ]);
        $columns = array_fill_keys(StatementColumns::FIELDS, null);
        foreach ($data['map'] ?? [] as $index => $field) {
            if (is_string($field) && $field !== '' && isset($statement->header[(int) $index])) {
                if ($columns[$field] !== null) {
                    throw ValidationException::withMessages(['map' => __('bank.field_twice', ['field' => __('bank.columns.'.$field)])]);
                }
                $columns[$field] = (int) $index;
            }
        }
        foreach (StatementColumns::FIELDS as $field) {
            if (isset($data[$field])) {
                $columns[$field] = (int) $data[$field];
            }
        }
        if ($columns['date'] === null || $columns['amount'] === null) {
            throw ValidationException::withMessages(['date' => __('bank.need_date_amount')]);
        }
        $pending['columns'] = $columns;
        if (is_string($data['name'] ?? null) && trim($data['name']) !== '') {
            $pending['name'] = trim($data['name']);
        }
        $request->session()->put('bank_import', $pending);

        $response = $this->proceed($request, $audit, $org, $matcher, $pending, $statement, []);

        return $response instanceof RedirectResponse ? $response : redirect()->route('bank.import.preview');
    }

    /** A295, A302: "Neu: x · Schon vorhanden: y · Unklar: z", the unclear ones to decide, the differing values. */
    public function preview(Request $request, CurrentOrg $org): View|RedirectResponse
    {
        $pending = $this->pending($request, $org);
        if ($pending === null || $pending['columns'] === null) {
            return redirect()->route('bank.import')->withErrors(['file' => __('bank.expired')]);
        }
        $statement = $this->statement($pending['path']);
        $prepared = $this->prepare($statement, $pending);
        $counts = ['new' => 0, 'known' => 0, 'unclear' => 0];
        $unclear = [];
        $differing = [];
        foreach ($prepared['groups'] as $group) {
            foreach ($group['plan'] as $index => $entry) {
                $counts[$entry['action']]++;
                if ($entry['action'] === 'unclear') {
                    $unclear[$index] = $entry;
                }
                if ($entry['action'] === 'known' && $entry['diffs'] !== []) {
                    $differing[$index] = $entry;
                }
            }
        }
        $existing = Transaction::query()
            ->whereIn('id', array_filter(array_map(static fn (array $e): ?string => $e['existing'], [...$unclear, ...$differing])))->get()->keyBy('id');

        return view('bank.preview', ['prepared' => $prepared, 'counts' => $counts, 'unclear' => $unclear, 'differing' => $differing, 'existing' => $existing]);
    }

    public function confirm(Request $request, AuditLog $audit, CurrentOrg $org, Matcher $matcher): RedirectResponse
    {
        $pending = $this->pending($request, $org);
        if ($pending === null || $pending['columns'] === null) {
            return redirect()->route('bank.import')->withErrors(['file' => __('bank.expired')]);
        }
        $data = $request->validate(['decide' => ['nullable', 'array'], 'decide.*' => ['in:new,known']]);
        $response = $this->proceed($request, $audit, $org, $matcher, $pending, $this->statement($pending['path']), $data['decide'] ?? [], force: true);

        return $response instanceof RedirectResponse ? $response : redirect()->route('bank.index');
    }

    /** 142: renaming stays possible. */
    public function rename(Request $request, string $id): RedirectResponse
    {
        $account = BankAccount::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $account->update(['name' => trim($data['name'])]);

        return redirect()->route('bank.import')->with('status', __('bank.renamed'));
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
     * Imports at once when nothing is unclear or differs (or $force: the preview was confirmed); otherwise sends to the
     * preview. Null result when no row was readable.
     *
     * @param  array{org: string, path: string, account: string|null, name: string|null, currency: string, iban?: string|null, columns: array<string, int|list<int>|null>|null}  $pending
     * @param  array<int|string, string>  $decisions
     */
    private function proceed(Request $request, AuditLog $audit, CurrentOrg $org, Matcher $matcher, array $pending, CsvStatement $statement, array $decisions, bool $force = false): RedirectResponse|JsonResponse
    {
        $prepared = $this->prepare($statement, $pending);
        if ($prepared['lines'] === 0) {
            // The upload stays: another mapping may read it.
            $error = __('bank.none_readable');
            if ($request->expectsJson()) {
                return response()->json(['message' => $error], 422);
            }

            return back()->withErrors(['date' => $error]);
        }

        $asks = collect($prepared['groups'])->contains(static fn (array $g): bool => collect($g['plan'])->contains(static fn (array $e): bool => $e['action'] === 'unclear' || $e['diffs'] !== []));
        if ($asks && ! $force) {
            return $request->expectsJson()
                ? response()->json(['columns' => route('bank.import.preview')])
                : redirect()->route('bank.import.preview');
        }

        /** @var array<string, int|list<int>|null> $columns */
        $columns = $pending['columns'];
        $profile = [];
        foreach ($columns as $field => $index) {
            if ($index !== null) {
                $names = array_map(static fn (int $i): string => $statement->header[$i], (array) $index);
                $profile[$field] = count($names) === 1 ? $names[0] : $names;
            }
        }

        $result = StatementImporter::store($statement, $prepared, $decisions, $profile, $pending['iban'] ?? null);
        $this->forget($request);
        // A322: Dauerbelege first; the matcher leaves bookings with a link alone.
        $suggested = app(StandingDocuments::class)->afterImport($org->id(), $result['new']);
        $suggested += $matcher->afterImport($org->id(), [...$result['new'], ...$result['completed']]);
        $noReceipt = Links::suggestNoReceipt($result['new']);

        $summary = ['new' => count($result['new']), 'known' => $result['known'], 'unreadable' => $prepared['unreadable']];
        $account = $result['accounts'][0];
        $audit->record('bank.imported', orgId: $org->id(), userId: $org->membership()->user_id, subject: $account, meta: [...$summary, 'updated' => $result['updated'], 'suggested' => $suggested, 'accounts' => count($result['accounts'])], ip: $request->ip());

        $message = __('bank.imported', $summary);
        if ($result['updated'] > 0) {
            $message .= ' '.trans_choice('bank.updated_count', $result['updated'], ['count' => $result['updated']]);
        }
        if (count($result['accounts']) > 1) {
            $message .= ' '.__('bank.accounts_used', ['count' => count($result['accounts'])]);
        }
        if ($suggested > 0) {
            $message .= ' '.trans_choice('bank.suggested_count', $suggested, ['count' => $suggested]);
        }
        if ($noReceipt > 0) {
            $message .= ' '.trans_choice('bank.no_receipt_suggested_count', $noReceipt, ['count' => $noReceipt]);
        }

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'account' => $account->id, 'url' => route('bank.account', $account->id)])
            : redirect()->route('bank.account', $account->id)->with('status', $message);
    }

    /**
     * @param  array{account: string|null, name: string|null, currency: string, columns: array<string, int|list<int>|null>|null}  $pending
     * @return array{groups: list<array{key: string, account: BankAccount|null, name: string, currency: string, sub: string|null, plan: array<int, array{line: array<string, mixed>, action: string, existing: string|null, fills: array<string, mixed>, diffs: array<string, array{0: mixed, 1: mixed}>}>}>, unreadable: int, lines: int}
     */
    private function prepare(CsvStatement $statement, array $pending): array
    {
        $account = $pending['account'] === null ? null : BankAccount::query()->whereKey($pending['account'])->first();

        return StatementImporter::prepare($statement, $pending['columns'] ?? [], $account, $pending['name'], $pending['currency']);
    }

    /**
     * A301 step 3: the columns the account remembered; without an account those of another account whose column
     * names are all in this file (the same bank's layout).
     *
     * @return array<string, string|list<string>>
     */
    private function profile(CsvStatement $statement, ?BankAccount $account): array
    {
        if ($account !== null) {
            return $account->import_profile ?? [];
        }
        foreach (BankAccount::query()->whereNotNull('import_profile')->latest('updated_at')->get() as $other) {
            $profile = $other->import_profile ?? [];
            $names = array_merge(...array_map(static fn (string|array $n): array => (array) $n, array_values($profile ?: ['' => []])));
            if ($profile !== [] && isset($profile['date'], $profile['amount']) && array_diff($names, $statement->header) === []) {
                return $profile;
            }
        }

        return [];
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

    private function statement(string $path): CsvStatement
    {
        return CsvStatement::read((string) Storage::disk('local')->get($path));
    }

    /**
     * The upload of this session, for the active organisation only.
     *
     * @return array{org: string, path: string, account: string|null, name: string|null, currency: string, iban?: string|null, columns: array<string, int|list<int>|null>|null}|null
     */
    private function pending(Request $request, CurrentOrg $org): ?array
    {
        /** @var array{org: string, path: string, account: string|null, name: string|null, currency: string, iban?: string|null, columns?: array<string, int|list<int>|null>|null}|null $pending */
        $pending = $request->session()->get('bank_import');

        return $pending !== null && $pending['org'] === $org->id() && Storage::disk('local')->exists($pending['path']) ? [...$pending, 'columns' => $pending['columns'] ?? null] : null;
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

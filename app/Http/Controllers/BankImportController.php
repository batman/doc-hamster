<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\CsvStatement;
use App\Domain\Banking\StatementImport;
use App\Domain\Org\CurrentOrg;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Bank statements from any bank (D-021): upload, choose the columns with a
 * look at the first rows, import. The file stays only until the import ran;
 * nothing but the transactions is kept.
 */
final class BankImportController
{
    private const FIELDS = ['date', 'amount', 'counterparty', 'purpose'];

    public function form(): View
    {
        return view('bank.import', ['accounts' => BankAccount::query()->withCount('transactions')->orderBy('name')->get()]);
    }

    public function upload(Request $request, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'account' => ['nullable', Rule::in(BankAccount::query()->pluck('id')->all())],
            'name' => ['required_without:account', 'nullable', 'string', 'max:150'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
        ]);

        $upload = $request->file('file');
        $content = $upload instanceof UploadedFile ? (string) file_get_contents($upload->getRealPath()) : '';

        // Excel files and other binaries contain NUL bytes, text never does.
        $statement = str_contains($content, "\0") ? null : CsvStatement::parse($content);
        if ($statement === null || $statement->header === [] || $statement->rows === []) {
            return back()->withInput()->withErrors(['file' => __('bank.wrong_type')]);
        }

        $this->forget($request);
        $this->clearOldFiles($org);

        $path = 'bank-imports/'.$org->id().'/'.Str::lower((string) Str::ulid()).'.csv';
        Storage::disk('local')->put($path, $content);

        $request->session()->put('bank_import', [
            'org' => $org->id(),
            'path' => $path,
            'account' => $data['account'] ?? null,
            'name' => $data['name'] ?? null,
            'currency' => Str::upper($data['currency'] ?? 'EUR'),
        ]);

        return redirect()->route('bank.import.columns');
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
            'accountName' => $account === null ? $pending['name'] : $account->name,
        ]);
    }

    public function run(Request $request, AuditLog $audit, CurrentOrg $org): RedirectResponse
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
        ]);
        $columns = [
            'date' => (int) $data['date'],
            'amount' => (int) $data['amount'],
            'counterparty' => isset($data['counterparty']) ? (int) $data['counterparty'] : null,
            'purpose' => isset($data['purpose']) ? (int) $data['purpose'] : null,
        ];

        $read = StatementImport::read($statement, $columns);
        if ($read['lines'] === []) {
            return back()->withErrors(['date' => __('bank.none_readable')]);
        }

        $account = $pending['account'] === null
            ? BankAccount::query()->create(['name' => $pending['name'], 'currency' => $pending['currency']])
            : BankAccount::query()->whereKey($pending['account'])->firstOrFail();

        // By name, not by position: a changed layout then falls back to the guess.
        $account->update(['import_profile' => array_map(
            static fn (int $index): string => $statement->header[$index],
            array_filter($columns, static fn (?int $index): bool => $index !== null),
        )]);

        $new = StatementImport::store($account, $read['lines']);
        $this->forget($request);

        $summary = ['new' => $new, 'known' => count($read['lines']) - $new, 'unreadable' => $read['unreadable']];
        $audit->record('bank.imported', orgId: $org->id(), userId: $org->membership()->user_id, subject: $account, meta: $summary, ip: $request->ip());

        return redirect()->route('bank.index')->with('status', __('bank.imported', $summary));
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
        return CsvStatement::parse((string) Storage::disk('local')->get($path));
    }

    /**
     * The upload of this session, for the active organisation only.
     *
     * @return array{org: string, path: string, account: string|null, name: string|null, currency: string}|null
     */
    private function pending(Request $request, CurrentOrg $org): ?array
    {
        /** @var array{org: string, path: string, account: string|null, name: string|null, currency: string}|null $pending */
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

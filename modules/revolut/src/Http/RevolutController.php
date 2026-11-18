<?php

declare(strict_types=1);

namespace DocHamsterModules\Revolut\Http;

use App\Domain\Banking\BankAccount;
use App\Domain\Documents\Document;
use App\Domain\Log\LogEntry;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use App\Modules\ModuleRuntime;
use DocHamsterModules\Revolut\RevolutJob;
use DocHamsterModules\Revolut\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View as ViewFactory;
use Throwable;

/**
 * Einstellungen → Module → Revolut Business (owner only): 1. make the signing key, 2. paste the API key (the accounts
 * come at once), 3. choose per Revolut account where its bookings go, 4. Probelauf (A98), then "Übernehmen" – from then
 * on it fetches every 15 minutes. Point 38: switch "Zahlungs-Entwürfe" on; a document's ⋯ menu then offers a draft.
 */
final class RevolutController
{
    public function show(): View
    {
        $this->owner();
        $settings = Settings::load();

        return self::view('settings', [
            'settings' => $settings,
            'accounts' => $settings->accounts(),
            'targets' => BankAccount::query()->orderBy('name')->get(['id', 'name', 'currency', 'source_key']),
            'probe' => session('m-revolut.probe'),
            'sodium' => function_exists('sodium_crypto_sign_keypair'),
        ]);
    }

    public function environment(Request $request): RedirectResponse
    {
        $this->owner();
        Settings::load()->save(['environment' => $request->input('environment') === 'sandbox' ? 'sandbox' : 'live', 'confirmed_at' => null]);

        return $this->back(__('m-revolut::texts.saved'));
    }

    public function keygen(): RedirectResponse
    {
        $this->owner();
        Settings::load()->makeSigningKey();

        return $this->back(__('m-revolut::texts.key_made'));
    }

    /** The API key from the Revolut API Console; the accounts are fetched right away (that tests it). */
    public function key(Request $request): RedirectResponse
    {
        $this->owner();
        $key = (string) $request->validate(['api_key' => ['required', 'string', 'min:20', 'max:4000']])['api_key'];
        Settings::load()->setApiKey($key);
        try {
            $accounts = RevolutJob::now('accounts');
        } catch (Throwable $e) {
            return $this->back(error: $e->getMessage());
        }

        return $this->back(trans_choice('m-revolut::texts.accounts_found', count((array) $accounts), ['count' => count((array) $accounts)]));
    }

    public function map(Request $request): RedirectResponse|JsonResponse
    {
        $this->owner();
        $mapping = array_filter((array) $request->input('mapping', []), 'is_string');
        Settings::load()->save(['mapping' => json_encode($mapping)]);

        // Saved at once on change (form[data-autosave] answers by JSON).
        return $request->wantsJson() ? response()->json(['message' => __('m-revolut::texts.saved')]) : $this->back(__('m-revolut::texts.saved'));
    }

    public function probe(Request $request): RedirectResponse
    {
        $this->owner();
        try {
            $request->session()->put('m-revolut.probe', RevolutJob::now('probe'));
        } catch (Throwable $e) {
            return $this->back(error: $e->getMessage());
        }

        return $this->back();
    }

    /** "Übernehmen": the Probelauf looked right – import now and from now on every 15 minutes. */
    public function accept(Request $request): RedirectResponse
    {
        $this->owner();
        Settings::load()->save(['confirmed_at' => now()]);
        $request->session()->forget('m-revolut.probe');

        return $this->sync();
    }

    public function sync(): RedirectResponse
    {
        $this->owner();
        try {
            $done = RevolutJob::now('sync');
        } catch (Throwable $e) {
            return $this->back(error: $e->getMessage());
        }

        return $this->back(__('m-revolut::texts.synced', is_array($done) ? $done : ['new' => 0, 'updated' => 0, 'suggested' => 0, 'receipts' => 0]));
    }

    public function drafts(Request $request): RedirectResponse
    {
        $this->owner();
        Settings::load()->save(['drafts' => $request->boolean('on')]);

        return $this->back(__('m-revolut::texts.saved'));
    }

    /** Point 38: the form of a payment draft, filled from the document's recognised values. */
    public function draftForm(string $id): View
    {
        $this->owner();
        $document = Document::query()->visibleTo((string) auth()->id(), true)->findOrFail($id);
        $settings = Settings::load();
        abort_unless($settings->drafts(), 404);
        $values = self::values($document);

        return self::view('draft', [
            'document' => $document,
            'accounts' => array_values(array_filter($settings->accounts(), static fn (array $a): bool => $a['state'] === 'active')),
            'values' => $values,
        ]);
    }

    public function draftCreate(Request $request, string $id): RedirectResponse
    {
        $this->owner();
        $document = Document::query()->visibleTo((string) auth()->id(), true)->findOrFail($id);
        abort_unless(Settings::load()->drafts(), 404);
        $data = $request->validate([
            'account_id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:80'],
            'iban' => ['required', 'string', 'max:40'],
            'bic' => ['nullable', 'string', 'max:11'],
            'amount' => ['required', 'string', 'max:20'],
            'currency' => ['required', 'string', 'size:3'],
            'reference' => ['required', 'string', 'max:140'],
        ]);
        $amount = (float) str_replace(',', '.', str_replace('.', '', $data['amount']));
        if ($amount <= 0) {
            return back()->withInput()->with('error', __('m-revolut::texts.draft_amount'));
        }
        try {
            RevolutJob::now('draft', [...$data, 'amount' => $amount, 'title' => 'docHamster: '.$document->title]);
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        LogEntry::write('general', 'event', (string) __('m-revolut::texts.draft_log', ['amount' => $data['amount'], 'currency' => $data['currency'], 'name' => $data['name']]),
            'module.revolut.draft', documentId: $document->id);

        return back()->with('status', __('m-revolut::texts.draft_done'));
    }

    /** @return array{name: string, iban: string, amount: string, currency: string, reference: string} */
    private static function values(Document $document): array
    {
        $keys = Property::query()->whereIn('key', ['sender', 'iban', 'invoice_amount', 'invoice_number', 'currency'])->pluck('key', 'id');
        $found = [];
        foreach (DocumentProperty::query()->where('document_id', $document->id)->whereIn('property_id', $keys->keys())->get() as $value) {
            $found[$keys[$value->property_id]] = $value->value_int ?? $value->value_text;
        }
        $amount = isset($found['invoice_amount']) && is_numeric($found['invoice_amount']) ? number_format(abs((int) $found['invoice_amount']) / 100, 2, ',', '.') : '';

        return ['name' => (string) ($found['sender'] ?? ''), 'iban' => (string) ($found['iban'] ?? ''), 'amount' => $amount,
            'currency' => strtoupper((string) ($found['currency'] ?? 'EUR')) ?: 'EUR', 'reference' => (string) ($found['invoice_number'] ?? $document->title)];
    }

    /**
     * A view of this module (m-revolut::), found where the module is installed.
     *
     * @param  array<string, mixed>  $data
     */
    private static function view(string $name, array $data): View
    {
        return ViewFactory::file(ViewFactory::getFinder()->find('m-revolut::'.$name), $data);
    }

    private function owner(): void
    {
        abort_unless(Gate::allows('modules.manage'), 403);
        ModuleRuntime::context('revolut');
    }

    private function back(?string $status = null, ?string $error = null): RedirectResponse
    {
        $to = redirect()->route('m.revolut.settings');

        return $error !== null ? $to->with('error', $error) : ($status !== null ? $to->with('status', $status) : $to);
    }
}

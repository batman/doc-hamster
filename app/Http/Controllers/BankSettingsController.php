<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Banking\MccTag;
use App\Domain\Banking\NoReceiptReason;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Einstellungen → Bank: the reasons for "kein Beleg nötig" (A286: add, rename, sort, switch off, delete – a reason in
 * use can only be switched off) and which card category (MCC) suggests which tag (A300).
 */
final class BankSettingsController
{
    public function reasons(): View
    {
        $reasons = NoReceiptReason::ordered();

        return view('bank.reasons', ['reasons' => $reasons, 'used' => $reasons->filter(static fn (NoReceiptReason $r): bool => $r->inUse())->pluck('id')->flip()]);
    }

    public function storeReason(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        NoReceiptReason::query()->create(['name' => trim($data['name']), 'sort' => (int) NoReceiptReason::query()->max('sort') + 1, 'active' => true]);

        return redirect()->route('bank.reasons')->with('status', __('bank.reason_added'));
    }

    /** Autosave of a row: the name or the switch. */
    public function updateReason(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $reason = NoReceiptReason::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:100'], 'active' => ['sometimes', 'boolean']]);
        $reason->update(array_filter(['name' => isset($data['name']) ? trim($data['name']) : null, 'active' => isset($data['active']) ? (bool) $data['active'] : null], static fn ($v): bool => $v !== null));

        return $request->wantsJson()
            ? response()->json(['message' => __('bank.reason_saved')])
            : redirect()->route('bank.reasons')->with('status', __('bank.reason_saved'));
    }

    public function destroyReason(string $id): RedirectResponse
    {
        $reason = NoReceiptReason::query()->whereKey($id)->firstOrFail();
        if ($reason->inUse()) {
            return redirect()->route('bank.reasons')->withErrors(['reason' => __('bank.reason_in_use')]);
        }
        $reason->delete();

        return redirect()->route('bank.reasons')->with('status', __('bank.reason_deleted'));
    }

    public function orderReasons(Request $request): JsonResponse
    {
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['string', Rule::in(NoReceiptReason::query()->pluck('id')->all())]]);
        foreach (array_values(array_unique($data['order'])) as $sort => $id) {
            NoReceiptReason::query()->whereKey($id)->update(['sort' => $sort]);
        }

        return response()->json(['ok' => true]);
    }

    public function mcc(): View
    {
        return view('bank.mcc', ['entries' => MccTag::ordered()]);
    }

    public function storeMcc(Request $request): RedirectResponse
    {
        $data = $request->validate(['mcc' => ['required', 'digits:4'], 'tag' => ['required', 'string', 'max:60']]);
        MccTag::query()->updateOrCreate(['mcc' => $data['mcc']], ['tag' => ltrim(trim($data['tag']), '#')]);

        return redirect()->route('bank.mcc')->with('status', __('bank.mcc_saved'));
    }

    public function destroyMcc(string $id): RedirectResponse
    {
        MccTag::query()->whereKey($id)->firstOrFail()->delete();

        return redirect()->route('bank.mcc')->with('status', __('bank.mcc_deleted'));
    }
}

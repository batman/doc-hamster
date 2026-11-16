<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Palette\PaletteColor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Einstellungen → Farben (point 15): the organisation's shared palette. Items
 * store the hex, so changing or deleting a colour leaves them as they are.
 */
final class PaletteController
{
    public function index(): View
    {
        return view('settings.colors', ['colors' => PaletteColor::query()->orderBy('position')->orderBy('id')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // Point 221: the new colour comes first, right below the "+" row it was added in.
        PaletteColor::query()->increment('position');
        PaletteColor::query()->create([...$data, 'position' => 0]);

        return redirect()->route('palette.index')->with('status', __('palette.saved'));
    }

    /** A row saves itself (FRAMEWORK.md "Properties module"): the circle on pick, the name on Enter or ✓. */
    public function update(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $color = PaletteColor::query()->whereKey($id)->firstOrFail();
        // PO 2026-11-16: the name (click-to-edit) and the colour (Farbkreis) save on their own – the other one stays.
        $request->mergeIfMissing(['name' => $color->name, 'hex' => $color->hex]);
        $color->update($this->validated($request));

        if ($request->wantsJson()) {
            return response()->json(['message' => __('palette.saved'), 'color' => ['id' => $color->id, 'name' => $color->name, 'hex' => $color->hex]]);
        }

        return redirect()->route('palette.index')->with('status', __('palette.saved'));
    }

    public function destroy(string $id): RedirectResponse
    {
        PaletteColor::query()->whereKey($id)->firstOrFail()->delete();

        return redirect()->route('palette.index')->with('status', __('palette.deleted'));
    }

    /** New order from the sortable list (framework, list:sorted). */
    public function order(Request $request): JsonResponse
    {
        $ids = PaletteColor::query()->pluck('id')->all();
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['string', Rule::in($ids)]]);

        foreach (array_values(array_unique($data['order'])) as $position => $id) {
            PaletteColor::query()->whereKey($id)->update(['position' => $position]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{name: string, hex: string}
     */
    private function validated(Request $request): array
    {
        $request->merge(['name' => trim((string) $request->input('name')), 'hex' => mb_strtolower(trim((string) $request->input('hex')))]);

        /** @var array{name: string, hex: string} */
        return $request->validate(['name' => ['required', 'string', 'max:60'], 'hex' => ['required', 'string', 'regex:'.PaletteColor::HEX]]);
    }
}

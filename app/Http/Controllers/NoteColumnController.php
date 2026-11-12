<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Notes\Note;
use App\Domain\Notes\NoteColumn;
use App\Domain\Org\Permission;
use App\Domain\Palette\PaletteColor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The columns of the notes board (Go of 2026-11-08, like Trello): any
 * number, named, in any order. They are the same for everyone, so changing
 * them needs the right "Notizen für alle zeigen" (A37).
 */
final class NoteColumnController
{
    /** "+" adds a column at once (point 94), named "Neue Spalte" unless a name is sent; the board starts its rename. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize();
        $data = $request->validate(['name' => ['nullable', 'string', 'max:60']]);
        $name = trim((string) ($data['name'] ?? ''));
        $column = NoteColumn::query()->create([
            'name' => $name !== '' ? $name : __('home.new_column'),
            'sort' => (int) NoteColumn::query()->max('sort') + 1,
        ]);

        return redirect()->route('home')->with('note_column_new', $column->id);
    }

    public function update(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $this->authorize();
        // Click-to-edit (<x-edit-in-place>, point 46) sends the text as "value".
        if (! $request->has('name')) {
            $request->merge(['name' => $request->input('value')]);
        }
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $name = trim($data['name']);
        NoteColumn::query()->findOrFail($id)->update(['name' => $name]);

        return $request->expectsJson() ? response()->json(['ok' => true, 'value' => $name]) : redirect()->route('home');
    }

    /** Columns dragged into a new order (A30). */
    public function order(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize();
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['string']]);
        foreach (array_values(array_filter($data['order'], 'is_string')) as $i => $id) {
            NoteColumn::query()->whereKey($id)->update(['sort' => $i]);
        }

        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect()->route('home');
    }

    /**
     * The look of a column from its ⋮ menu (points 66, A100, A101, A103): background colour, default colour of
     * new notes ("none" = no own one) and the order of its notes. Only the fields sent change.
     */
    public function look(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $this->authorize();
        $column = NoteColumn::query()->findOrFail($id);
        $colors = static fn (?string $current): array => array_values(array_filter([...PaletteColor::hexes(), 'none', $current]));
        $data = $request->validate([
            'color' => ['sometimes', 'string', Rule::in($colors($column->color))],
            'note_color' => ['sometimes', 'string', Rule::in($colors($column->note_color))],
            'sort' => ['sometimes', 'string', Rule::in(NoteColumn::SORTS)],
        ]);
        foreach (['color' => 'color', 'note_color' => 'note_color', 'sort' => 'note_sort'] as $field => $attribute) {
            if (array_key_exists($field, $data)) {
                $column->{$attribute} = $data[$field] === 'none' ? null : $data[$field];
            }
        }
        $column->save();

        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect()->route('home');
    }

    /** Its notes go to the end of the first other column; the last column stays (A38). */
    public function destroy(string $id): RedirectResponse
    {
        $this->authorize();
        $column = NoteColumn::query()->findOrFail($id);
        $target = NoteColumn::board()->firstWhere(static fn (NoteColumn $other): bool => $other->id !== $column->id);
        abort_if($target === null, 422);

        $sort = (int) Note::query()->where('column_id', $target->id)->max('sort');
        foreach (Note::query()->where('column_id', $column->id)->orderBy('sort')->pluck('id') as $noteId) {
            Note::query()->whereKey($noteId)->update(['column_id' => $target->id, 'sort' => ++$sort]);
        }
        $column->delete();

        return redirect()->route('home');
    }

    private function authorize(): void
    {
        abort_unless(Gate::allows(Permission::ShareNotes->value), 403);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Notes\Note;
use App\Domain\Notes\NoteColumn;
use App\Domain\Org\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The columns of the notes board (Go of 2026-11-08, like Trello): any
 * number, named, in any order. They are the same for everyone, so changing
 * them needs the right "Notizen für alle zeigen" (A37).
 */
final class NoteColumnController
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize();
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        NoteColumn::query()->create(['name' => trim($data['name']), 'sort' => (int) NoteColumn::query()->max('sort') + 1]);

        return redirect()->route('home');
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

    /** Its notes, archived ones too, go to the end of the first other column; the last column stays (A38). */
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

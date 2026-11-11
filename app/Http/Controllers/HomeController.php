<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Documents\Tag;
use App\Domain\Notes\Note;
use App\Domain\Notes\NoteColumn;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Palette\PaletteColor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Home: sticky notes (collected 2026-11-03) on a board like Trello (Go of
 * 2026-11-08): columns, notes dragged between and within them, an archive,
 * tags. Text with inline lists ("[Milch, #Brot]", set and drawn by app.js)
 * and links to receipts. A note is private; with the right "Notizen für
 * alle zeigen" its author shows it to everyone and decides whether others
 * may change it.
 */
final class HomeController
{
    public function index(Request $request, CurrentOrg $org): View
    {
        $userId = $org->membership()->user_id;
        $columns = NoteColumn::board();
        $tags = Tag::query()->orderBy('name')->get();
        $filter = $tags->firstWhere('id', $request->query('tag'));

        $notes = $this->notes($userId)->whereNull('archived_at')
            ->when($filter !== null, static fn (Builder $query) => $query->whereHas('tags', static fn (Builder $tag) => $tag->whereKey($filter?->id)))
            ->orderBy('sort')->orderBy('created_at')->get();
        // A note without a column (or whose column is gone) lies in the first one.
        $first = (string) $columns->first()?->id;
        $byColumn = $notes->groupBy(static fn (Note $note): string => $columns->contains('id', $note->column_id) ? (string) $note->column_id : $first);

        return view('home.index', [
            'columns' => $columns,
            'byColumn' => $byColumn,
            'notes' => $notes,
            'tags' => $tags,
            'filter' => $filter,
            'userId' => $userId,
            'canShare' => Gate::allows(Permission::ShareNotes->value),
            'documents' => $this->visibleDocuments()->latest()->limit(500)->get(['id', 'title']),
        ]);
    }

    /** Archived notes (A41): back to the board or deleted for good. */
    public function archive(CurrentOrg $org): View
    {
        $userId = $org->membership()->user_id;

        return view('home.archive', [
            'notes' => $this->notes($userId)->whereNotNull('archived_at')->latest('archived_at')->get(),
            'userId' => $userId,
        ]);
    }

    /**
     * Drag & drop: the note goes into a column; `order` is that column's
     * notes as the person sees them, top to bottom (A32).
     */
    public function move(Request $request, string $id, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $note = $this->editable($id, $org, unlocked: true);
        $data = $request->validate([
            'column' => ['required', 'string', Rule::in(NoteColumn::query()->pluck('id')->all())],
            'order' => ['array'],
            'order.*' => ['string'],
        ]);
        $order = array_values(array_unique(array_filter((array) ($data['order'] ?? []), 'is_string')));
        if (! in_array($note->id, $order, true)) {
            $order[] = $note->id;
        }
        $note->update(['column_id' => $data['column']]);

        $visible = $this->notes($org->membership()->user_id)->whereKey($order)->pluck('id')->all();
        foreach (array_values(array_intersect($order, $visible)) as $i => $noteId) {
            Note::query()->whereKey($noteId)->update(['column_id' => $data['column'], 'sort' => $i]);
        }

        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect()->route('home');
    }

    public function archiveNote(string $id, CurrentOrg $org): RedirectResponse
    {
        $this->editable($id, $org, unlocked: true)->forceFill(['archived_at' => now()])->save();

        return redirect()->route('home');
    }

    /** Back from the archive: at the end of its column. */
    public function restore(string $id, CurrentOrg $org): RedirectResponse
    {
        $note = $this->editable($id, $org);
        $note->forceFill(['archived_at' => null, 'sort' => $this->endOf($note->column_id)])->save();

        return redirect()->route('notes.archive');
    }

    public function store(Request $request, CurrentOrg $org): RedirectResponse
    {
        $note = new Note(['user_id' => $org->membership()->user_id]);
        $this->fill($request, $note);

        return redirect()->route('home');
    }

    public function update(Request $request, string $id, CurrentOrg $org): RedirectResponse
    {
        $note = $this->editable($id, $org);
        // A locked note only changes its lock; its fields come back once it is unlocked (A13).
        if ($note->locked && ($request->boolean('locked') || ! $request->exists('title'))) {
            $note->update(['locked' => $request->boolean('locked')]);

            return redirect()->route('home');
        }
        $this->fill($request, $note);

        return redirect()->route('home');
    }

    /**
     * The look of a note: place and size on the board (dragged), colour,
     * opacity, lock. Only the fields sent change. A locked note only unlocks.
     */
    public function look(Request $request, string $id, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $note = $this->editable($id, $org);
        $data = $request->validate([
            'x' => ['sometimes', 'integer', 'min:0', 'max:20000'],
            'y' => ['sometimes', 'integer', 'min:0', 'max:20000'],
            'w' => ['sometimes', 'integer', 'min:160', 'max:4000'],
            'h' => ['sometimes', 'integer', 'min:120', 'max:4000'],
            'color' => ['sometimes', 'string', Rule::in($this->colors($note))],
            'opacity' => ['sometimes', 'integer', 'min:30', 'max:100'],
            'locked' => ['sometimes', 'boolean'],
        ]);
        abort_if($note->locked && array_diff(array_keys($data), ['locked']) !== [], 423);

        $note->fill(array_filter([
            'pos_x' => $data['x'] ?? null,
            'pos_y' => $data['y'] ?? null,
            'width' => $data['w'] ?? null,
            'height' => $data['h'] ?? null,
            'color' => $data['color'] ?? null,
            'opacity' => $data['opacity'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));
        if (array_key_exists('locked', $data)) {
            $note->locked = $request->boolean('locked');
        }
        $note->save();

        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect()->route('home');
    }

    /**
     * The text, written right on the note. Lists ("[a, #b]") and their ticks
     * are part of the text; app.js sets and draws them.
     */
    public function text(Request $request, string $id, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $note = $this->editable($id, $org, unlocked: true);
        $data = $request->validate(['body' => ['nullable', 'string', 'max:5000']]);
        $note->update(['body' => ($data['body'] ?? '') === '' ? null : $data['body']]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect()->route('home');
    }

    public function destroy(string $id, CurrentOrg $org): RedirectResponse
    {
        $note = $this->editable($id, $org, unlocked: true);
        $archived = $note->archived_at !== null;
        $note->delete();

        return redirect()->route($archived ? 'notes.archive' : 'home');
    }

    /**
     * A note takes any colour of the shared palette, and keeps its own one even if that left the palette.
     *
     * @return list<string>
     */
    private function colors(Note $note): array
    {
        return array_values(array_filter([...PaletteColor::hexes(), $note->color]));
    }

    private function fill(Request $request, Note $note): void
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:100'],
            'body' => ['nullable', 'string', 'max:5000'],
            'link' => ['nullable', 'string', 'max:250'],
            'unlink' => ['array'],
            'color' => ['nullable', 'string', Rule::in($this->colors($note))],
            'opacity' => ['nullable', 'integer', 'min:30', 'max:100'],
            'column' => ['nullable', 'string', Rule::in(NoteColumn::query()->pluck('id')->all())],
            'tags' => ['array'],
            'tags.*' => ['string', Rule::in(Tag::query()->pluck('id')->all())],
        ]);
        $isAuthor = $note->user_id === $request->user()?->getAuthIdentifier();

        // The edit dialog has no text (it is changed on the note itself), so the text only changes when sent.
        if ($request->exists('body')) {
            $note->body = $data['body'] ?? null;
        }
        $note->fill([
            'title' => $data['title'] ?? null,
            'color' => $data['color'] ?? $note->color, 'opacity' => $data['opacity'] ?? $note->opacity, 'locked' => $request->boolean('locked'),
        ]);
        // A new note goes to the end of its column; the column field (phones, A36) moves it to the end of another.
        $column = $data['column'] ?? null;
        if (! $note->exists || ($column !== null && $column !== $note->column_id)) {
            $column ??= NoteColumn::board()->first()?->id;
            $note->fill(['column_id' => $column, 'sort' => $this->endOf($column)]);
        }
        // Only the author decides who sees and changes it, and only with the right.
        if ($isAuthor && Gate::allows(Permission::ShareNotes->value)) {
            $note->fill(['shared' => $request->boolean('shared'), 'editable' => $request->boolean('shared') && $request->boolean('editable')]);
        }
        $note->save();
        // The dialogs always send the title, so a missing title means: tags not part of this request.
        if ($request->exists('title')) {
            $note->tags()->sync(array_values(array_filter((array) ($data['tags'] ?? []), 'is_string')));
        }

        $unlink = array_values(array_filter((array) ($data['unlink'] ?? []), 'is_string'));
        if ($unlink !== []) {
            $note->documents()->detach($this->visibleDocuments()->whereKey($unlink)->pluck('id')->all());
        }
        // A receipt is linked by its name, picked from the suggestions (A7); only receipts the person may see.
        if (($data['link'] ?? '') !== '') {
            $document = $this->visibleDocuments()->where('title', $data['link'])->first();
            if ($document !== null) {
                $note->documents()->syncWithoutDetaching([$document->id]);
            }
        }
    }

    /**
     * The notes a person sees, with what the board shows of them. A shared
     * note shows only the receipts the reader may see (#56).
     *
     * @return Builder<Note>
     */
    private function notes(string $userId): Builder
    {
        return Note::query()->visibleTo($userId)->with([
            'author', 'tags',
            'documents' => fn (BelongsToMany $documents) => $documents->whereIn('documents.id', $this->visibleDocuments()->select('documents.id')),
        ]);
    }

    private function endOf(?string $column): int
    {
        return (int) Note::query()->where('column_id', $column)->max('sort') + 1;
    }

    private function editable(string $id, CurrentOrg $org, bool $unlocked = false): Note
    {
        $userId = $org->membership()->user_id;
        $note = Note::query()->visibleTo($userId)->whereKey($id)->firstOrFail();
        abort_unless($note->editableBy($userId), 403);
        abort_if($unlocked && $note->locked, 423);

        return $note;
    }

    /**
     * @return Builder<Document>
     */
    private function visibleDocuments(): Builder
    {
        return Document::query()->visibleTo((string) auth()->id(), Gate::allows(Permission::ViewAllDocuments->value));
    }
}

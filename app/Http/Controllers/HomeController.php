<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\Device;
use App\Domain\Documents\Document;
use App\Domain\Notes\Note;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Org\Role;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Home: sticky notes (collected 2026-11-03). Text with inline lists
 * ("[Milch, #Brot]", set and drawn by app.js) and links to receipts. A note is private; with the right "Notizen für alle zeigen" its
 * author shows it to everyone and decides whether others may change it.
 */
final class HomeController
{
    public function index(CurrentOrg $org): View
    {
        $userId = $org->membership()->user_id;

        return view('home.index', [
            // A shared note shows only the receipts the reader may see (#56).
            'notes' => Note::query()->visibleTo($userId)->with(['author', 'documents' => fn (BelongsToMany $documents) => $documents->whereIn('documents.id', $this->visibleDocuments()->select('documents.id'))])->latest()->get(),
            'userId' => $userId,
            'canShare' => Gate::allows(Permission::ShareNotes->value),
            'documents' => $this->visibleDocuments()->latest()->limit(500)->get(['id', 'title']),
            // First steps: until a phone is connected, home points to "Handy verbinden" (PLAN §11.1).
            'connectPhone' => $org->membership()->role !== Role::Accountant
                && ! Device::query()->where('user_id', $userId)->whereNull('revoked_at')->exists(),
        ]);
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
            'color' => ['sometimes', Rule::in(Note::COLORS)],
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
        $this->editable($id, $org, unlocked: true)->delete();

        return redirect()->route('home');
    }

    private function fill(Request $request, Note $note): void
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:100'],
            'body' => ['nullable', 'string', 'max:5000'],
            'link' => ['nullable', 'string', 'max:250'],
            'unlink' => ['array'],
            'color' => ['nullable', Rule::in(Note::COLORS)],
            'opacity' => ['nullable', 'integer', 'min:30', 'max:100'],
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
        // Only the author decides who sees and changes it, and only with the right.
        if ($isAuthor && Gate::allows(Permission::ShareNotes->value)) {
            $note->fill(['shared' => $request->boolean('shared'), 'editable' => $request->boolean('shared') && $request->boolean('editable')]);
        }
        $note->save();

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

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Notes\Note;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Home: sticky notes (collected 2026-11-03). Text, a checklist and links to
 * receipts. A note is private; with the right "Notizen für alle zeigen" its
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
        $this->fill($request, $note);

        return redirect()->route('home');
    }

    /** Ticks from the checklist; whoever may change the note may tick (A8). */
    public function check(Request $request, string $id, CurrentOrg $org): RedirectResponse
    {
        $note = $this->editable($id, $org);
        $done = array_map('intval', (array) $request->input('done', []));

        $note->update(['checklist' => array_map(
            static fn (array $item, int $i): array => ['text' => $item['text'], 'done' => in_array($i, $done, true)],
            $note->checklist ?? [],
            array_keys($note->checklist ?? []),
        )]);

        return redirect()->route('home');
    }

    public function destroy(string $id, CurrentOrg $org): RedirectResponse
    {
        $this->editable($id, $org)->delete();

        return redirect()->route('home');
    }

    private function fill(Request $request, Note $note): void
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'checklist' => ['nullable', 'string', 'max:5000'],
            'link' => ['nullable', 'string', 'max:250'],
            'unlink' => ['array'],
        ]);
        $isAuthor = $note->user_id === $request->user()?->getAuthIdentifier();

        // One line per item; "[x] " in front marks it done.
        $lines = array_filter(array_map('trim', preg_split('/\R/', (string) ($data['checklist'] ?? '')) ?: []), static fn (string $line): bool => $line !== '');
        $checklist = array_values(array_map(static fn (string $line): array => [
            'text' => (string) preg_replace('/^\[[xX ]?\]\s*/', '', $line),
            'done' => preg_match('/^\[[xX]\]/', $line) === 1,
        ], $lines));

        $note->fill(['body' => $data['body'] ?? null, 'checklist' => $checklist]);
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

    private function editable(string $id, CurrentOrg $org): Note
    {
        $userId = $org->membership()->user_id;
        $note = Note::query()->visibleTo($userId)->whereKey($id)->firstOrFail();
        abort_unless($note->editableBy($userId), 403);

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

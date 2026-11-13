<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Http\Controllers\Concerns\ScopesDocuments;
use App\Http\Documents\DocumentListing;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Receipts: the documents windows (list with one receipt opened beside it), editing, trash and restore.
 * The listing is built by DocumentListing; view switch, upload and file delivery have their own controllers.
 */
final class DocumentController
{
    use ScopesDocuments;

    public function index(Request $request): View
    {
        return view('documents.index', $this->listing($request));
    }

    /** The list with one receipt opened beside it (design point 17), with its own address. */
    public function show(Request $request, string $id): View
    {
        $open = $this->visible($request)->with(['files', 'tags', 'matches.transaction'])->whereKey($id)->firstOrFail();

        return view('documents.index', [...$this->listing($request), 'open' => $open]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listing(Request $request): array
    {
        return app(DocumentListing::class)->build($request, $this->user($request));
    }

    public function updateTags(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate(self::tagRules());
        $userId = $this->userId($request);

        $document = $this->visible($request)
            ->whereKey($id)
            ->firstOrFail();

        $document->tags()->sync($data['tags'] ?? []);
        $audit->record('document.tags_changed', orgId: $org->id(), userId: $userId, subject: $document, ip: $request->ip());

        return back()->with('status', __('tags.changed'));
    }

    /** Title, note and tags from the receipt view. */
    public function update(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:250'],
            'note' => ['nullable', 'string', 'max:5000'],
            ...self::tagRules(),
            ...self::folderRule(),
        ]);
        $document = $this->visible($request)->whereKey($id)->firstOrFail();

        // The folder changes only when the form has the field, so other forms never clear it.
        // The note is edited in place now (click-to-edit), so the form only changes it when it sends it.
        $document->update([
            ...($request->has('note') ? ['note' => $data['note'] ?? null] : []),
            ...(isset($data['title']) ? ['title' => $data['title']] : []),
            ...($request->has('folder') ? ['folder_id' => $data['folder'] ?? Folder::defaultIdFor($org->id())] : []),
        ]);
        $document->tags()->sync($data['tags'] ?? []);
        $audit->record('document.changed', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());

        return back()->with('status', __('documents.changed'));
    }

    /**
     * One text field edited in place (click-to-edit, points 27/28): the note or the recognised text.
     * Answers with JSON, the page stays as it is.
     */
    public function updateField(Request $request, string $id, AuditLog $audit, CurrentOrg $org): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'string', Rule::in(['note', 'ocr_text', 'title'])],
            'value' => [...match ($request->input('field')) {
                'note' => ['nullable', 'max:5000'],
                // Renamed in its row (pencil, A174): a document always keeps a name.
                'title' => ['required', 'max:250'],
                default => ['nullable', 'max:1000000'],
            }, 'string'],
        ]);
        $document = $this->visible($request)->whereKey($id)->firstOrFail();
        $value = trim((string) ($data['value'] ?? ''));
        if ($data['field'] === 'title' && $value === '') {
            return response()->json(['message' => __('validation.required', ['attribute' => __('documents.name')])], 422);
        }

        $document->forceFill([$data['field'] => $value !== '' ? $value : null])->save();
        $audit->record('document.changed', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());

        return response()->json(['value' => $value, 'message' => __('documents.changed')]);
    }

    /** "In Ordner verschieben" from the ⋯ menu of the receipt window (point 26): only the folder changes. */
    public function move(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $data = $request->validate(['folder' => ['required', ...self::folderRule()['folder']]]);
        $document = $this->visible($request)->whereKey($id)->firstOrFail();

        $document->update(['folder_id' => $data['folder']]);
        $audit->record('document.changed', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());

        return back()->with('status', __('documents.moved'));
    }

    /** Into the trash: the receipt leaves every list, the file stays (#30, "Belege löschen"). */
    public function destroy(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $document = $this->visible($request)->whereKey($id)->firstOrFail();
        $document->delete();
        $audit->record('document.trashed', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());

        return redirect()->route('documents.index')->with('status', __('documents.deleted'));
    }

    /**
     * The trash (#30 "Belege löschen"): receipts deleted by mistake come back
     * from here; something from the print tray goes back there.
     */
    public function trash(Request $request): View
    {
        return view('documents.trash', [
            'documents' => $this->visible($request)->withPrintTray()->onlyTrashed()->with('files')->latest('deleted_at')->limit(200)->get(),
            'count' => $this->visible($request)->withPrintTray()->onlyTrashed()->count(),
            'name' => Folder::trashName($this->organization()),
        ]);
    }

    public function restore(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse|JsonResponse
    {
        $document = $this->visible($request)->withPrintTray()->onlyTrashed()->whereKey($id)->firstOrFail();
        $document->restore();
        $audit->record('document.restored', orgId: $org->id(), userId: $this->userId($request), subject: $document, ip: $request->ip());
        if ($request->wantsJson()) {
            return response()->json(['message' => __('documents.restored')]);
        }

        return redirect()->route('documents.trash')->with('status', __('documents.restored'));
    }
}

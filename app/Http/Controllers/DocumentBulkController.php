<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Drag and drop of documents (points 75-77, A131-A137): several at once onto a
 * folder row (move, or restore when they come from the Papierkorb), onto the
 * Papierkorb row (trash), and the Papierkorb's own "delete for good" and
 * "Papierkorb leeren". Answers JSON for the page's toast with "Rückgängig";
 * the two permanent ones also work as plain forms.
 */
final class DocumentBulkController
{
    private const MAX = 500;

    /**
     * Into a folder. Documents from the Papierkorb come back into it (A135) – only for people who may use the Papierkorb.
     * The answer lists where each one was, so the toast's "Rückgängig" can put them back.
     */
    public function move(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse
    {
        $data = $request->validate([
            ...$this->idRules(),
            'folder' => ['required', 'string', Rule::in(Folder::query()->pluck('id')->all())],
        ]);
        $mayTrash = Gate::allows(Permission::TrashDocuments->value);
        $documents = $this->visible($request, $mayTrash)->whereKey($data['ids'])->get();
        $userId = $this->user($request)->id;

        $moves = [];
        $restored = 0;
        DB::transaction(function () use ($documents, $data, $audit, $org, $userId, $request, &$moves, &$restored): void {
            foreach ($documents as $document) {
                $moves[] = ['id' => $document->id, 'folder' => $document->folder_id, 'trashed' => $document->trashed()];
                if ($document->trashed()) {
                    $document->restore();
                    $restored++;
                    $audit->record('document.restored', orgId: $org->id(), userId: $userId, subject: $document, ip: $request->ip());
                }
                $document->update(['folder_id' => $data['folder']]);
                $audit->record('document.moved', orgId: $org->id(), userId: $userId, subject: $document, meta: ['to' => $data['folder']], ip: $request->ip());
            }
        });

        $name = (string) Folder::query()->whereKey($data['folder'])->value('name');
        $count = count($moves);
        $message = $restored > 0 && $restored === $count
            ? trans_choice('documents.bulk.restored_into', $count, ['count' => $count, 'name' => $name])
            : trans_choice('documents.bulk.moved', $count, ['count' => $count, 'name' => $name]);

        return response()->json(['message' => $message, 'moves' => $moves]);
    }

    /** Onto the Papierkorb row (point 76): into the trash, nothing is lost yet. */
    public function trash(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse
    {
        $data = $request->validate($this->idRules());
        $documents = $this->visible($request, false)->whereKey($data['ids'])->get();
        $userId = $this->user($request)->id;
        foreach ($documents as $document) {
            $document->delete();
            $audit->record('document.trashed', orgId: $org->id(), userId: $userId, subject: $document, ip: $request->ip());
        }
        $count = $documents->count();

        return response()->json([
            'message' => trans_choice('documents.bulk.trashed', $count, ['count' => $count, 'name' => Folder::trashName($org->organization())]),
            'ids' => $documents->modelKeys(),
        ]);
    }

    /** Back out of the Papierkorb into the folder each one was in ("Rückgängig" of a trash drop, the restore button). */
    public function restore(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse
    {
        $data = $request->validate($this->idRules());
        $documents = $this->visible($request, true)->onlyTrashed()->whereKey($data['ids'])->get();
        $userId = $this->user($request)->id;
        foreach ($documents as $document) {
            $document->restore();
            $audit->record('document.restored', orgId: $org->id(), userId: $userId, subject: $document, ip: $request->ip());
        }
        $count = $documents->count();

        return response()->json(['message' => trans_choice('documents.bulk.restored', $count, ['count' => $count])]);
    }

    /** Deleting inside the Papierkorb (point 77, A137): gone for good, files included; asked first in a dialog. */
    public function purge(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate($this->idRules());
        $count = $this->destroy($this->visible($request, true)->onlyTrashed()->whereKey($data['ids'])->with('files')->get(), $request, $audit, $org);

        return $this->answer($request, trans_choice('documents.bulk.purged', $count, ['count' => $count]));
    }

    /** "Papierkorb leeren" (A136): everything in the Papierkorb this person may see, gone for good. */
    public function empty(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $count = $this->destroy($this->visible($request, true)->onlyTrashed()->with('files')->get(), $request, $audit, $org);

        return $this->answer($request, trans_choice('documents.bulk.purged', $count, ['count' => $count]));
    }

    /**
     * @param  Collection<int, Document>  $documents
     */
    private function destroy(Collection $documents, Request $request, AuditLog $audit, CurrentOrg $org): int
    {
        $userId = $this->user($request)->id;
        $disk = Storage::disk('local');
        foreach ($documents as $document) {
            $paths = $document->files->flatMap(static fn (DocumentFile $file): array => array_filter([$file->path, $file->thumb_path]))->all();
            // Logged before it goes: the audit keeps the id and title, the rows cascade away with the document.
            $audit->record('document.purged', orgId: $org->id(), userId: $userId, subject: $document, meta: ['title' => $document->title, 'files' => count($paths)], ip: $request->ip());
            $document->forceDelete();
            $disk->delete($paths);
        }

        return $documents->count();
    }

    private function answer(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : redirect()->route('documents.trash')->with('status', $message);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function idRules(): array
    {
        return ['ids' => ['required', 'array', 'max:'.self::MAX], 'ids.*' => ['string', 'max:30']];
    }

    /**
     * Documents this person may see (PLAN §2.1), from the print tray too; with the Papierkorb's ones when allowed.
     *
     * @return Builder<Document>
     */
    private function visible(Request $request, bool $withTrashed): Builder
    {
        $query = Document::query()->withPrintTray()->visibleTo($this->user($request)->id, Gate::allows(Permission::ViewAllDocuments->value));

        return $withTrashed ? $query->withTrashed() : $query;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}

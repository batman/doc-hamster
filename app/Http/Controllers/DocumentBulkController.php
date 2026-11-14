<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentPurger;
use App\Domain\Documents\Folder;
use App\Domain\Documents\TrashSettings;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
     * The answer lists where each one was, so the toast's "Rückgängig" can put them back. An empty folder takes them
     * back into the Posteingang (the undo of filing, A358, A361); "filed" (Posteingang → "Ablegen in …") says
     * "Abgelegt in „X“".
     */
    public function move(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse
    {
        $data = $request->validate([
            ...$this->idRules(),
            'folder' => ['present', 'nullable', 'string', Rule::in(Folder::query()->pluck('id')->all())],
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

        $folder = $data['folder'] ?? null;
        $name = $folder !== null ? (string) Folder::query()->whereKey($folder)->value('name') : __('inbox.title');
        $count = count($moves);
        $message = match (true) {
            $restored > 0 && $restored === $count => trans_choice('documents.bulk.restored_into', $count, ['count' => $count, 'name' => $name]),
            $folder === null => trans_choice('inbox.unfiled', $count, ['count' => $count]),
            $request->boolean('filed') => trans_choice('inbox.filed', $count, ['count' => $count, 'name' => $name]),
            default => trans_choice('documents.bulk.moved', $count, ['count' => $count, 'name' => $name]),
        };

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
    public function purge(Request $request, DocumentPurger $purger): JsonResponse|RedirectResponse
    {
        $data = $request->validate($this->idRules());
        $documents = $this->visible($request, true)->onlyTrashed()->whereKey($data['ids'])->get();
        $count = $purger->purge($documents, $this->user($request)->id, $request->ip());

        return $this->answer($request, trans_choice('documents.bulk.purged', $count, ['count' => $count]));
    }

    /**
     * "Papierkorb leeren" (A136, point 207): everything in the Papierkorb this person may see leaves it at once, but is
     * only deleted for good after the undo period – until then "Leerung rückgängig machen" brings it back.
     */
    public function empty(Request $request, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $days = TrashSettings::of($org->organization())['undo'];
        $until = Carbon::now()->addDays($days)->startOfSecond();
        $count = $this->visible($request, true)->onlyTrashed()->update(['purge_after' => $until]);
        $audit->record('document.trash_emptied', orgId: $org->id(), userId: $this->user($request)->id, meta: ['count' => $count, 'until' => $until->toDateTimeString()], ip: $request->ip());
        $message = trans_choice('documents.bulk.emptied', $count, ['count' => $count, 'days' => $days]);

        return $request->wantsJson()
            ? response()->json([
                'message' => $message,
                'undo' => route('documents.trash.unempty'),
                'batch' => $until->toDateTimeString(),
                'undoLabel' => __('documents.bulk.unempty_short'),
            ])
            : redirect()->route('documents.trash')->with('status', $message);
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

        // Emptied ones (point 207) are out of reach here: only "Leerung rückgängig machen" brings them back.
        return $withTrashed ? $query->withTrashed()->whereNull('purge_after') : $query;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}

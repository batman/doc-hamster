<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Folders for receipts (issue #77): create, rename, move, colour, symbol,
 * order, delete. Anyone who may edit receipts may manage folders. Deleting a
 * folder moves its receipts and subfolders up one level; "Mit Inhalt löschen"
 * also deletes the subfolders and puts the receipts into the Papierkorb.
 * "Belege allgemein" keeps its name and place (PO, 2026-11-07).
 */
final class FolderController
{
    /**
     * Folders are managed where they are shown, in the Dokumente window (points 10-12). This page only
     * gives the windows opened from there: `?folder=` its properties, `?new=1` / `?parent=` a new one.
     */
    public function index(Request $request, CurrentOrg $org): View|RedirectResponse
    {
        $rows = Folder::flat();
        $wanted = $request->query('folder');
        $current = collect($rows)->first(static fn (array $row): bool => $row['folder']->id === $wanted);
        if ($current === null && ! $request->filled('new') && ! $request->filled('parent')) {
            return redirect()->route('documents.index');
        }

        return view('folders.index', [
            'rows' => $rows,
            'folder' => $current['folder'] ?? null,
            // "+" in the menu or in a folder: the window for a new folder (or subfolder) opens right of the list.
            'creating' => $current === null && ($request->filled('new') || $request->filled('parent')),
            // Only receipts this person may see (PLAN §2.1), so counts don't reveal others' uploads.
            'counts' => Document::query()->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value))->whereNotNull('folder_id')->selectRaw('folder_id, count(*) as total')->groupBy('folder_id')->pluck('total', 'folder_id'),
        ]);
    }

    public function store(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $folder = Folder::query()->create(['org_id' => $org->id(), 'name' => $data['name'], 'parent_id' => $data['parent_id'], 'color' => $data['color'], 'icon' => $data['icon'], 'bank_matching' => $data['bank_matching']]);
        $this->log($audit, $request, $org, 'folder.created', $folder);

        // Back to where it was made: the window of its parent folder (or the top level).
        return redirect()->route('documents.index', array_filter(['folder' => $folder->parent_id]))->with('status', __('folders.saved'));
    }

    public function update(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $folder = Folder::query()->whereKey($id)->firstOrFail();
        $data = $this->validated($request, $folder);

        // "Dokumente allgemein" may be renamed (A18) but stays on the top level.
        $folder->update([
            'name' => $data['name'],
            'parent_id' => $folder->is_default ? null : $data['parent_id'],
            'color' => $request->has('look') ? $data['color'] : $folder->color,
            'icon' => $request->has('look') ? $data['icon'] : $folder->icon,
            'bank_matching' => $data['bank_matching'],
        ]);
        $this->log($audit, $request, $org, 'folder.changed', $folder);

        // Back to the window it was changed in (a row of the Dokumente window or its properties window).
        return back()->with('status', __('folders.saved'));
    }

    /**
     * Drag and drop: the new order of one level. At the top level the token
     * "trash" stands for the Papierkorb, whose place is kept in the settings.
     */
    public function order(Request $request, CurrentOrg $org): JsonResponse
    {
        $parent = $request->filled('parent_id') ? $request->input('parent_id') : null;
        $data = $request->validate(['order' => ['required', 'array', 'max:500'], 'order.*' => ['string', 'max:30']]);
        /** @var list<string> $order */
        $order = array_values(array_unique($data['order']));

        $siblings = Folder::query()->where('parent_id', is_string($parent) ? $parent : null)->pluck('id')->all();
        $ids = array_values(array_filter($order, static fn (string $id): bool => $id !== 'trash'));
        if (array_diff($ids, $siblings) !== [] || ($parent !== null && in_array('trash', $order, true))) {
            return response()->json(['message' => __('folders.order_invalid')], 422);
        }

        DB::transaction(static function () use ($ids): void {
            foreach ($ids as $position => $id) {
                Folder::query()->whereKey($id)->update(['position' => $position]);
            }
        });

        $slot = array_search('trash', $order, true);
        if ($parent === null && is_int($slot)) {
            $organization = $org->organization();
            $settings = $organization->settings_json ?? [];
            data_set($settings, Folder::TRASH_SLOT, $slot);
            $organization->update(['settings_json' => $settings]);
        }

        return response()->json(['message' => __('folders.order_saved')]);
    }

    public function destroy(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $folder = Folder::query()->whereKey($id)->firstOrFail();

        if ($folder->is_default) {
            return back()->withErrors(['folder' => __('folders.default_locked')]);
        }

        // Receipts move up; from a top folder they go to "Belege", so none is ever left without a folder.
        $target = $folder->parent_id ?? Folder::defaultIdFor($org->id());

        if ($request->boolean('with_content')) {
            return $this->destroyWithContent($request, $folder, $target, $org, $audit);
        }

        DB::transaction(static function () use ($folder, $target): void {
            Document::query()->withPrintTray()->withTrashed()->where('folder_id', $folder->id)->update(['folder_id' => $target]);
            Folder::query()->where('parent_id', $folder->id)->update(['parent_id' => $folder->parent_id]);
            $folder->delete();
        });
        $this->log($audit, $request, $org, 'folder.deleted', $folder);

        return redirect()->route('documents.index', array_filter(['folder' => $folder->parent_id]))->with('status', __('folders.deleted'));
    }

    /**
     * Deletes the folder with everything in it (PO, 2026-11-07): its subfolders
     * go, the receipts this person may see go into the Papierkorb. Nothing is
     * lost for good: a receipt brought back from the Papierkorb, and any receipt
     * this person may not see, lands one level above the deleted folder.
     */
    private function destroyWithContent(Request $request, Folder $folder, string $target, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        if (! Gate::allows(Permission::TrashDocuments->value)) {
            abort(403);
        }

        $branch = Folder::branchIds($folder->id);
        $userId = $org->membership()->user_id;
        $trashed = Document::query()->visibleTo($userId, Gate::allows(Permission::ViewAllDocuments->value))->whereIn('folder_id', $branch)->get();

        DB::transaction(static function () use ($branch, $target, $trashed): void {
            foreach ($trashed as $document) {
                $document->delete();
            }
            Document::query()->withPrintTray()->withTrashed()->whereIn('folder_id', $branch)->update(['folder_id' => $target]);
            // Deepest first, so no folder points at one that is already gone.
            foreach (array_reverse($branch) as $id) {
                Folder::query()->whereKey($id)->delete();
            }
        });

        foreach ($trashed as $document) {
            $audit->record('document.trashed', orgId: $org->id(), userId: $userId, subject: $document, ip: $request->ip());
        }
        $audit->record('folder.deleted', orgId: $org->id(), userId: $userId, subject: $folder, meta: ['name' => $folder->name, 'with_content' => true, 'folders' => count($branch), 'receipts' => $trashed->count()], ip: $request->ip());

        return redirect()->route('documents.index', array_filter(['folder' => $folder->parent_id]))->with('status', trans_choice('folders.deleted_with_content', $trashed->count(), ['count' => $trashed->count()]));
    }

    /**
     * @return array{name: string, parent_id: string|null, color: string|null, icon: string|null, bank_matching: bool}
     */
    private function validated(Request $request, ?Folder $folder = null): array
    {
        $name = trim((string) $request->input('name'));
        // An own colour beats the palette; "none" or empty = the text colour.
        $color = $request->filled('color_hex') ? $request->input('color_hex') : $request->input('color');
        $request->merge([
            'name' => $name,
            'parent_id' => $request->filled('parent_id') ? $request->input('parent_id') : null,
            'color' => is_string($color) && $color !== '' && $color !== 'none' ? mb_strtolower(trim($color)) : null,
            'icon' => $request->filled('icon') ? $request->input('icon') : null,
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\/\\\\]/', $this->nameFree($request, $folder)],
            'parent_id' => ['nullable', 'string', $this->parentAllowed($folder)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            'icon' => ['nullable', 'string', Rule::in(Folder::ICONS)],
        ]);

        return [
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'color' => $data['color'] ?? null,
            'icon' => $data['icon'] ?? null,
            'bank_matching' => $request->boolean('bank_matching'),
        ];
    }

    /** One name per folder level; compared in PHP so the database collation doesn't matter. */
    private function nameFree(Request $request, ?Folder $folder): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($request, $folder): void {
            $parent = $request->input('parent_id');
            $taken = Folder::query()->whereKeyNot($folder?->id)->where('parent_id', is_string($parent) ? $parent : null)->pluck('name')
                ->map(static fn (string $name): string => mb_strtolower($name));

            if (is_string($value) && $taken->contains(mb_strtolower($value))) {
                $fail(__('folders.name_taken'));
            }
        };
    }

    /** The parent exists in this organisation, is not the folder itself or below it, and the tree stays shallow. */
    private function parentAllowed(?Folder $folder): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($folder): void {
            if (! is_string($value)) {
                return;
            }
            $rows = collect(Folder::flat())->keyBy(static fn (array $row): string => $row['folder']->id);
            $parent = $rows->get($value);
            if ($parent === null) {
                $fail(__('folders.parent_invalid'));

                return;
            }
            if ($folder !== null && in_array($value, Folder::branchIds($folder->id), true)) {
                $fail(__('folders.parent_inside'));

                return;
            }
            $below = $folder === null ? 0 : (int) collect(Folder::flat())->filter(static fn (array $row): bool => in_array($row['folder']->id, Folder::branchIds($folder->id), true))->max('depth') - $folder->depth();
            if ($parent['depth'] + 1 + $below > Folder::MAX_DEPTH) {
                $fail(__('folders.too_deep', ['max' => Folder::MAX_DEPTH + 1]));
            }
        };
    }

    private function log(AuditLog $audit, Request $request, CurrentOrg $org, string $action, Folder $folder): void
    {
        $audit->record($action, orgId: $org->id(), userId: $org->membership()->user_id, subject: $folder, meta: ['name' => $folder->name], ip: $request->ip());
    }
}

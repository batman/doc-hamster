<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Documents\FolderRemoval;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Point 33, A88–A90: an assistant's proposals for the folder tree – the same rules as by hand, checked when proposed
 * and again when taken over: a name is free on its level, the tree stays within Folder::MAX_DEPTH levels, a folder never
 * goes into itself, "Dokumente allgemein" is never moved or deleted, private folders are never seen (A69). A new folder
 * may bring its documents along (one click: create and move in); deleting puts what is inside into the Papierkorb.
 */
final class FolderProposals
{
    public function __construct(private readonly CurrentOrg $org, private readonly AuditLog $audit) {}

    /**
     * @param  array<string, string>  $p
     * @return string|null the reason it cannot be filed (key of assistant.propose_unknown), null = fine
     */
    public static function problem(string $kind, array $p): ?string
    {
        $tree = Folder::tree(Folder::flat());
        $folder = static fn (mixed $id): ?Folder => is_string($id) && isset($tree[$id]) ? Folder::query()->find($id) : null;
        $name = static fn (mixed $n): bool => is_string($n) && trim($n) !== '' && mb_strlen($n) <= 100 && preg_match('/[\/\\\\]/', $n) !== 1;
        $parentOk = static fn (mixed $id): bool => $id === null || $id === '' || isset($tree[$id]);

        return match ($kind) {
            Proposal::FOLDER_CREATE => match (true) {
                ! $name($p['name'] ?? null) => 'folder_name',
                ! $parentOk($p['parent_id'] ?? null) => 'folder',
                ($p['parent_id'] ?? null) !== null && $tree[$p['parent_id']]['depth'] + 1 >= Folder::MAX_DEPTH => 'folder_deep',
                self::taken((string) $p['name'], $p['parent_id'] ?? null) => 'folder_taken',
                default => null,
            },
            Proposal::FOLDER_RENAME => match (true) {
                ($f = $folder($p['folder_id'] ?? null)) === null => 'folder',
                ! $name($p['name'] ?? null) => 'folder_name',
                self::taken((string) $p['name'], $f->parent_id, $f->id) => 'folder_taken',
                default => null,
            },
            Proposal::FOLDER_MOVE => match (true) {
                ($f = $folder($p['folder_id'] ?? null)) === null => 'folder',
                $f->is_default => 'folder_fixed',
                ! $parentOk($p['parent_id'] ?? null) || in_array($p['parent_id'] ?? null, Folder::branchIds($f->id), true) => 'folder',
                (($p['parent_id'] ?? null) !== null ? $tree[$p['parent_id']]['depth'] + 1 : 0) + $tree[$f->id]['height'] >= Folder::MAX_DEPTH => 'folder_deep',
                self::taken($f->name, $p['parent_id'] ?? null, $f->id) => 'folder_taken',
                default => null,
            },
            Proposal::FOLDER_DELETE => match (true) {
                ($f = $folder($p['folder_id'] ?? null)) === null => 'folder',
                $f->is_default => 'folder_fixed',
                default => null,
            },
            default => 'folder',
        };
    }

    /** @return string what happened, for the toast and the tile */
    public function accept(Proposal $proposal, ?string $ip): string
    {
        $p = $proposal->payload;
        $needed = $proposal->kind === Proposal::FOLDER_DELETE ? Permission::TrashDocuments : Permission::EditDocuments;
        if (! Gate::allows($needed->value)) {
            throw new RuntimeException(__('proposals.not_allowed'));
        }
        $problem = self::problem($proposal->kind, $p);
        if ($problem !== null) {
            throw new RuntimeException(__('assistant.propose_unknown.'.$problem));
        }
        $user = $this->org->membership()->user_id;
        $log = fn (string $event, Folder $folder, array $more = []) => $this->audit->record($event, orgId: $this->org->id(), userId: $user, subject: $folder,
            meta: ['via' => 'assistant', 'proposal' => $proposal->id, 'name' => $folder->name, ...$more], ip: $ip);

        return match ($proposal->kind) {
            Proposal::FOLDER_CREATE => DB::transaction(function () use ($p, $user, $log): string {
                $parent = isset($p['parent_id']) && $p['parent_id'] !== '' ? Folder::query()->find($p['parent_id']) : null;
                $folder = Folder::query()->create(['org_id' => $this->org->id(), 'name' => trim((string) $p['name']), 'parent_id' => $parent?->id,
                    'bank_matching' => $parent === null || $parent->bank_matching]);
                $ids = Document::query()->visibleTo($user, Gate::allows(Permission::ViewAllDocuments->value))
                    ->whereIn('id', self::ids($p))->pluck('id')->all();
                if ($ids !== []) {
                    Document::query()->whereIn('id', $ids)->update(['folder_id' => $folder->id]);
                }
                $log('folder.created', $folder, ['documents' => count($ids)]);

                return trans_choice('proposals.done.folder_create', count($ids), ['name' => $folder->name, 'count' => count($ids)]);
            }),
            Proposal::FOLDER_RENAME => (function () use ($p, $log): string {
                $folder = Folder::query()->findOrFail($p['folder_id']);
                $old = $folder->name;
                $folder->update(['name' => trim((string) $p['name'])]);
                $log('folder.changed', $folder, ['old' => $old]);

                return __('proposals.done.folder_rename', ['old' => $old, 'name' => $folder->name]);
            })(),
            Proposal::FOLDER_MOVE => (function () use ($p, $log): string {
                $folder = Folder::query()->findOrFail($p['folder_id']);
                $parent = isset($p['parent_id']) && $p['parent_id'] !== '' ? Folder::query()->find($p['parent_id']) : null;
                $folder->update(['parent_id' => $parent?->id, 'position' => null]);
                $log('folder.moved', $folder);

                return __('folders.moved', ['name' => $folder->name, 'target' => $parent->name ?? __('folders.top')]);
            })(),
            default => (function () use ($p, $user, $log): string {
                $folder = Folder::query()->findOrFail($p['folder_id']);
                $trashed = FolderRemoval::withContent($folder, $folder->parent_id ?? Folder::defaultIdFor($this->org->id()), $user, Gate::allows(Permission::ViewAllDocuments->value));
                $log('folder.deleted', $folder, ['with_content' => true, 'receipts' => $trashed->count()]);

                return trans_choice('folders.deleted_with_content', $trashed->count(), ['count' => $trashed->count()]);
            })(),
        };
    }

    /**
     * The documents a new folder brings along – stored as one text "id,id,…" (every payload value is a text).
     *
     * @param  array<string, string>  $p
     * @return list<string>
     */
    public static function ids(array $p): array
    {
        return array_values(array_filter(explode(',', $p['document_ids'] ?? ''), static fn (string $id): bool => $id !== ''));
    }

    /** One name per level, compared without case (like FolderController::nameFree). */
    private static function taken(string $name, mixed $parent, ?string $except = null): bool
    {
        return Folder::query()->whereKeyNot($except)->where('parent_id', is_string($parent) && $parent !== '' ? $parent : null)->pluck('name')
            ->map(static fn (string $n): string => mb_strtolower($n))->contains(mb_strtolower(trim($name)));
    }
}

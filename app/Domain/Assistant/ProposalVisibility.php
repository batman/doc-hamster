<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Point 36, A95: a proposal is shown only to whoever may see everything it names – the Vorschläge window, its red
 * count, the before/after pictures, Übernehmen / Ablehnen by address and an assistant's list_proposals. A proposal about
 * a document in a private folder (A69) or about a private folder is the owner's alone; the person's role restriction
 * counts too. A document that is gone does not hide it (the tile says "nicht mehr vorhanden").
 */
final class ProposalVisibility
{
    /**
     * @param  Collection<int, Proposal>  $proposals
     * @return Collection<int, Proposal>
     */
    public static function filter(Collection $proposals): Collection
    {
        $org = app(CurrentOrg::class);
        if (! $org->isSet() || $proposals->isEmpty()) {
            return $proposals;
        }
        $docIds = $proposals->flatMap(static fn (Proposal $p): array => self::documents($p))->unique()->values()->all();
        $existing = $docIds === [] ? [] : Document::query()->withTrashed()->whereIn('id', $docIds)->pluck('id')->all();
        $visible = $docIds === [] ? [] : Document::query()->withTrashed()->visibleTo($org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value))
            ->whereIn('id', $docIds)->pluck('id')->all();
        $hidden = array_diff($existing, $visible);
        $privateFolders = $org->seesPrivate() ? [] : Folder::privateBranch();

        return $proposals->filter(static fn (Proposal $p): bool => array_intersect(self::documents($p), $hidden) === []
            && array_intersect(self::folders($p), $privateFolders) === [])->values();
    }

    public static function allows(Proposal $proposal): bool
    {
        return self::filter(collect([$proposal]))->isNotEmpty();
    }

    /** @return list<string> */
    private static function documents(Proposal $p): array
    {
        $ids = [];
        foreach (['document_id', 'keep_id', 'remove_id'] as $key) {
            if (is_string($p->payload[$key] ?? null) && $p->payload[$key] !== '') {
                $ids[] = $p->payload[$key];
            }
        }

        return [...$ids, ...FolderProposals::ids($p->payload)];
    }

    /** @return list<string> */
    private static function folders(Proposal $p): array
    {
        return array_values(array_filter([$p->payload['folder_id'] ?? null, $p->payload['parent_id'] ?? null], static fn (mixed $id): bool => is_string($id) && $id !== ''));
    }
}

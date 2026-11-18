<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a folder with everything in it (PO, 2026-11-07) – the folder window's button and an assistant's taken-over
 * proposal (A88) do the same: its subfolders go, the documents this person may see go into the Papierkorb; nothing is
 * lost for good – a document brought back, and any this person may not see, lands in $target (one level above).
 */
final class FolderRemoval
{
    /** @return Collection<int, Document> the documents that went into the Papierkorb */
    public static function withContent(Folder $folder, string $target, string $userId, bool $seesAll): Collection
    {
        $branch = Folder::branchIds($folder->id);
        $trashed = Document::query()->visibleTo($userId, $seesAll)->whereIn('folder_id', $branch)->get();

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

        return $trashed;
    }
}

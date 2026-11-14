<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Log\LogEntry;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Point 207: the daily Papierkorb job. Per organisation it deletes for good what was emptied and whose undo period has
 * ended, and – unless the organisation chose "nie automatisch leeren" – what lay in the Papierkorb longer than its
 * setting. Runs without an active organisation, so every query names the organisation itself.
 */
final readonly class TrashSweeper
{
    public function __construct(private DocumentPurger $purger) {}

    /** @return array{orgs: int, undo_ended: int, kept_days: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $total = ['orgs' => 0, 'undo_ended' => 0, 'kept_days' => 0];
        foreach (Organization::query()->lazyById() as $organization) {
            $counts = $this->sweep($organization, $now);
            $total['orgs']++;
            $total['undo_ended'] += $counts['undo_ended'];
            $total['kept_days'] += $counts['kept_days'];
        }

        return $total;
    }

    /** @return array{undo_ended: int, kept_days: int} */
    public function sweep(Organization $organization, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $undoEnded = $this->purger->purge(
            $this->trashed($organization)->whereNotNull('purge_after')->where('purge_after', '<=', $now)->lazyById(200),
            reason: 'undo_ended',
        );
        $keep = TrashSettings::of($organization)['keep'];
        $keptDays = $keep === 0 ? 0 : $this->purger->purge(
            $this->trashed($organization)->whereNull('purge_after')->where('deleted_at', '<=', $now->copy()->subDays($keep))->lazyById(200),
            reason: 'kept_days',
        );

        if ($undoEnded + $keptDays > 0) {
            LogEntry::write('general', 'event', (string) __('log.trash_swept', ['undo' => $undoEnded, 'kept' => $keptDays, 'days' => $keep]), 'document.trash_swept', $organization->id);
        }

        return ['undo_ended' => $undoEnded, 'kept_days' => $keptDays];
    }

    /** @return Builder<Document> */
    private function trashed(Organization $organization): Builder
    {
        return Document::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $organization->id)->onlyTrashed();
    }
}

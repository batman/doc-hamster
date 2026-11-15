<?php

declare(strict_types=1);

namespace App\Domain\Optimize;

use App\Domain\Documents\DocumentFile;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use App\Jobs\Local\OptimizeDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Point 236: which files the optimiser still has to look at and how far it is. Runs by itself while the module is on:
 * a new upload goes first (before its text recognition, so OCR reads the smaller file); the daily stock is worked off
 * by the scheduler a few files at a time on the low queue. Every query names the organisation itself (jobs run
 * without an active one).
 */
final class OptimizeQueue
{
    public const string QUEUED = 'queued';

    /** Files handed to the queue per organisation and scheduler run. */
    private const int BATCH = 20;

    /** A queued file whose job got lost is handed out again after this many hours. */
    private const int STALE_HOURS = 6;

    /** Types the optimiser can work on. */
    public const array MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /**
     * Files not looked at yet and big enough to be over the threshold with one page.
     *
     * @return Builder<DocumentFile>
     */
    public static function candidates(Organization $organization): Builder
    {
        return self::files($organization)->whereNull('optimize')
            ->where('size', '>', OptimizeSettings::bytesPerPage($organization))
            ->whereIn('mime', self::MIMES);
    }

    /**
     * A new upload: when the module is on and the file may qualify, it is optimised first and the job starts the text
     * recognition afterwards. False = nothing to do, the caller starts the recognition itself.
     */
    public static function first(DocumentFile $file, Organization $organization): bool
    {
        if (! OptimizeSettings::enabled($organization) || ! in_array($file->mime, self::MIMES, true) || $file->size <= OptimizeSettings::bytesPerPage($organization)) {
            return false;
        }
        $file->forceFill(['optimize' => self::QUEUED, 'optimize_at' => now()])->save();
        OptimizeDocument::dispatch($file->id, true);

        return true;
    }

    /** The scheduler's run: a few files per organisation with the module on. */
    public static function sweep(): int
    {
        $count = 0;
        foreach (Organization::query()->lazyById() as $organization) {
            if (OptimizeSettings::enabled($organization)) {
                $count += self::fill($organization);
            }
        }

        return $count;
    }

    /** Hands the next files of one organisation to the low queue (and those whose job got lost). */
    public static function fill(Organization $organization): int
    {
        self::files($organization)->where('optimize', self::QUEUED)->where('optimize_at', '<', now()->subHours(self::STALE_HOURS))
            ->update(['optimize' => null]);
        $waiting = self::files($organization)->where('optimize', self::QUEUED)->count();
        $ids = $waiting >= self::BATCH ? [] : self::candidates($organization)->orderBy('document_files.id')->limit(self::BATCH - $waiting)
            ->pluck('document_files.id')->map(static fn (mixed $id): string => (string) $id)->all();
        if ($ids === []) {
            return 0;
        }
        DocumentFile::query()->withoutGlobalScope(OrgScope::class)->whereIn('id', $ids)->update(['optimize' => self::QUEUED, 'optimize_at' => now()]);
        foreach ($ids as $id) {
            OptimizeDocument::dispatch($id);
        }

        return count($ids);
    }

    /** A new threshold: files that were under the old one are looked at again. */
    public static function thresholdChanged(Organization $organization): void
    {
        self::files($organization)->where('optimize', ShrinkResult::SMALL)->update(['optimize' => null]);
    }

    /**
     * A410: optimised files, files waiting and the space saved (bytes).
     *
     * @return array{done: int, waiting: int, saved: int}
     */
    public static function counts(Organization $organization): array
    {
        $done = self::files($organization)->where('optimize', ShrinkResult::DONE)
            ->selectRaw('count(*) as n, coalesce(sum(original_size - size), 0) as saved')->toBase()->first();
        $waiting = self::candidates($organization)->count() + self::files($organization)->where('optimize', self::QUEUED)->count();

        return ['done' => (int) ($done->n ?? 0), 'waiting' => $waiting, 'saved' => max(0, (int) ($done->saved ?? 0))];
    }

    /**
     * The files of the organisation's documents that are not in the Papierkorb and are no kept originals.
     *
     * @return Builder<DocumentFile>
     */
    private static function files(Organization $organization): Builder
    {
        return DocumentFile::query()->withoutGlobalScope(OrgScope::class)->where('document_files.org_id', $organization->id)
            ->whereIn('document_id', static fn (QueryBuilder $q) => $q->select('id')->from('documents')
                ->where('org_id', $organization->id)->whereNull('deleted_at')->whereNull('original_of'));
    }
}

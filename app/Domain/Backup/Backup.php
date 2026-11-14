<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One "Backup herunterladen" (point 206). Streamed backups are rows only (Home's "Letztes Backup"); background ones
 * carry their file in private storage until they are downloaded and 24 hours have passed. Every query names the
 * organisation explicitly (jobs and the scheduler run without an active one).
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $created_by
 * @property string $status
 * @property int $progress
 * @property array<string, mixed>|null $options
 * @property int $estimate
 * @property int|null $size
 * @property string|null $path
 * @property string|null $error
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $downloaded_at
 * @property CarbonImmutable $created_at
 */
final class Backup extends Model
{
    use HasUlids;

    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public const string STREAMED = 'streamed';

    public const string EXPIRED = 'expired';

    protected $fillable = ['org_id', 'created_by', 'status', 'progress', 'options', 'estimate', 'size', 'path', 'error', 'finished_at', 'downloaded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'progress' => 'integer',
            'estimate' => 'integer',
            'size' => 'integer',
            'finished_at' => 'immutable_datetime',
            'downloaded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return Builder<self>
     */
    public static function ofOrg(string $orgId): Builder
    {
        return self::query()->where('org_id', $orgId);
    }

    /** The private folder for backup files and temporary data (never under public/). */
    public static function workDir(): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('backups');

        return $disk->path('backups');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** Ready and not yet expired (expiry by created_at, not by whether the file still exists – D-023). */
    public function isReady(): bool
    {
        return $this->status === self::DONE && $this->path !== null && $this->created_at->greaterThan(now()->subHours(self::keepHours()));
    }

    /** Home's hint (A372): when this organisation last made a backup, null for never. */
    public static function lastAt(string $orgId): ?CarbonImmutable
    {
        $at = self::ofOrg($orgId)->whereIn('status', [self::DONE, self::STREAMED, self::EXPIRED])->max('created_at');

        return $at !== null ? CarbonImmutable::parse((string) $at) : null;
    }

    /** The red badge: finished backups of this person that wait for their download. */
    public static function waiting(string $orgId, string $userId): int
    {
        return self::ofOrg($orgId)->where('created_by', $userId)->where('status', self::DONE)->whereNull('downloaded_at')
            ->where('created_at', '>', now()->subHours(self::keepHours()))->count();
    }

    /**
     * Deletes backup files older than the keep time (scheduler, and whenever the backup page is opened or a backup
     * starts – cleanup does not rely on cron alone) and stale temporary files.
     */
    public static function prune(): int
    {
        $disk = Storage::disk('local');
        $pruned = 0;
        self::query()->where('created_at', '<', now()->subHours(self::keepHours()))->whereIn('status', [self::DONE, self::QUEUED, self::RUNNING, self::FAILED])
            ->each(static function (self $backup) use ($disk, &$pruned): void {
                if ($backup->path !== null) {
                    $disk->delete($backup->path);
                }
                $backup->forceFill(['status' => $backup->status === self::DONE ? self::EXPIRED : self::FAILED, 'path' => null])->save();
                $pruned++;
            });
        foreach ($disk->files('backups') as $file) {
            if ($disk->lastModified($file) < now()->subHours(self::keepHours())->getTimestamp()) {
                $disk->delete($file);
            }
        }

        return $pruned;
    }

    public static function keepHours(): int
    {
        return (int) config('dochamster.backup.keep_hours', 24);
    }
}

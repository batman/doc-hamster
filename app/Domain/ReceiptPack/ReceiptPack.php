<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One receipt pack (point 211, D-018): frozen once built – the PDF stays as it is, a change means a new pack with the
 * next version of the same period (A379: Belegpaket_<period>_v<N>.pdf). Every query names the organisation (the job
 * runs without an active one until it sets it).
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $created_by
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property string $period_label
 * @property int $version
 * @property list<string> $account_ids
 * @property string $status
 * @property int $progress
 * @property string|null $path
 * @property int|null $size
 * @property int|null $pages
 * @property array<string, mixed>|null $summary
 * @property string|null $error
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $downloaded_at
 * @property CarbonImmutable $created_at
 */
final class ReceiptPack extends Model
{
    use HasUlids;

    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    protected $fillable = ['org_id', 'created_by', 'period_from', 'period_to', 'period_label', 'version', 'account_ids', 'status', 'progress', 'path', 'size', 'pages', 'summary', 'error', 'finished_at', 'downloaded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'version' => 'integer',
            'account_ids' => 'array',
            'progress' => 'integer',
            'size' => 'integer',
            'pages' => 'integer',
            'summary' => 'array',
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

    /** The next version of this period in this organisation: 1, 2, 3 … (every pack counts, also a failed one). */
    public static function nextVersion(string $orgId, string $label): int
    {
        return (int) self::ofOrg($orgId)->where('period_label', $label)->max('version') + 1;
    }

    /**
     * A new pack row with the next version – inside a transaction with a lock, so two clicks never get the same one.
     *
     * @param  list<string>  $accountIds
     */
    public static function start(string $orgId, ?string $userId, PackPeriod $period, array $accountIds, string $status): self
    {
        return DB::transaction(static function () use ($orgId, $userId, $period, $accountIds, $status): self {
            $label = $period->label();
            self::ofOrg($orgId)->where('period_label', $label)->lockForUpdate()->get(['id']);

            return self::query()->create([
                'org_id' => $orgId, 'created_by' => $userId, 'period_from' => $period->from->toDateString(), 'period_to' => $period->to->toDateString(),
                'period_label' => $label, 'version' => self::nextVersion($orgId, $label), 'account_ids' => $accountIds, 'status' => $status,
            ]);
        });
    }

    public function fileName(): string
    {
        return PackPeriod::fileName($this->period_label, $this->version);
    }

    public function period(): PackPeriod
    {
        return new PackPeriod($this->period_from, $this->period_to);
    }

    public function isReady(): bool
    {
        return $this->status === self::DONE && $this->path !== null && Storage::disk('local')->exists($this->path);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** The red badge (Export): finished packs of this person that wait for their first download. */
    public static function waiting(string $orgId, string $userId): int
    {
        return self::ofOrg($orgId)->where('created_by', $userId)->where('status', self::DONE)->whereNull('downloaded_at')->count();
    }

    /** Where the frozen files live (private disk, never under public/). */
    public static function dir(): string
    {
        Storage::disk('local')->makeDirectory('receipt-packs');

        return 'receipt-packs';
    }
}

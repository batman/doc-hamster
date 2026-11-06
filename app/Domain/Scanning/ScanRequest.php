<?php

declare(strict_types=1);

namespace App\Domain\Scanning;

use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One click on "Scannen" when the scan goes through a Scan Station: the
 * station takes it, scans, uploads the pages and reports the end.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string $station_id
 * @property string $source
 * @property string $color
 * @property int $dpi
 * @property string $status queued, running, done or failed
 * @property string|null $error translation key `scan.*`
 * @property int $pages
 * @property bool $acknowledged the person has seen the end
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ScanRequest extends Model
{
    use BelongsToOrg;
    use HasUlids;

    /** Nobody waits longer: a request the station never finished is failed after this. */
    public const GIVE_UP_SECONDS = 300;

    /** Nobody takes a request that has waited in the queue longer than this (station offline). */
    public const QUEUE_SECONDS = 120;

    protected $fillable = ['org_id', 'user_id', 'station_id', 'source', 'color', 'dpi', 'status', 'error', 'pages', 'acknowledged'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['dpi' => 'integer', 'pages' => 'integer', 'acknowledged' => 'boolean'];
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    /**
     * Ends requests nobody finished, so a dead station never leaves a person waiting for ever.
     */
    public static function expireStale(): void
    {
        self::query()->withoutGlobalScope(OrgScope::class)
            ->where('status', 'queued')->where('created_at', '<', now()->subSeconds(self::QUEUE_SECONDS))
            ->update(['status' => 'failed', 'error' => 'scan.station_offline']);
        self::query()->withoutGlobalScope(OrgScope::class)
            ->where('status', 'running')->where('updated_at', '<', now()->subSeconds(self::GIVE_UP_SECONDS))
            ->update(['status' => 'failed', 'error' => 'scan.timeout']);
    }
}

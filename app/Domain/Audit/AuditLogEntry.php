<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of the append-only audit trail (PLAN §6.4, §11.3). Written through
 * AuditLog::record(). Updating or deleting a model instance throws
 * AppendOnlyViolation, also quietly or without events. Bulk queries are not
 * blocked – the rule for code is: never update or delete audit rows.
 *
 * @property string $id
 * @property string|null $org_id
 * @property string|null $user_id
 * @property string|null $device_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $meta_json
 * @property string|null $ip_hash
 * @property Carbon $created_at
 */
final class AuditLogEntry extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $fillable = ['org_id', 'user_id', 'device_id', 'action', 'subject_type', 'subject_id', 'meta_json', 'ip_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meta_json' => 'array'];
    }

    /**
     * Not an event listener: events can be switched off (`saveQuietly`,
     * `withoutEvents`), this method can't be skipped.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): never
    {
        throw AppendOnlyViolation::for('updating');
    }

    protected function performDeleteOnModel(): never
    {
        throw AppendOnlyViolation::for('deleting');
    }
}

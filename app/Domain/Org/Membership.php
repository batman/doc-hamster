<?php

declare(strict_types=1);

namespace App\Domain\Org;

use App\Domain\Auth\User;
use Carbon\CarbonImmutable;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's role in one organisation. Revoking keeps the row (`revoked_at`) so
 * the audit trail still resolves who it was; the org scope and policies that
 * check memberships come with P1-6.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property Role $role
 * @property CarbonImmutable|null $revoked_at
 */
#[UseFactory(MembershipFactory::class)]
final class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = ['org_id', 'user_id', 'role', 'revoked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}

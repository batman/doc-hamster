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
 * @property string|null $org_role_id null for Admins
 * @property CarbonImmutable|null $revoked_at
 */
#[UseFactory(MembershipFactory::class)]
final class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = ['org_id', 'user_id', 'role', 'org_role_id', 'revoked_at'];

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

    /**
     * Follows the foreign key; memberships are listed across organisations, so
     * this can't depend on the active one.
     *
     * @return BelongsTo<OrgRole, $this>
     */
    public function orgRole(): BelongsTo
    {
        return $this->belongsTo(OrgRole::class, 'org_role_id')->withoutGlobalScope(OrgScope::class);
    }

    /**
     * Admin with null, otherwise the given role. Tax advisors keep their own
     * kind of membership for the sign-in (D-012).
     */
    public function assignRole(?OrgRole $orgRole): void
    {
        $this->role = match (true) {
            $orgRole === null => Role::Owner,
            $orgRole->system_key === Role::Accountant => Role::Accountant,
            default => Role::Member,
        };
        $this->org_role_id = $orgRole?->id;
    }

    public function roleName(): string
    {
        return $this->orgRole->name ?? (string) __('members.roles.'.$this->role->value);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}

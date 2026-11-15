<?php

declare(strict_types=1);

namespace App\Domain\Org;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role an Admin names and sets checkboxes for (#30). Admin itself is not a
 * row here: it is `memberships.role = owner` and may always do everything.
 * `system_key` marks the pre-installed role ("Mitarbeiter"), which can't be deleted.
 * Point 215: optional restrictions (period, accounts, folder branches – RoleRestriction) and a pause (point 223).
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property Role|null $system_key
 * @property list<string> $rights
 * @property CarbonImmutable|null $period_from
 * @property CarbonImmutable|null $period_to
 * @property list<string>|null $account_ids null = every account
 * @property list<string>|null $folder_ids null = every folder; a ticked folder includes its subfolders
 * @property CarbonImmutable|null $paused_at
 */
final class OrgRole extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $table = 'roles';

    protected $fillable = ['org_id', 'name', 'system_key', 'rights', 'period_from', 'period_to', 'account_ids', 'folder_ids', 'paused_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_key' => Role::class,
            'rights' => 'array',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'account_ids' => 'array',
            'folder_ids' => 'array',
            'paused_at' => 'immutable_datetime',
        ];
    }

    /**
     * Creates the pre-installed role and, in a new organisation, the read-only "Steuerberater" (an ordinary role).
     * Skips the model events: a new organisation gets its rows whichever organisation is active right now.
     */
    public static function ensureDefaults(Organization $organization): void
    {
        self::withoutEvents(static function () use ($organization): void {
            $query = static fn () => self::query()->withoutGlobalScope(OrgScope::class);
            $query()->firstOrCreate(
                ['org_id' => $organization->id, 'system_key' => Role::Member->value],
                ['name' => __('members.roles.member'), 'rights' => self::values(RoleRight::defaults())],
            );
            $advisor = (string) __('members.roles.advisor');
            if (! $query()->where('org_id', $organization->id)->where('name', $advisor)->exists()) {
                $query()->create(['org_id' => $organization->id, 'name' => $advisor, 'rights' => self::values(RoleRight::advisor())]);
            }
        });
    }

    /**
     * @param  list<RoleRight>  $rights
     * @return list<string>
     */
    private static function values(array $rights): array
    {
        return array_map(static fn (RoleRight $right): string => $right->value, $rights);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'org_role_id');
    }

    public function has(RoleRight $right): bool
    {
        return in_array($right->value, $this->rights, true);
    }

    public function isPreinstalled(): bool
    {
        return $this->system_key !== null;
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    public function restriction(): RoleRestriction
    {
        return RoleRestriction::of($this);
    }
}

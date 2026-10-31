<?php

declare(strict_types=1);

namespace App\Domain\Org;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role an Admin names and sets checkboxes for (#30). Admin itself is not a
 * row here: it is `memberships.role = owner` and may always do everything.
 * `system_key` marks the pre-installed roles, which can't be deleted.
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property Role|null $system_key
 * @property list<string> $rights
 */
final class OrgRole extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $table = 'roles';

    protected $fillable = ['org_id', 'name', 'system_key', 'rights'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_key' => Role::class,
            'rights' => 'array',
        ];
    }

    /**
     * Creates the missing pre-installed roles. Skips the model events: a new
     * organisation gets its rows whichever organisation is active right now.
     */
    public static function ensureDefaults(Organization $organization): void
    {
        self::withoutEvents(static function () use ($organization): void {
            foreach ([Role::Member, Role::Accountant] as $role) {
                self::query()->withoutGlobalScope(OrgScope::class)->firstOrCreate(
                    ['org_id' => $organization->id, 'system_key' => $role->value],
                    [
                        'name' => __('members.roles.'.$role->value),
                        'rights' => array_map(static fn (RoleRight $right): string => $right->value, RoleRight::defaultsFor($role)),
                    ],
                );
            }
        });
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
}

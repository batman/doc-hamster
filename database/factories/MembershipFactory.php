<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgRole;
use App\Domain\Org\OrgScope;
use App\Domain\Org\Role;
use App\Domain\Org\RoleRight;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Membership>
 */
final class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => Role::Member,
        ];
    }

    /**
     * Non-Admins get the pre-installed role of their organisation.
     */
    public function configure(): static
    {
        return $this->afterMaking(static function (Membership $membership): void {
            if ($membership->role !== Role::Owner && $membership->org_role_id === null) {
                $membership->org_role_id = OrgRole::query()->withoutGlobalScope(OrgScope::class)
                    ->where('org_id', $membership->org_id)
                    ->where('system_key', $membership->role->value)
                    ->value('id');
            }
        });
    }

    /**
     * Sets the checkboxes of the person's role, for everyone in the
     * organisation who has it.
     */
    public function withRights(RoleRight ...$rights): self
    {
        return $this->afterCreating(static function (Membership $membership) use ($rights): void {
            DB::table('roles')->where('id', $membership->org_role_id)->update([
                'rights' => json_encode(array_map(static fn (RoleRight $right): string => $right->value, $rights)),
            ]);
        });
    }

    public function owner(): self
    {
        return $this->state(fn (): array => ['role' => Role::Owner]);
    }

    public function accountant(): self
    {
        return $this->state(fn (): array => [
            'role' => Role::Accountant,
            'user_id' => User::factory()->accountant(),
        ]);
    }
}

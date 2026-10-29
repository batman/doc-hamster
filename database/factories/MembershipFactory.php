<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

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

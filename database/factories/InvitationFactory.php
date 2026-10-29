<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Org\Invitation;
use App\Domain\Org\Organization;
use App\Domain\Org\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
final class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'role' => Role::Member,
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addMinutes(10),
        ];
    }
}

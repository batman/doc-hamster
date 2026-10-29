<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auth\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Synthetic users only (AGENTS.md). Default = owner/member style account
 * without password; `accountant()` = tax advisor with username + password.
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'display_name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'locale' => 'de',
        ];
    }

    public function accountant(?string $password = null): self
    {
        return $this->state(fn (): array => [
            'username' => 'stb-'.Str::lower(Str::random(8)),
            'email' => null,
            'password_hash' => $password ?? Str::password(24),
        ]);
    }
}

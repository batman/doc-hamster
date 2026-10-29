<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auth\Passkey;
use App\Domain\Auth\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Random bytes in place of real WebAuthn data – enough for storage tests.
 *
 * @extends Factory<Passkey>
 */
final class PasskeyFactory extends Factory
{
    protected $model = Passkey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'credential_id' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'public_key' => random_bytes(77),
            'sign_count' => 0,
            'transports' => ['internal'],
            'name' => 'Testgerät',
        ];
    }
}

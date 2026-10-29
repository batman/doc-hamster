<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auth\Device;
use App\Domain\Auth\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
final class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Testhandy',
            'user_agent' => 'Mozilla/5.0 (synthetic test agent)',
            'token_hash' => hash('sha256', Str::random(40)),
            'token_family' => (string) Str::ulid(),
        ];
    }
}

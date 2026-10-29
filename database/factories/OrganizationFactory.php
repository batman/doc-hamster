<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Org\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Haushalt '.$this->faker->lastName(),
        ];
    }
}

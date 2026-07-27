<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'hcode' => (string) fake()->unique()->numberBetween(10000, 99999),
            'name_th' => 'โรงพยาบาลทดสอบ '.fake()->unique()->numerify('###'),
            'name_en' => fake()->company(),
            'is_active' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Testing123!'),
            'cid_hash' => null,
            'cid_encrypted' => null,
            'is_active' => true,
            'system_role' => SystemRole::User,
            'admin_slot' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'system_role' => SystemRole::SuperAdmin,
            'admin_slot' => null,
            'is_active' => true,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'system_role' => SystemRole::Admin,
            'admin_slot' => 1,
            'is_active' => true,
        ]);
    }
}

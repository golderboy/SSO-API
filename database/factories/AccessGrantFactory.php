<?php

namespace Database\Factories;

use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccessGrant>
 */
class AccessGrantFactory extends Factory
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
            'user_id' => User::factory(),
            'application_id' => Application::factory(),
            'organization_id' => Organization::factory(),
            'role' => 'user',
            'permissions' => ['site.login'],
            'is_active' => true,
            'valid_from' => null,
            'valid_until' => null,
            'revoked_at' => null,
        ];
    }
}

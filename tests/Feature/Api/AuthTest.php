<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_and_logout(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.test',
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'SecurePassword123!',
            'device_name' => 'phpunit',
        ]);

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonPath('data.user.id', $admin->public_id)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token']]);

        $token = $response->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->public_id);

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_super_admin_can_login(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'email' => 'grants@example.test',
            'password' => 'SecurePassword123!',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $superAdmin->email,
            'password' => 'SecurePassword123!',
            'device_name' => 'phpunit',
        ])->assertOk()
            ->assertJsonPath('data.user.system_role', 'super_admin');
    }

    public function test_login_uses_generic_error_for_non_administrative_user_and_wrong_password(): void
    {
        $user = User::factory()->create([
            'email' => 'personnel@example.test',
            'password' => 'SecurePassword123!',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'SecurePassword123!',
            'device_name' => 'phpunit',
        ])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');

        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.test',
            'password' => 'SecurePassword123!',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
            'device_name' => 'phpunit',
        ])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.test',
            'password' => 'wrong-password',
            'device_name' => 'phpunit',
        ])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_login_normalizes_email_case_and_whitespace(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@example.test',
            'password' => 'SecurePassword123!',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => '  ADMIN@EXAMPLE.TEST  ',
            'password' => 'SecurePassword123!',
            'device_name' => 'phpunit',
        ])->assertOk();
    }
}

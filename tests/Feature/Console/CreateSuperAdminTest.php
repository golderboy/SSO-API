<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Services\PersonnelIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSyntheticCid;
use Tests\TestCase;

class CreateSuperAdminTest extends TestCase
{
    use CreatesSyntheticCid;
    use RefreshDatabase;

    public function test_command_creates_super_administrator(): void
    {
        $cid = $this->syntheticCid();

        $this->artisan('sso:create-admin', [
            '--name' => 'Initial Administrator',
            '--email' => 'admin@example.test',
        ])
            ->expectsQuestion('Thai citizen ID (input is hidden)', $cid)
            ->expectsQuestion('Password (minimum 12 characters)', 'SecurePassword123!')
            ->expectsQuestion('Confirm password', 'SecurePassword123!')
            ->expectsOutput(
                'Super administrator created. '
                .'Use POST /api/v1/auth/login to obtain a token.',
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.test',
            'is_active' => true,
            'is_super_admin' => true,
        ]);
    }

    public function test_duplicate_cid_is_rejected_without_database_exception(): void
    {
        $cid = $this->syntheticCid();
        $existingUser = User::factory()->create();
        app(PersonnelIdentityService::class)->setCid($existingUser, $cid);
        $existingUser->save();

        $this->artisan('sso:create-admin', [
            '--name' => 'Duplicate Administrator',
            '--email' => 'duplicate@example.test',
        ])
            ->expectsQuestion('Thai citizen ID (input is hidden)', $cid)
            ->expectsOutput(
                'A user with this citizen ID already exists. No changes were made. '
                .'Use --promote-existing only after verifying the existing account.',
            )
            ->assertFailed();

        $this->assertDatabaseMissing('users', [
            'email' => 'duplicate@example.test',
        ]);
    }

    public function test_explicit_option_promotes_existing_user_and_revokes_tokens(): void
    {
        $cid = $this->syntheticCid();
        $existingUser = User::factory()->create([
            'email' => 'personnel@example.test',
            'is_active' => true,
            'is_super_admin' => false,
        ]);
        app(PersonnelIdentityService::class)->setCid($existingUser, $cid);
        $existingUser->save();
        $existingUser->createToken('existing-session', ['admin']);

        $this->artisan('sso:create-admin', [
            '--name' => 'Promoted Administrator',
            '--email' => 'promoted@example.test',
            '--promote-existing' => true,
        ])
            ->expectsQuestion('Thai citizen ID (input is hidden)', $cid)
            ->expectsQuestion('Password (minimum 12 characters)', 'SecurePassword123!')
            ->expectsQuestion('Confirm password', 'SecurePassword123!')
            ->expectsOutput(
                'Super administrator promoted. '
                .'Use POST /api/v1/auth/login to obtain a token.',
            )
            ->assertSuccessful();

        $existingUser->refresh();

        $this->assertSame('Promoted Administrator', $existingUser->name);
        $this->assertSame('promoted@example.test', $existingUser->email);
        $this->assertTrue($existingUser->is_active);
        $this->assertTrue($existingUser->is_super_admin);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $existingUser->id,
        ]);
    }
}

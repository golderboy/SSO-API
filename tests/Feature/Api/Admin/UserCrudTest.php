<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSyntheticCid;
use Tests\TestCase;

class UserCrudTest extends TestCase
{
    use CreatesSyntheticCid;
    use RefreshDatabase;

    public function test_admin_authentication_and_super_admin_role_are_required(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['admin']);

        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_super_admin_can_create_update_read_and_delete_personnel(): void
    {
        $admin = User::factory()->superAdmin()->create();
        Sanctum::actingAs($admin, ['admin']);

        $create = $this->postJson('/api/v1/admin/users', [
            'name' => 'Synthetic Personnel',
            'email' => 'personnel@example.test',
            'cid' => $this->syntheticCid(),
            'is_active' => true,
            'is_super_admin' => false,
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('data.name', 'Synthetic Personnel')
            ->assertJsonPath('data.has_cid', true)
            ->assertJsonMissingPath('data.cid')
            ->assertJsonMissingPath('data.cid_hash')
            ->assertJsonMissingPath('data.password');

        $userId = $create->json('data.id');

        $this->getJson("/api/v1/admin/users/{$userId}")
            ->assertOk()
            ->assertJsonPath('data.id', $userId);

        $this->patchJson("/api/v1/admin/users/{$userId}", [
            'name' => 'Updated Personnel',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Personnel');

        $this->deleteJson("/api/v1/admin/users/{$userId}")->assertNoContent();
        $this->getJson("/api/v1/admin/users/{$userId}")->assertNotFound();

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deleted']);
    }

    public function test_admin_cannot_delete_or_demote_self(): void
    {
        $admin = User::factory()->superAdmin()->create();
        Sanctum::actingAs($admin, ['admin']);

        $this->patchJson("/api/v1/admin/users/{$admin->public_id}", [
            'is_super_admin' => false,
        ])->assertUnprocessable();

        $this->deleteJson("/api/v1/admin/users/{$admin->public_id}")
            ->assertUnprocessable();
    }

    public function test_invalid_cid_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create(), ['admin']);

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Invalid CID',
            'cid' => '123',
        ])->assertUnprocessable()->assertJsonValidationErrors('cid');

        $this->postJson('/api/v1/admin/users', [
            'name' => 'CID With Letters',
            'cid' => 'A-'.$this->syntheticCid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('cid');
    }

    public function test_duplicate_cid_is_rejected_without_exposing_cid(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create(), ['admin']);
        $cid = $this->syntheticCid();

        $this->postJson('/api/v1/admin/users', [
            'name' => 'First Personnel',
            'cid' => $cid,
        ])->assertCreated();

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Duplicate Personnel',
            'cid' => $cid,
        ])->assertStatus(409)->assertJsonMissing(['cid' => $cid]);
    }

    public function test_password_change_revokes_existing_tokens(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->superAdmin()->create();
        $target->createToken('existing-session', ['admin']);
        Sanctum::actingAs($admin, ['admin']);

        $this->patchJson("/api/v1/admin/users/{$target->public_id}", [
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $target->id,
        ]);
    }

    public function test_promoting_admin_requires_email_and_password(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create(), ['admin']);
        $target = User::factory()->create([
            'email' => null,
            'password' => null,
        ]);

        $this->patchJson("/api/v1/admin/users/{$target->public_id}", [
            'is_super_admin' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);

        $this->patchJson("/api/v1/admin/users/{$target->public_id}", [
            'email' => 'NEW-ADMIN@EXAMPLE.TEST',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'is_super_admin' => true,
        ])->assertOk()
            ->assertJsonPath('data.email', 'new-admin@example.test')
            ->assertJsonPath('data.is_super_admin', true);
    }

    public function test_cid_cannot_be_cleared_through_update(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create(), ['admin']);
        $target = User::factory()->create();

        $this->patchJson("/api/v1/admin/users/{$target->public_id}", [
            'cid' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('cid');
    }
}

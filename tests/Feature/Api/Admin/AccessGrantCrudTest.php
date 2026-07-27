<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Application;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessGrantCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_and_revoke_access_grant(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $personnel = User::factory()->create();
        $application = Application::factory()->create();
        $organization = Organization::factory()->create();
        Sanctum::actingAs($admin, ['admin']);

        $payload = [
            'user_id' => $personnel->public_id,
            'application_id' => $application->public_id,
            'organization_id' => $organization->public_id,
            'role' => 'staff',
            'permissions' => ['site.login', 'records.read'],
        ];

        $create = $this->postJson('/api/v1/admin/access-grants', $payload);
        $create
            ->assertCreated()
            ->assertJsonPath('data.user.id', $personnel->public_id)
            ->assertJsonPath('data.application.id', $application->public_id)
            ->assertJsonPath('data.organization.id', $organization->public_id)
            ->assertJsonPath('data.role', 'staff');

        $grantId = $create->json('data.id');

        $this->postJson('/api/v1/admin/access-grants', $payload)
            ->assertStatus(409);

        $this->patchJson("/api/v1/admin/access-grants/{$grantId}", [
            'permissions' => ['site.login'],
        ])->assertOk()->assertJsonPath('data.permissions.0', 'site.login');

        $this->deleteJson("/api/v1/admin/access-grants/{$grantId}")
            ->assertNoContent();

        $this->assertDatabaseHas('access_grants', [
            'public_id' => $grantId,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'access_grant.revoked']);
    }

    public function test_organization_is_required_when_application_policy_requires_it(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create(), ['admin']);
        $personnel = User::factory()->create();
        $application = Application::factory()->create([
            'require_organization_match' => true,
        ]);

        $this->postJson('/api/v1/admin/access-grants', [
            'user_id' => $personnel->public_id,
            'application_id' => $application->public_id,
            'organization_id' => null,
            'role' => 'staff',
        ])->assertUnprocessable();
    }
}

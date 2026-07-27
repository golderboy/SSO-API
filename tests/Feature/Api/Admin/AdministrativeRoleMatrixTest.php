<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdministrativeRoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_has_read_only_reference_data_and_audit_access(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $application = Application::factory()->create();
        $organization = Organization::factory()->create();
        $audit = AuditLog::query()->create([
            'public_id' => fake()->uuid(),
            'action' => 'test.event',
        ]);
        Sanctum::actingAs($superAdmin, ['admin']);

        $this->getJson('/api/v1/admin/applications')->assertOk();
        $this->getJson("/api/v1/admin/applications/{$application->public_id}")->assertOk();
        $this->getJson('/api/v1/admin/organizations')->assertOk();
        $this->getJson("/api/v1/admin/organizations/{$organization->public_id}")->assertOk();
        $this->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->getJson("/api/v1/admin/audit-logs/{$audit->public_id}")->assertOk();
    }

    public function test_super_admin_cannot_manage_system_configuration(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $application = Application::factory()->create();
        $organization = Organization::factory()->create();
        Sanctum::actingAs($superAdmin, ['admin']);

        $this->postJson('/api/v1/admin/applications', [
            'name' => 'Forbidden',
            'slug' => 'forbidden',
        ])->assertForbidden();
        $this->patchJson("/api/v1/admin/applications/{$application->public_id}", [
            'name' => 'Forbidden',
        ])->assertForbidden();
        $this->deleteJson("/api/v1/admin/applications/{$application->public_id}")
            ->assertForbidden();

        $this->postJson('/api/v1/admin/organizations', [
            'hcode' => '99999',
            'name_th' => 'Forbidden',
        ])->assertForbidden();
        $this->patchJson("/api/v1/admin/organizations/{$organization->public_id}", [
            'name_th' => 'Forbidden',
        ])->assertForbidden();
        $this->deleteJson("/api/v1/admin/organizations/{$organization->public_id}")
            ->assertForbidden();

        $this->postJson(
            "/api/v1/admin/applications/{$application->public_id}/api-keys",
            ['name' => 'forbidden'],
        )->assertForbidden();
    }
}

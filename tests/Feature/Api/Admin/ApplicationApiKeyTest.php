<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_supply_key_and_plain_text_is_returned_once(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create(), ['admin']);
        $application = Application::factory()->create();
        $plainTextKey = str_repeat('z', 64);

        $response = $this->postJson(
            "/api/v1/admin/applications/{$application->public_id}/api-keys",
            [
                'name' => 'system-provided-key',
                'key' => $plainTextKey,
            ],
        );

        $response
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.key', $plainTextKey)
            ->assertJsonPath(
                'data.warning',
                'Store this key now. It cannot be retrieved again.',
            );

        $this->assertDatabaseHas('application_api_keys', [
            'application_id' => $application->id,
            'key_hash' => hash('sha256', $plainTextKey),
        ]);
    }

    public function test_duplicate_key_is_rejected_and_cross_application_revoke_is_hidden(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create(), ['admin']);
        $application = Application::factory()->create();
        $otherApplication = Application::factory()->create();
        $plainTextKey = str_repeat('q', 64);

        $created = $this->postJson(
            "/api/v1/admin/applications/{$application->public_id}/api-keys",
            ['name' => 'first', 'key' => $plainTextKey],
        )->assertCreated();

        $this->postJson(
            "/api/v1/admin/applications/{$otherApplication->public_id}/api-keys",
            ['name' => 'duplicate', 'key' => $plainTextKey],
        )->assertUnprocessable()->assertJsonValidationErrors('key');

        $keyId = $created->json('data.id');

        $this->deleteJson(
            "/api/v1/admin/applications/{$otherApplication->public_id}/api-keys/{$keyId}",
        )->assertNotFound();

        $this->deleteJson(
            "/api/v1/admin/applications/{$application->public_id}/api-keys/{$keyId}",
        )->assertNoContent();
    }
}

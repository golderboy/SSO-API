<?php

namespace Tests\Feature\Api;

use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyService;
use App\Services\PersonnelIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSyntheticCid;
use Tests\TestCase;

class AccessCheckTest extends TestCase
{
    use CreatesSyntheticCid;
    use RefreshDatabase;

    public function test_application_key_and_matching_grant_allow_access(): void
    {
        [$key, $cid, $application, $organization, $user] = $this->authorizedFixture();

        $this->withHeader('X-API-Key', $key)
            ->postJson('/api/v1/access/check', [
                'cid' => $cid,
                'organization_hcode' => $organization->hcode,
            ])
            ->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.subject_id', $user->public_id)
            ->assertJsonPath('data.application_id', $application->public_id)
            ->assertJsonPath('data.organization.hcode', $organization->hcode)
            ->assertJsonPath('data.role', 'staff');

        $this->assertDatabaseHas('audit_logs', ['action' => 'access.allowed']);
    }

    public function test_missing_invalid_and_revoked_keys_are_rejected(): void
    {
        $this->postJson('/api/v1/access/check', [])->assertUnauthorized();

        $this->withHeader('X-API-Key', str_repeat('x', 64))
            ->postJson('/api/v1/access/check', [])
            ->assertUnauthorized();

        [$key, $cid, , $organization] = $this->authorizedFixture();
        $apiKey = app(ApiKeyService::class)->findUsable($key);
        $apiKey->update(['revoked_at' => now()]);

        $this->withHeader('X-API-Key', $key)
            ->postJson('/api/v1/access/check', [
                'cid' => $cid,
                'organization_hcode' => $organization->hcode,
            ])->assertUnauthorized();
    }

    public function test_invalid_key_requests_are_rate_limited_before_authentication(): void
    {
        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->withHeader('X-API-Key', str_repeat('x', 64))
                ->postJson('/api/v1/access/check', [])
                ->assertUnauthorized();
        }

        $this->withHeader('X-API-Key', str_repeat('x', 64))
            ->postJson('/api/v1/access/check', [])
            ->assertTooManyRequests();
    }

    public function test_wrong_application_or_organization_does_not_leak_denial_reason(): void
    {
        [, $cid, , $organization] = $this->authorizedFixture();
        $otherApplication = Application::factory()->create();
        $otherKey = app(ApiKeyService::class)->issue(
            $otherApplication,
            'test',
            str_repeat('b', 64),
        )['plain_text_key'];

        $this->withHeader('X-API-Key', $otherKey)
            ->postJson('/api/v1/access/check', [
                'cid' => $cid,
                'organization_hcode' => $organization->hcode,
            ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'allowed' => false,
                    'reason' => 'not_authorized',
                ],
            ]);

        $otherOrganization = Organization::factory()->create();
        [$key] = $this->authorizedFixture('200000000000');

        $this->withHeader('X-API-Key', $key)
            ->postJson('/api/v1/access/check', [
                'cid' => $cid,
                'organization_hcode' => $otherOrganization->hcode,
            ])
            ->assertOk()
            ->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.reason', 'not_authorized');
    }

    /**
     * @return array{string, string, Application, Organization, User}
     */
    private function authorizedFixture(
        string $cidPrefix = '100000000000',
    ): array {
        $cid = $this->syntheticCid($cidPrefix);
        $user = User::factory()->create();
        app(PersonnelIdentityService::class)->setCid($user, $cid);
        $user->save();

        $application = Application::factory()->create();
        $organization = Organization::factory()->create();
        AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => $organization->id,
            'role' => 'staff',
            'permissions' => ['site.login'],
        ]);

        $key = app(ApiKeyService::class)->issue(
            $application,
            'test',
            hash('sha256', 'synthetic-api-key-'.$cidPrefix),
        )['plain_text_key'];

        return [$key, $cid, $application, $organization, $user];
    }
}

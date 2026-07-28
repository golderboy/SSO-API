<?php

namespace Tests\Feature;

use App\Exceptions\BrokerRequestException;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\Organization;
use App\Models\User;
use App\Services\SsoAccessGrantPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SsoAccessGrantPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_organizations_are_intersected_with_local_grants(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create([
            'require_organization_match' => true,
        ]);
        $allowedOrganization = Organization::factory()->create([
            'hcode' => '00123',
        ]);
        $otherOrganization = Organization::factory()->create([
            'hcode' => '00456',
        ]);
        $allowedGrant = AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => $allowedOrganization->id,
        ]);
        AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => $otherOrganization->id,
        ]);

        $grants = app(SsoAccessGrantPolicy::class)->eligible(
            $user,
            $application,
            ['00123', '99999'],
        );

        $this->assertCount(1, $grants);
        $this->assertTrue($grants->sole()->is($allowedGrant));
    }

    public function test_empty_provider_organization_set_fails_closed(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create([
            'require_organization_match' => false,
        ]);
        AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => null,
        ]);

        $grants = app(SsoAccessGrantPolicy::class)->eligible(
            $user,
            $application,
            [],
        );

        $this->assertTrue($grants->isEmpty());
    }

    public function test_local_only_policy_remains_available_for_thaid(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create([
            'require_organization_match' => true,
        ]);
        $organization = Organization::factory()->create();
        $grant = AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => $organization->id,
        ]);

        $grants = app(SsoAccessGrantPolicy::class)->eligible(
            $user,
            $application,
        );

        $this->assertCount(1, $grants);
        $this->assertTrue($grants->sole()->is($grant));
    }

    public function test_invalid_provider_organization_set_is_rejected(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();

        $this->expectException(BrokerRequestException::class);

        app(SsoAccessGrantPolicy::class)->eligible(
            $user,
            $application,
            ['../invalid'],
        );
    }
}

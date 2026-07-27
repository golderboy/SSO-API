<?php

namespace Tests\Feature;

use App\Data\VerifiedExternalIdentity;
use App\Exceptions\IdentityResolutionException;
use App\Models\ExternalIdentity;
use App\Models\User;
use App\Services\IdentityLinkService;
use App\Services\PersonnelIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSyntheticCid;
use Tests\TestCase;

class IdentityLinkServiceTest extends TestCase
{
    use CreatesSyntheticCid;
    use RefreshDatabase;

    public function test_thaid_links_only_to_an_existing_user_with_exact_pid(): void
    {
        $cid = $this->syntheticCid();
        $user = $this->userWithCid($cid);
        $identity = VerifiedExternalIdentity::thaId(
            'verified-thaid-subject',
            $cid,
        );

        $resolved = app(IdentityLinkService::class)->resolve($identity);

        $this->assertTrue($resolved->is($user));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('external_identities', 1);
        $externalIdentity = ExternalIdentity::query()->sole();
        $this->assertSame('thaid', $externalIdentity->provider->value);
        $this->assertNotSame(
            'verified-thaid-subject',
            $externalIdentity->getRawOriginal('subject_hash'),
        );
        $this->assertNotSame(
            $cid,
            $externalIdentity->getRawOriginal('identity_match_hash'),
        );
    }

    public function test_provider_id_links_with_verified_sha256_cid_hash(): void
    {
        $cid = $this->syntheticCid('110000000000');
        $user = $this->userWithCid($cid);
        $identity = VerifiedExternalIdentity::providerId(
            'provider-account-id',
            hash('sha256', $cid),
        );

        $resolved = app(IdentityLinkService::class)->resolve($identity);

        $this->assertTrue($resolved->is($user));
        $this->assertDatabaseHas('external_identities', [
            'user_id' => $user->id,
            'provider' => 'provider_id',
        ]);
    }

    public function test_unknown_verified_identity_is_denied_without_creating_user(): void
    {
        $identity = VerifiedExternalIdentity::thaId(
            'unknown-subject',
            $this->syntheticCid(),
        );

        try {
            app(IdentityLinkService::class)->resolve($identity);
            $this->fail('An unknown identity must be denied.');
        } catch (IdentityResolutionException $exception) {
            $this->assertSame('identity_not_authorized', $exception->reason);
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('external_identities', 0);
    }

    public function test_existing_subject_is_denied_when_verified_cid_changes(): void
    {
        $firstCid = $this->syntheticCid();
        $secondCid = $this->syntheticCid('120000000000');
        $this->userWithCid($firstCid);
        $this->userWithCid($secondCid);
        $service = app(IdentityLinkService::class);
        $service->resolve(
            VerifiedExternalIdentity::thaId('stable-subject', $firstCid),
        );

        try {
            $service->resolve(
                VerifiedExternalIdentity::thaId('stable-subject', $secondCid),
            );
            $this->fail('A changed CID must never relink automatically.');
        } catch (IdentityResolutionException $exception) {
            $this->assertSame('identity_link_mismatch', $exception->reason);
        }

        $this->assertDatabaseCount('external_identities', 1);
    }

    public function test_inactive_linked_user_is_denied(): void
    {
        $cid = $this->syntheticCid();
        $user = $this->userWithCid($cid);
        $service = app(IdentityLinkService::class);
        $identity = VerifiedExternalIdentity::thaId('subject', $cid);
        $service->resolve($identity);
        $user->forceFill(['is_active' => false])->save();

        $this->expectException(IdentityResolutionException::class);
        $service->resolve($identity);
    }

    public function test_new_subject_for_already_linked_provider_cid_is_not_relinked(): void
    {
        $cid = $this->syntheticCid();
        $this->userWithCid($cid);
        $service = app(IdentityLinkService::class);
        $providerCidHash = hash('sha256', $cid);
        $service->resolve(
            VerifiedExternalIdentity::providerId(
                'original-account',
                $providerCidHash,
            ),
        );

        try {
            $service->resolve(
                VerifiedExternalIdentity::providerId(
                    'replacement-account',
                    $providerCidHash,
                ),
            );
            $this->fail('A changed provider subject must require manual review.');
        } catch (IdentityResolutionException $exception) {
            $this->assertSame('identity_link_conflict', $exception->reason);
        }

        $this->assertDatabaseCount('external_identities', 1);
    }

    private function userWithCid(string $cid): User
    {
        $user = User::factory()->create();
        app(PersonnelIdentityService::class)->setCid($user, $cid);
        $user->save();

        return $user;
    }
}

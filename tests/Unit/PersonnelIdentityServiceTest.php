<?php

namespace Tests\Unit;

use App\Services\PersonnelIdentityService;
use Tests\TestCase;

class PersonnelIdentityServiceTest extends TestCase
{
    public function test_cid_hash_is_deterministic_but_does_not_expose_cid(): void
    {
        $service = app(PersonnelIdentityService::class);
        $formatted = '1-0000-00000-00-9';
        $normalized = '100000000000'.'9';

        $hash = $service->hash($formatted);

        $this->assertSame($service->hash($normalized), $hash);
        $this->assertSame(64, strlen($hash));
        $this->assertStringNotContainsString($normalized, $hash);
    }

    public function test_provider_hash_is_keyed_again_after_provider_sha256(): void
    {
        $service = app(PersonnelIdentityService::class);
        $cid = '1000000000009';
        $providerSha256 = hash('sha256', $cid);
        $lookupHash = $service->hashProviderCidSha256($providerSha256);

        $this->assertSame($service->hashProviderCid($cid), $lookupHash);
        $this->assertSame(64, strlen($lookupHash));
        $this->assertNotSame($providerSha256, $lookupHash);
        $this->assertStringNotContainsString($cid, $lookupHash);
    }
}

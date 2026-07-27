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
}

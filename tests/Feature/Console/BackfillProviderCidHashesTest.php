<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Services\PersonnelIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSyntheticCid;
use Tests\TestCase;

class BackfillProviderCidHashesTest extends TestCase
{
    use CreatesSyntheticCid;
    use RefreshDatabase;

    public function test_command_validates_then_backfills_without_printing_cid(): void
    {
        $cid = $this->syntheticCid();
        $identity = app(PersonnelIdentityService::class);
        $user = User::factory()->create();
        $user->forceFill([
            'cid_hash' => $identity->hash($cid),
            'cid_encrypted' => $cid,
            'provider_cid_hash' => null,
        ])->save();

        $this->artisan('sso:backfill-provider-cid-hashes', [
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('validated 1 user record(s)')
            ->doesntExpectOutputToContain($cid)
            ->assertSuccessful();
        $this->assertNull($user->fresh()->provider_cid_hash);

        $this->artisan('sso:backfill-provider-cid-hashes')
            ->expectsOutputToContain('updated 1 user record(s)')
            ->doesntExpectOutputToContain($cid)
            ->assertSuccessful();

        $this->assertSame(
            $identity->hashProviderCid($cid),
            $user->fresh()->provider_cid_hash,
        );
    }
}

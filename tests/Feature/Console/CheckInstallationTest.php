<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckInstallationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.debug' => false,
            'app.url' => 'https://sso.example.test',
            'sso.cid_lookup_key' => str_repeat('c', 32),
            'sso.provider_cid_lookup_key' => str_repeat('p', 32),
            'sso.external_subject_lookup_key' => str_repeat('s', 32),
            'sso.transaction_hash_key' => str_repeat('t', 32),
            'sso.audit_hash_key' => str_repeat('a', 32),
        ]);
    }

    public function test_installation_check_passes_without_disabled_providers(): void
    {
        $this->artisan('sso:check-installation')
            ->expectsOutputToContain('Installation check passed.')
            ->assertSuccessful();
    }

    public function test_provider_check_fails_when_credentials_are_missing(): void
    {
        $this->artisan('sso:check-installation', ['--providers' => true])
            ->expectsOutputToContain('Installation check failed.')
            ->assertFailed();
    }

    public function test_provider_check_passes_when_both_credential_sets_exist(): void
    {
        config([
            'services.thaid.client_id' => 'test-client',
            'services.thaid.client_secret' => 'test-secret',
            'services.thaid.redirect_uri' => 'https://sso.example.test/sso/callback/thaid',
            'services.thaid.issuer' => 'https://imauth.bora.dopa.go.th',
            'services.thaid.authorization_url' => 'https://imauth.bora.dopa.go.th/auth',
            'services.thaid.token_url' => 'https://imauth.bora.dopa.go.th/token',
            'services.thaid.introspection_url' => 'https://imauth.bora.dopa.go.th/introspect',
            'services.thaid.revocation_url' => 'https://imauth.bora.dopa.go.th/revoke',
            'services.thaid.discovery_url' => 'https://imauth.bora.dopa.go.th/discovery',
            'services.moph_id.health_id.client_id' => 'test-health-client',
            'services.moph_id.health_id.client_secret' => 'test-health-secret',
            'services.moph_id.health_id.redirect_uri' => 'https://sso.example.test/moph/callback',
            'services.moph_id.health_id.base_url' => 'https://uat-moph.id.th',
            'services.moph_id.provider_id.client_id' => 'test-provider-client',
            'services.moph_id.provider_id.secret_key' => 'test-provider-secret',
            'services.moph_id.provider_id.base_url' => 'https://uat-provider.id.th',
        ]);

        $this->artisan('sso:check-installation', ['--providers' => true])
            ->expectsOutputToContain('Installation check passed.')
            ->assertSuccessful();
    }

    public function test_installation_check_rejects_reused_lookup_key(): void
    {
        config([
            'sso.provider_cid_lookup_key' => config('sso.cid_lookup_key'),
        ]);

        $this->artisan('sso:check-installation')
            ->expectsOutputToContain('Installation check failed.')
            ->assertFailed();
    }

    public function test_provider_check_rejects_mismatched_thaid_callback(): void
    {
        config([
            'services.thaid.enabled' => true,
            'services.thaid.client_id' => 'test-client',
            'services.thaid.client_secret' => 'test-secret',
            'services.thaid.redirect_uri' => 'https://sso.example.test/wrong',
            'services.thaid.issuer' => 'https://imauth.bora.dopa.go.th',
            'services.thaid.authorization_url' => 'https://imauth.bora.dopa.go.th/auth',
            'services.thaid.token_url' => 'https://imauth.bora.dopa.go.th/token',
            'services.thaid.introspection_url' => 'https://imauth.bora.dopa.go.th/introspect',
            'services.thaid.revocation_url' => 'https://imauth.bora.dopa.go.th/revoke',
            'services.thaid.discovery_url' => 'https://imauth.bora.dopa.go.th/discovery',
        ]);

        $this->artisan('sso:check-installation')
            ->expectsOutputToContain('ThaID callback URI')
            ->expectsOutputToContain('Installation check failed.')
            ->assertFailed();
    }

    public function test_installation_check_requires_provider_hash_backfill(): void
    {
        User::factory()->create([
            'cid_hash' => str_repeat('f', 64),
            'provider_cid_hash' => null,
        ]);

        $this->artisan('sso:check-installation')
            ->expectsOutputToContain('1 user record(s) require backfill')
            ->assertFailed();
    }
}

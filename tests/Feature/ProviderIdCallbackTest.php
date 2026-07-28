<?php

namespace Tests\Feature;

use App\Contracts\ProviderIdIdentityProvider;
use App\Data\VerifiedExternalIdentity;
use App\Enums\AuthenticationTransactionStatus;
use App\Enums\IdentityProvider;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\AuthenticationTransaction;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApplicationSsoClientService;
use App\Services\PersonnelIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Mockery;
use Tests\Concerns\CreatesSyntheticCid;
use Tests\TestCase;

class ProviderIdCallbackTest extends TestCase
{
    use CreatesSyntheticCid;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://sso.example.test',
            'sso.transaction_hash_key' => str_repeat('t', 32),
            'services.moph_id.enabled' => true,
            'services.moph_id.health_id.client_id' => 'health-client',
            'services.moph_id.health_id.redirect_uri' => 'https://sso.example.test/sso/callback/provider-id',
            'services.moph_id.health_id.base_url' => 'https://health.example.test',
            'services.moph_id.health_id.authorization_path' => '/oauth/redirect',
        ]);
    }

    public function test_verified_provider_identity_with_matching_organization_is_approved(): void
    {
        [$application, $client] = $this->oauthApplication();
        $cid = $this->syntheticCid();
        $user = $this->userWithCid($cid);
        $organization = Organization::factory()->create([
            'hcode' => 'HCODE001',
        ]);
        $grant = AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => $organization->id,
        ]);
        [$transaction, $state] = $this->selectProviderId($client);
        $provider = Mockery::mock(ProviderIdIdentityProvider::class);
        $provider->shouldReceive('authenticate')
            ->once()
            ->with('authorization-code-123')
            ->andReturn(VerifiedExternalIdentity::providerId(
                'provider-account-123',
                hash('sha256', $cid),
                ['HCODE001'],
            ));
        $this->app->instance(ProviderIdIdentityProvider::class, $provider);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'sobmoeiservice.moph.go.th',
            'HTTP_X_FORWARDED_PORT' => '443',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PREFIX' => '/call',
        ])->get(route('sso.callback.provider-id', [
            'code' => 'authorization-code-123',
            'state' => $state,
        ], false))->assertRedirect();

        $transaction->refresh();
        $this->assertSame(
            AuthenticationTransactionStatus::Approved,
            $transaction->status,
        );
        $this->assertTrue($transaction->user->is($user));
        $this->assertTrue($transaction->accessGrant->is($grant));
        $this->assertSame($organization->id, $transaction->organization_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('external_identities', 1);
        $this->assertSame(
            '/call/authorize',
            parse_url(
                (string) $response->headers->get('Location'),
                PHP_URL_PATH,
            ),
        );
    }

    public function test_provider_organization_not_granted_locally_is_denied(): void
    {
        [$application, $client] = $this->oauthApplication();
        $cid = $this->syntheticCid();
        $user = $this->userWithCid($cid);
        $organization = Organization::factory()->create([
            'hcode' => 'LOCAL001',
        ]);
        AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => $organization->id,
        ]);
        [$transaction, $state] = $this->selectProviderId($client);
        $provider = Mockery::mock(ProviderIdIdentityProvider::class);
        $provider->shouldReceive('authenticate')
            ->once()
            ->andReturn(VerifiedExternalIdentity::providerId(
                'provider-account-123',
                hash('sha256', $cid),
                ['OTHER001'],
            ));
        $this->app->instance(ProviderIdIdentityProvider::class, $provider);

        $this->get(route('sso.callback.provider-id', [
            'code' => 'authorization-code-123',
            'state' => $state,
        ], false))->assertForbidden();

        $this->assertSame(
            AuthenticationTransactionStatus::Denied,
            $transaction->fresh()->status,
        );
        $this->assertDatabaseCount('sso_subjects', 0);
    }

    public function test_unknown_provider_identity_is_denied_without_creating_user(): void
    {
        [, $client] = $this->oauthApplication();
        [$transaction, $state] = $this->selectProviderId($client);
        $provider = Mockery::mock(ProviderIdIdentityProvider::class);
        $provider->shouldReceive('authenticate')
            ->once()
            ->andReturn(VerifiedExternalIdentity::providerId(
                'unknown-provider-account',
                str_repeat('a', 64),
                ['HCODE001'],
            ));
        $this->app->instance(ProviderIdIdentityProvider::class, $provider);

        $this->get(route('sso.callback.provider-id', [
            'code' => 'authorization-code-123',
            'state' => $state,
        ], false))->assertForbidden();

        $this->assertSame(
            AuthenticationTransactionStatus::Denied,
            $transaction->fresh()->status,
        );
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('external_identities', 0);
    }

    public function test_invalid_state_does_not_exchange_code_or_mutate_transaction(): void
    {
        [, $client] = $this->oauthApplication();
        [$transaction] = $this->selectProviderId($client);
        $provider = Mockery::mock(ProviderIdIdentityProvider::class);
        $provider->shouldNotReceive('authenticate');
        $this->app->instance(ProviderIdIdentityProvider::class, $provider);

        $this->get(route('sso.callback.provider-id', [
            'code' => 'authorization-code-123',
            'state' => Str::random(64),
        ], false))->assertForbidden();

        $this->assertSame(
            AuthenticationTransactionStatus::ProviderSelected,
            $transaction->fresh()->status,
        );
    }

    public function test_replayed_provider_callback_does_not_exchange_code_twice(): void
    {
        [$application, $client] = $this->oauthApplication();
        $cid = $this->syntheticCid();
        $user = $this->userWithCid($cid);
        $organization = Organization::factory()->create([
            'hcode' => 'HCODE001',
        ]);
        AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => $organization->id,
        ]);
        [$transaction, $state] = $this->selectProviderId($client);
        $provider = Mockery::mock(ProviderIdIdentityProvider::class);
        $provider->shouldReceive('authenticate')
            ->once()
            ->andReturn(VerifiedExternalIdentity::providerId(
                'provider-account-123',
                hash('sha256', $cid),
                ['HCODE001'],
            ));
        $this->app->instance(ProviderIdIdentityProvider::class, $provider);
        $callback = '/sso/callback/provider-id?'.http_build_query([
            'code' => 'authorization-code-123',
            'state' => $state,
        ]);

        $this->get($callback)->assertRedirect();
        $this->get($callback)->assertForbidden();

        $this->assertSame(
            AuthenticationTransactionStatus::Approved,
            $transaction->fresh()->status,
        );
        $this->assertDatabaseCount('external_identities', 1);
    }

    /**
     * @return array{Application, Client}
     */
    private function oauthApplication(): array
    {
        $application = Application::factory()->create([
            'name' => 'Testsso',
            'require_organization_match' => true,
        ]);
        $result = app(ApplicationSsoClientService::class)->create(
            $application,
            ['https://client.example.test/callback'],
            ['provider_id'],
        );

        return [$application, $result['client']];
    }

    /**
     * @return array{AuthenticationTransaction, string}
     */
    private function selectProviderId(Client $client): array
    {
        $this->get('/authorize?'.http_build_query(
            $this->authorizationParameters($client),
        ))->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();
        $response = $this->post(
            route('sso.broker.select-provider', $transaction, false),
            ['provider' => IdentityProvider::ProviderId->value],
        )->assertRedirect();
        $query = [];
        parse_str(
            (string) parse_url(
                (string) $response->headers->get('Location'),
                PHP_URL_QUERY,
            ),
            $query,
        );
        $this->assertIsString($query['state'] ?? null);

        return [$transaction, $query['state']];
    }

    /**
     * @return array<string, string>
     */
    private function authorizationParameters(Client $client): array
    {
        return [
            'response_type' => 'code',
            'client_id' => (string) $client->getKey(),
            'redirect_uri' => 'https://client.example.test/callback',
            'scope' => 'openid profile organization roles',
            'state' => Str::random(64),
            'nonce' => Str::random(64),
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ];
    }

    private function userWithCid(string $cid): User
    {
        $user = User::factory()->create();
        app(PersonnelIdentityService::class)->setCid($user, $cid);
        $user->save();

        return $user;
    }
}

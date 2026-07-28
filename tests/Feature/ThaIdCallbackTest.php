<?php

namespace Tests\Feature;

use App\Contracts\ThaIdIdentityProvider;
use App\Data\VerifiedExternalIdentity;
use App\Enums\AuthenticationTransactionStatus;
use App\Enums\IdentityProvider;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\AuditLog;
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

class ThaIdCallbackTest extends TestCase
{
    use CreatesSyntheticCid;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://sso.example.test',
            'sso.transaction_hash_key' => str_repeat('t', 32),
            'services.thaid.enabled' => true,
            'services.thaid.client_id' => 'thaid-test-client',
            'services.thaid.authorization_url' => 'https://idp.example.test/oauth/authorize',
            'services.thaid.redirect_uri' => 'https://sso.example.test/sso/callback/thaid',
            'services.thaid.scopes' => 'pid name openid',
        ]);
    }

    public function test_verified_existing_user_with_one_grant_is_approved(): void
    {
        [$application, $client] = $this->oauthApplication();
        $cid = $this->syntheticCid();
        $user = $this->userWithCid($cid);
        $grant = AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
        ]);
        [$transaction, $state] = $this->selectThaId($client);
        $provider = Mockery::mock(ThaIdIdentityProvider::class);
        $provider->shouldReceive('authenticate')
            ->once()
            ->with('authorization-code-123')
            ->andReturn(VerifiedExternalIdentity::thaId(
                'verified-thaid-subject',
                $cid,
            ));
        $this->app->instance(ThaIdIdentityProvider::class, $provider);

        $callbackUri = route('sso.callback.thaid', [
            'code' => 'authorization-code-123',
            'state' => $state,
        ], false);
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'sobmoeiservice.moph.go.th',
            'HTTP_X_FORWARDED_PORT' => '443',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PREFIX' => '/call',
        ])->get($callbackUri)->assertRedirect();

        $transaction->refresh();
        $this->assertSame(
            AuthenticationTransactionStatus::Approved,
            $transaction->status,
        );
        $this->assertTrue($transaction->user->is($user));
        $this->assertTrue($transaction->accessGrant->is($grant));
        $this->assertSame($grant->organization_id, $transaction->organization_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('external_identities', 1);
        $this->assertDatabaseHas('sso_subjects', ['user_id' => $user->id]);
        $response->assertSessionHas(
            'sso.approved_transaction',
            $transaction->public_id,
        );
        $this->assertSame(
            '/call/authorize',
            parse_url(
                (string) $response->headers->get('Location'),
                PHP_URL_PATH,
            ),
        );
        $audit = AuditLog::query()
            ->where('action', 'sso.authorization_approved')
            ->sole();
        $this->assertSame('thaid', $audit->context['provider']);
        $this->assertArrayNotHasKey('cid', $audit->context);

        $this->withServerVariables([])
            ->get($callbackUri)
            ->assertForbidden();
        $this->assertSame(
            AuthenticationTransactionStatus::Approved,
            $transaction->fresh()->status,
        );
        $this->assertDatabaseCount('external_identities', 1);
    }

    public function test_unknown_verified_identity_is_denied_without_creating_user(): void
    {
        [, $client] = $this->oauthApplication();
        [$transaction, $state] = $this->selectThaId($client);
        $provider = Mockery::mock(ThaIdIdentityProvider::class);
        $provider->shouldReceive('authenticate')
            ->once()
            ->andReturn(VerifiedExternalIdentity::thaId(
                'unknown-subject',
                $this->syntheticCid(),
            ));
        $this->app->instance(ThaIdIdentityProvider::class, $provider);

        $this->get(route('sso.callback.thaid', [
            'code' => 'authorization-code-123',
            'state' => $state,
        ], false))->assertForbidden();

        $this->assertSame(
            AuthenticationTransactionStatus::Denied,
            $transaction->fresh()->status,
        );
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('external_identities', 0);
        $this->assertDatabaseCount('sso_subjects', 0);
        $audit = AuditLog::query()
            ->where('action', 'sso.authentication_denied')
            ->sole();
        $this->assertSame('identity_not_authorized', $audit->context['reason']);
        $this->assertStringNotContainsString(
            $this->syntheticCid(),
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_invalid_state_does_not_exchange_code_or_mutate_transaction(): void
    {
        [, $client] = $this->oauthApplication();
        [$transaction] = $this->selectThaId($client);
        $provider = Mockery::mock(ThaIdIdentityProvider::class);
        $provider->shouldNotReceive('authenticate');
        $this->app->instance(ThaIdIdentityProvider::class, $provider);

        $this->get(route('sso.callback.thaid', [
            'code' => 'authorization-code-123',
            'state' => Str::random(64),
        ], false))->assertForbidden();

        $this->assertSame(
            AuthenticationTransactionStatus::ProviderSelected,
            $transaction->fresh()->status,
        );
        $this->assertDatabaseCount('external_identities', 0);
    }

    public function test_multiple_eligible_organizations_require_exact_grant_selection(): void
    {
        [$application, $client] = $this->oauthApplication();
        $cid = $this->syntheticCid();
        $user = $this->userWithCid($cid);
        $firstOrganization = Organization::factory()->create([
            'name_th' => 'หน่วยงานทดสอบหนึ่ง',
        ]);
        $secondOrganization = Organization::factory()->create([
            'name_th' => 'หน่วยงานทดสอบสอง',
        ]);
        $firstGrant = AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => $firstOrganization->id,
        ]);
        AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => $secondOrganization->id,
        ]);
        [$transaction, $state] = $this->selectThaId($client);
        $provider = Mockery::mock(ThaIdIdentityProvider::class);
        $provider->shouldReceive('authenticate')
            ->once()
            ->andReturn(VerifiedExternalIdentity::thaId(
                'verified-multi-org-subject',
                $cid,
            ));
        $this->app->instance(ThaIdIdentityProvider::class, $provider);

        $this->get(route('sso.callback.thaid', [
            'code' => 'authorization-code-123',
            'state' => $state,
        ], false))
            ->assertOk()
            ->assertSee('หน่วยงานทดสอบหนึ่ง')
            ->assertSee('หน่วยงานทดสอบสอง')
            ->assertSessionHas(
                'sso.pending_organization_transaction',
                $transaction->public_id,
            );
        $this->assertSame(
            AuthenticationTransactionStatus::OrganizationRequired,
            $transaction->fresh()->status,
        );

        $response = $this->post(
            route('sso.broker.select-organization', $transaction, false),
            ['access_grant' => $firstGrant->public_id],
        )->assertRedirect();

        $transaction->refresh();
        $this->assertSame(
            AuthenticationTransactionStatus::Approved,
            $transaction->status,
        );
        $this->assertTrue($transaction->accessGrant->is($firstGrant));
        $this->assertSame($firstOrganization->id, $transaction->organization_id);
        $response->assertSessionHas(
            'sso.approved_transaction',
            $transaction->public_id,
        );
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
            ['thaid'],
        );

        return [$application, $result['client']];
    }

    /**
     * @return array{AuthenticationTransaction, string}
     */
    private function selectThaId(Client $client): array
    {
        $this->get('/authorize?'.http_build_query(
            $this->authorizationParameters($client),
        ))->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();
        $response = $this->post(
            route('sso.broker.select-provider', $transaction, false),
            ['provider' => IdentityProvider::ThaId->value],
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

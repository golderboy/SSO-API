<?php

namespace Tests\Feature;

use App\Enums\AuthenticationTransactionStatus;
use App\Enums\IdentityProvider;
use App\Http\Middleware\BrokerAuthorizationRequest;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\AuthenticationTransaction;
use App\Models\Organization;
use App\Models\SsoOAuthClient;
use App\Models\SsoSubject;
use App\Models\User;
use App\Services\ApplicationSsoClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Tests\TestCase;

class BrokerAuthorizationRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://sso.example.test',
            'sso.transaction_hash_key' => str_repeat('t', 32),
        ]);
    }

    public function test_authorization_request_creates_encrypted_browser_transaction(): void
    {
        [$application, $client] = $this->oauthApplication();
        $parameters = $this->authorizationParameters($client);

        $this->get('/authorize?'.http_build_query($parameters))
            ->assertOk()
            ->assertSee($application->name)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertHeader('X-Frame-Options', 'DENY');

        $transaction = AuthenticationTransaction::query()->sole();
        $stored = (string) DB::table('authentication_transactions')
            ->where('id', $transaction->id)
            ->value('downstream_request');

        $this->assertSame(
            AuthenticationTransactionStatus::Pending,
            $transaction->status,
        );
        $this->assertSame($parameters, $transaction->downstream_request);
        $this->assertStringNotContainsString($parameters['state'], $stored);
        $this->assertStringNotContainsString(
            $parameters['redirect_uri'],
            $stored,
        );
        $this->assertTrue(
            $transaction->expires_at->between(
                now()->addMinutes(4),
                now()->addMinutes(5)->addSecond(),
            ),
        );
    }

    public function test_invalid_or_unregistered_requests_create_no_transaction(): void
    {
        [, $client] = $this->oauthApplication();
        $valid = $this->authorizationParameters($client);

        foreach ([
            array_merge($valid, ['redirect_uri' => 'https://evil.example.test/callback']),
            array_merge($valid, ['redirect_uri' => 'http://client.example.test/callback']),
            array_merge($valid, ['code_challenge_method' => 'plain']),
            array_merge($valid, ['code_challenge' => 'too-short']),
            array_merge($valid, ['state' => 'too-short']),
            array_merge($valid, ['scope' => 'openid unknown']),
            array_merge($valid, ['unexpected' => 'value']),
        ] as $parameters) {
            $this->get('/authorize?'.http_build_query($parameters))
                ->assertBadRequest();
        }

        $this->assertDatabaseCount('authentication_transactions', 0);
    }

    public function test_provider_form_preserves_public_call_prefix_behind_loopback_proxy(): void
    {
        [, $client] = $this->oauthApplication();

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'sobmoeiservice.moph.go.th',
            'HTTP_X_FORWARDED_PORT' => '443',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PREFIX' => '/call',
        ])->get('/authorize?'.http_build_query(
            $this->authorizationParameters($client),
        ))->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();

        $response->assertSee(
            "https://sobmoeiservice.moph.go.th/call/broker/transactions/{$transaction->public_id}/provider",
            false,
        );
    }

    public function test_inactive_application_and_revoked_client_are_rejected(): void
    {
        [$application, $client] = $this->oauthApplication();
        $parameters = $this->authorizationParameters($client);
        $application->update(['is_active' => false]);

        $this->get('/authorize?'.http_build_query($parameters))
            ->assertUnauthorized();

        $application->update(['is_active' => true]);
        $client->update(['revoked' => true]);

        $this->get('/authorize?'.http_build_query($parameters))
            ->assertUnauthorized();
        $this->assertDatabaseCount('authentication_transactions', 0);
    }

    public function test_thaid_selection_redirects_with_state_and_stores_only_hash(): void
    {
        config([
            'services.thaid.enabled' => true,
            'services.thaid.client_id' => 'thaid-test-client',
            'services.thaid.authorization_url' => 'https://idp.example.test/oauth/authorize',
            'services.thaid.redirect_uri' => 'https://sso.example.test/sso/callback/thaid',
            'services.thaid.scopes' => 'pid name openid',
        ]);
        [, $client] = $this->oauthApplication();
        $this->get('/authorize?'.http_build_query(
            $this->authorizationParameters($client),
        ))->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();

        $response = $this->post(
            route('sso.broker.select-provider', $transaction, false),
            ['provider' => IdentityProvider::ThaId->value],
        )->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $query = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame(
            'https://idp.example.test/oauth/authorize',
            strtok($location, '?'),
        );
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('thaid-test-client', $query['client_id']);
        $this->assertSame(
            'https://sso.example.test/sso/callback/thaid',
            $query['redirect_uri'],
        );
        $this->assertSame('pid name openid', $query['scope']);
        $this->assertIsString($query['state']);
        $this->assertSame(64, strlen($query['state']));

        $transaction->refresh();
        $this->assertSame(
            AuthenticationTransactionStatus::ProviderSelected,
            $transaction->status,
        );
        $this->assertSame(IdentityProvider::ThaId, $transaction->selected_provider);
        $this->assertSame(
            hash_hmac('sha256', $query['state'], str_repeat('t', 32)),
            $transaction->upstream_state_hash,
        );
        $this->assertDatabaseMissing('authentication_transactions', [
            'upstream_state_hash' => $query['state'],
        ]);
    }

    public function test_disabled_provider_does_not_change_transaction_state(): void
    {
        [, $client] = $this->oauthApplication();
        $this->get('/authorize?'.http_build_query(
            $this->authorizationParameters($client),
        ))->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();

        $this->post(
            route('sso.broker.select-provider', $transaction, false),
            ['provider' => IdentityProvider::ThaId->value],
        )->assertServiceUnavailable();

        $this->assertSame(
            AuthenticationTransactionStatus::Pending,
            $transaction->fresh()->status,
        );
        $this->assertNull($transaction->fresh()->selected_provider);
    }

    public function test_provider_id_selection_uses_health_id_authorization_endpoint(): void
    {
        config([
            'services.moph_id.enabled' => true,
            'services.moph_id.health_id.client_id' => 'health-test-client',
            'services.moph_id.health_id.base_url' => 'https://health.example.test',
            'services.moph_id.health_id.authorization_path' => '/oauth/redirect',
            'services.moph_id.health_id.redirect_uri' => 'https://sso.example.test/sso/callback/provider-id',
        ]);
        [, $client] = $this->oauthApplication();
        $this->get('/authorize?'.http_build_query(
            $this->authorizationParameters($client),
        ))->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();

        $response = $this->post(
            route('sso.broker.select-provider', $transaction, false),
            ['provider' => IdentityProvider::ProviderId->value],
        )->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $query = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame(
            'https://health.example.test/oauth/redirect',
            strtok($location, '?'),
        );
        $this->assertIsString($query['state'] ?? null);
        $this->assertSame(64, strlen($query['state']));
        $this->assertSame([
            'client_id' => 'health-test-client',
            'redirect_uri' => 'https://sso.example.test/sso/callback/provider-id',
            'response_type' => 'code',
            'state' => $query['state'],
        ], $query);
        $this->assertSame(
            AuthenticationTransactionStatus::ProviderSelected,
            $transaction->fresh()->status,
        );
        $this->assertSame(
            hash_hmac(
                'sha256',
                $query['state'],
                str_repeat('t', 32),
            ),
            $transaction->fresh()->upstream_state_hash,
        );
    }

    public function test_provider_not_allowed_for_application_is_rejected(): void
    {
        [, $client] = $this->oauthApplication(['thaid']);
        $this->get('/authorize?'.http_build_query(
            $this->authorizationParameters($client),
        ))->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();

        $this->post(
            route('sso.broker.select-provider', $transaction, false),
            ['provider' => IdentityProvider::ProviderId->value],
        )->assertForbidden();

        $this->assertSame(
            AuthenticationTransactionStatus::Pending,
            $transaction->fresh()->status,
        );
    }

    public function test_transaction_cannot_be_selected_from_a_different_browser_session(): void
    {
        config([
            'services.thaid.enabled' => true,
            'services.thaid.client_id' => 'thaid-test-client',
            'services.thaid.authorization_url' => 'https://idp.example.test/oauth/authorize',
            'services.thaid.redirect_uri' => 'https://sso.example.test/sso/callback/thaid',
            'services.thaid.scopes' => 'pid name openid',
        ]);
        [, $client] = $this->oauthApplication();
        $this->get('/authorize?'.http_build_query(
            $this->authorizationParameters($client),
        ))->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();
        $transaction->forceFill([
            'browser_session_hash' => str_repeat('f', 64),
        ])->save();

        $this->post(
            route('sso.broker.select-provider', $transaction, false),
            ['provider' => IdentityProvider::ThaId->value],
        )->assertForbidden();

        $this->assertSame(
            AuthenticationTransactionStatus::Pending,
            $transaction->fresh()->status,
        );
    }

    public function test_approved_transaction_issues_code_once_and_is_consumed(): void
    {
        [$application, $client] = $this->oauthApplication();
        $parameters = $this->authorizationParameters($client);
        $this->get('/authorize?'.http_build_query($parameters))->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();
        $this->approveTransaction($transaction, $application);

        $response = $this->withSession([
            'sso.approved_transaction' => $transaction->public_id,
        ])->get('/authorize?'.http_build_query($parameters))
            ->assertRedirect();
        $query = [];
        parse_str(
            (string) parse_url(
                (string) $response->headers->get('Location'),
                PHP_URL_QUERY,
            ),
            $query,
        );

        $this->assertIsString($query['code'] ?? null);
        $this->assertNotSame('', $query['code']);
        $this->assertSame($parameters['state'], $query['state']);
        $this->assertSame(
            AuthenticationTransactionStatus::Consumed,
            $transaction->fresh()->status,
        );
        $this->assertNotNull($transaction->fresh()->consumed_at);
        $this->assertDatabaseCount('oauth_auth_codes', 1);
        $response->assertSessionMissing('sso.approved_transaction');

        $this->withSession([
            'sso.approved_transaction' => $transaction->public_id,
        ])->get('/authorize?'.http_build_query($parameters))
            ->assertForbidden()
            ->assertSessionMissing('sso.approved_transaction');
        $this->assertDatabaseCount('oauth_auth_codes', 1);
    }

    public function test_revoked_grant_blocks_code_after_upstream_approval(): void
    {
        [$application, $client] = $this->oauthApplication();
        $parameters = $this->authorizationParameters($client);
        $this->get('/authorize?'.http_build_query($parameters))->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();
        $grant = $this->approveTransaction($transaction, $application);
        $grant->update(['revoked_at' => now()]);

        $this->withSession([
            'sso.approved_transaction' => $transaction->public_id,
        ])->get('/authorize?'.http_build_query($parameters))
            ->assertForbidden()
            ->assertSessionMissing('sso.approved_transaction');

        $this->assertSame(
            AuthenticationTransactionStatus::Approved,
            $transaction->fresh()->status,
        );
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_error_redirect_denies_claimed_transaction_without_consuming_it(): void
    {
        Route::middleware(['web', BrokerAuthorizationRequest::class])
            ->get('/_test/broker-error', function () {
                $state = (string) request()->query('state');

                return redirect(
                    'https://client.example.test/callback?'.http_build_query([
                        'error' => 'server_error',
                        'state' => $state,
                    ]),
                );
            });
        [$application, $client] = $this->oauthApplication();
        $parameters = $this->authorizationParameters($client);
        $endpoint = '/_test/broker-error?'.http_build_query($parameters);
        $this->get($endpoint)->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();
        $this->approveTransaction($transaction, $application);

        $this->withSession([
            'sso.approved_transaction' => $transaction->public_id,
        ])->get($endpoint)
            ->assertRedirect()
            ->assertSessionMissing('sso.approved_transaction');

        $transaction->refresh();
        $this->assertSame(
            AuthenticationTransactionStatus::Denied,
            $transaction->status,
        );
        $this->assertNull($transaction->consumed_at);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_changed_downstream_request_cannot_reuse_approval(): void
    {
        [$application, $client] = $this->oauthApplication();
        $parameters = $this->authorizationParameters($client);
        $this->get('/authorize?'.http_build_query($parameters))->assertOk();
        $transaction = AuthenticationTransaction::query()->sole();
        $this->approveTransaction($transaction, $application);
        $parameters['state'] = Str::random(64);

        $this->withSession([
            'sso.approved_transaction' => $transaction->public_id,
        ])->get('/authorize?'.http_build_query($parameters))
            ->assertForbidden()
            ->assertSessionMissing('sso.approved_transaction');

        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_oauth_client_never_skips_authorization_without_broker_approval(): void
    {
        $client = new SsoOAuthClient;
        $subject = new SsoSubject;

        $this->assertFalse($client->skipsAuthorization($subject, []));

        request()->attributes->set('sso_broker_approved', true);

        $this->assertTrue($client->skipsAuthorization($subject, []));
    }

    public function test_passport_consent_fallback_is_bound_and_fails_closed(): void
    {
        $response = app(AuthorizationViewResponse::class)
            ->withParameters([])
            ->toResponse(request());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString(
            'access_denied',
            (string) $response->getContent(),
        );
    }

    /**
     * @param  list<string>  $allowedProviders
     * @return array{Application, Client}
     */
    private function oauthApplication(
        array $allowedProviders = ['thaid', 'provider_id'],
    ): array {
        $application = Application::factory()->create([
            'name' => 'Testsso',
            'require_organization_match' => true,
        ]);
        $result = app(ApplicationSsoClientService::class)->create(
            $application,
            ['https://client.example.test/callback'],
            $allowedProviders,
        );

        return [$application, $result['client']];
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

    private function approveTransaction(
        AuthenticationTransaction $transaction,
        Application $application,
    ): AccessGrant {
        $user = User::factory()->create();
        SsoSubject::query()->create(['user_id' => $user->id]);
        $organization = Organization::factory()->create();
        $grant = AccessGrant::factory()->create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => $organization->id,
        ]);
        $transaction->forceFill([
            'selected_provider' => IdentityProvider::ThaId,
            'status' => AuthenticationTransactionStatus::Approved,
            'user_id' => $user->id,
            'access_grant_id' => $grant->id,
            'organization_id' => $organization->id,
            'authenticated_at' => now(),
        ])->save();

        return $grant;
    }
}

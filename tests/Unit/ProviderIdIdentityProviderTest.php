<?php

namespace Tests\Unit;

use App\Exceptions\UpstreamAuthenticationException;
use App\Services\ProviderIdIdentityProvider;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class ProviderIdIdentityProviderTest extends TestCase
{
    private OpenSSLAsymmetricKey $privateKey;

    private string $publicKey;

    private string $issuedProviderToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureProvider();
        Cache::clear();
        Http::preventStrayRequests();
        $this->privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $details = openssl_pkey_get_details($this->privateKey);
        $this->assertIsArray($details);
        $this->publicKey = $details['key'];
    }

    public function test_exchanges_health_token_and_returns_verified_provider_identity(): void
    {
        $this->fakeSuccessfulProvider();

        $identity = app(ProviderIdIdentityProvider::class)
            ->authenticate('health-authorization-code');

        $this->assertSame('provider-account-123', $identity->subject);
        $this->assertSame(
            str_repeat('a', 64),
            $identity->identityMatchValue,
        );
        $this->assertSame(
            ['HCODE001', 'HCODE002'],
            $identity->organizationHcodes,
        );
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://health.example.test/api/v1/token'
                && $request->method() === 'POST'
                && $request->isForm()
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'health-authorization-code'
                && $request['redirect_uri']
                    === 'https://sso.example.test/sso/callback/provider-id'
                && $request['client_id'] === 'health-client'
                && $request['client_secret'] === 'health-secret';
        });
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://provider.example.test/api/v1/services/token'
                && $request->method() === 'POST'
                && $request['token_by'] === 'Health ID'
                && $request['token'] === 'health-access-token'
                && $request['client_id'] === 'provider-client'
                && $request['secret_key'] === 'provider-secret';
        });
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://provider.example.test/api/v1/services/profile'
                && $request->method() === 'GET'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer '.$this->issuedProviderToken,
                )
                && $request->hasHeader('client-id', 'provider-client')
                && $request->hasHeader('secret-key', 'provider-secret');
        });
    }

    public function test_rejects_mismatched_health_and_provider_accounts(): void
    {
        $this->fakeSuccessfulProvider(
            providerAccountId: 'different-account',
        );

        try {
            app(ProviderIdIdentityProvider::class)
                ->authenticate('health-authorization-code');
            $this->fail('Mismatched upstream accounts must be rejected.');
        } catch (UpstreamAuthenticationException $exception) {
            $this->assertSame(
                'provider_identity_mismatch',
                $exception->reason,
            );
        }

        Http::assertNotSent(
            fn (Request $request): bool => str_ends_with(
                $request->url(),
                '/services/profile',
            ),
        );
    }

    public function test_rejects_provider_token_signed_by_an_untrusted_key(): void
    {
        $attackerKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $attackerKey);
        $this->fakeSuccessfulProvider(signingKey: $attackerKey);

        try {
            app(ProviderIdIdentityProvider::class)
                ->authenticate('health-authorization-code');
            $this->fail('An untrusted Provider ID token must be rejected.');
        } catch (UpstreamAuthenticationException $exception) {
            $this->assertSame(
                'provider_token_signature_invalid',
                $exception->reason,
            );
            $this->assertStringNotContainsString(
                'health-access-token',
                $exception->getMessage(),
            );
        }
    }

    public function test_refreshes_cached_public_key_once_during_key_rotation(): void
    {
        $oldKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $oldKey);
        $oldDetails = openssl_pkey_get_details($oldKey);
        $this->assertIsArray($oldDetails);
        $this->fakeSuccessfulProvider(
            publicKeyResponse: Http::sequence()
                ->push(
                    $oldDetails['key'],
                    200,
                    ['Content-Type' => 'text/plain'],
                )
                ->push(
                    $this->publicKey,
                    200,
                    ['Content-Type' => 'text/plain'],
                ),
        );

        $identity = app(ProviderIdIdentityProvider::class)
            ->authenticate('health-authorization-code');

        $this->assertSame('provider-account-123', $identity->subject);
        Http::assertSentCount(5);
    }

    public function test_rejects_redirected_or_non_plaintext_public_key_response(): void
    {
        $this->fakeSuccessfulProvider(
            publicKeyResponse: Http::response(
                '<html>redirected</html>',
                302,
                ['Location' => 'https://attacker.example.test/key'],
            ),
        );

        try {
            app(ProviderIdIdentityProvider::class)
                ->authenticate('health-authorization-code');
            $this->fail('A redirected public key response must be rejected.');
        } catch (UpstreamAuthenticationException $exception) {
            $this->assertSame(
                'provider_public_key_invalid',
                $exception->reason,
            );
        }
    }

    public function test_rejects_profile_without_valid_organization_hcodes(): void
    {
        $this->fakeSuccessfulProvider(organizations: []);

        try {
            app(ProviderIdIdentityProvider::class)
                ->authenticate('health-authorization-code');
            $this->fail('A profile without organization hcodes must fail closed.');
        } catch (UpstreamAuthenticationException $exception) {
            $this->assertSame('provider_profile_invalid', $exception->reason);
        }
    }

    public function test_rejects_mismatched_callback_configuration_before_exchange(): void
    {
        config([
            'services.moph_id.health_id.redirect_uri' => 'https://sso.example.test/wrong-callback',
        ]);
        Http::fake();

        try {
            app(ProviderIdIdentityProvider::class)
                ->authenticate('health-authorization-code');
            $this->fail('A mismatched callback must be rejected.');
        } catch (UpstreamAuthenticationException $exception) {
            $this->assertSame(
                'provider_configuration_invalid',
                $exception->reason,
            );
        }

        Http::assertNothingSent();
    }

    /**
     * @param  list<array{hcode: string}>  $organizations
     */
    private function fakeSuccessfulProvider(
        string $providerAccountId = 'provider-account-123',
        ?OpenSSLAsymmetricKey $signingKey = null,
        mixed $publicKeyResponse = null,
        array $organizations = [
            ['hcode' => 'HCODE001'],
            ['hcode' => 'HCODE002'],
        ],
    ): void {
        $providerToken = $this->providerToken(
            $signingKey,
            $providerAccountId,
        );
        $this->issuedProviderToken = $providerToken;
        Http::fake([
            'https://health.example.test/api/v1/token' => Http::response([
                'status' => 'success',
                'data' => [
                    'access_token' => 'health-access-token',
                    'token_type' => 'Bearer',
                    'expires_in' => 900,
                    'account_id' => 'provider-account-123',
                ],
                'message' => 'You logged in successfully',
            ], 200, ['Content-Type' => 'application/json']),
            'https://provider.example.test/api/v1/services/token' => Http::response([
                'status' => 200,
                'message' => 'OK',
                'data' => [
                    'token_type' => 'Bearer',
                    'expires_in' => 900,
                    'access_token' => $providerToken,
                    'account_id' => $providerAccountId,
                    'result' => 'Success',
                    'login_by' => 'access_token_health_id',
                ],
            ], 200, ['Content-Type' => 'application/json']),
            'https://provider.example.test/api/v1/services/public-key' => $publicKeyResponse ?? Http::response(
                $this->publicKey,
                200,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            ),
            'https://provider.example.test/api/v1/services/profile' => Http::response([
                'status' => 200,
                'message' => 'OK',
                'data' => [
                    'account_id' => $providerAccountId,
                    'hash_cid' => str_repeat('a', 64),
                    'provider_id' => 'provider-number',
                    'organization' => $organizations,
                ],
            ], 200, ['Content-Type' => 'application/json']),
        ]);
    }

    private function providerToken(
        ?OpenSSLAsymmetricKey $key = null,
        string $accountId = 'provider-account-123',
    ): string {
        return JWT::encode([
            'account_id' => $accountId,
            'iat' => time(),
            'exp' => time() + 900,
        ], $key ?? $this->privateKey, 'RS256');
    }

    private function configureProvider(): void
    {
        config([
            'app.url' => 'https://sso.example.test',
            'services.moph_id.enabled' => true,
            'services.moph_id.clock_skew_seconds' => 60,
            'services.moph_id.public_key_cache_seconds' => 300,
            'services.moph_id.health_id.client_id' => 'health-client',
            'services.moph_id.health_id.client_secret' => 'health-secret',
            'services.moph_id.health_id.redirect_uri' => 'https://sso.example.test/sso/callback/provider-id',
            'services.moph_id.health_id.base_url' => 'https://health.example.test',
            'services.moph_id.health_id.token_path' => '/api/v1/token',
            'services.moph_id.provider_id.client_id' => 'provider-client',
            'services.moph_id.provider_id.secret_key' => 'provider-secret',
            'services.moph_id.provider_id.base_url' => 'https://provider.example.test',
            'services.moph_id.provider_id.token_path' => '/api/v1/services/token',
            'services.moph_id.provider_id.profile_path' => '/api/v1/services/profile',
            'services.moph_id.provider_id.public_key_path' => '/api/v1/services/public-key',
            'services.upstream_http.connect_timeout_seconds' => 5,
            'services.upstream_http.timeout_seconds' => 15,
        ]);
    }
}

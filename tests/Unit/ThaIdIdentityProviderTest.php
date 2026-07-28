<?php

namespace Tests\Unit;

use App\Exceptions\UpstreamAuthenticationException;
use App\Services\ThaIdIdentityProvider;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\Concerns\CreatesSyntheticCid;
use Tests\TestCase;

class ThaIdIdentityProviderTest extends TestCase
{
    use CreatesSyntheticCid;

    private OpenSSLAsymmetricKey $privateKey;

    /**
     * @var array<string, mixed>
     */
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureProvider();
        [$this->privateKey, $this->jwk] = $this->ecKeyPair('uat-key-1');
        Cache::clear();
        Http::preventStrayRequests();
    }

    public function test_exchanges_code_and_returns_only_verified_thaid_identity(): void
    {
        $cid = $this->syntheticCid();
        $this->fakeSuccessfulProvider($cid, 'stable-subject');

        $identity = app(ThaIdIdentityProvider::class)
            ->authenticate('authorization-code-123');

        $this->assertSame('thaid', $identity->provider->value);
        $this->assertSame('stable-subject', $identity->subject);
        $this->assertSame($cid, $identity->identityMatchValue);
        Http::assertSentCount(4);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === config('services.thaid.token_url')
                && $request->method() === 'POST'
                && $request->isForm()
                && $request->hasHeader(
                    'Authorization',
                    'Basic '.base64_encode(
                        config('services.thaid.client_id').':'
                            .config('services.thaid.client_secret'),
                    ),
                )
                && $request->data() === [
                    'grant_type' => 'authorization_code',
                    'code' => 'authorization-code-123',
                    'redirect_uri' => config(
                        'services.thaid.redirect_uri',
                    ),
                ];
        });
        Http::assertSent(function (Request $request): bool {
            return $request->url()
                    === config('services.thaid.introspection_url')
                && $request->data() === [
                    'token' => 'Bearer access-token-value',
                ];
        });
    }

    public function test_rejects_untrusted_id_token_claims(): void
    {
        $cid = $this->syntheticCid();

        foreach ([
            ['iss' => 'https://attacker.example.test'],
            ['aud' => 'different-client'],
            ['at_hash' => 'invalid-access-token-hash'],
            ['pid' => '1000000000000'],
        ] as $overrides) {
            Cache::clear();
            $this->fakeSuccessfulProvider(
                $cid,
                'stable-subject',
                $overrides,
            );

            try {
                app(ThaIdIdentityProvider::class)
                    ->authenticate('authorization-code-123');
                $this->fail('Untrusted ID Token claims must be rejected.');
            } catch (UpstreamAuthenticationException $exception) {
                $this->assertContains($exception->reason, [
                    'id_token_claims_invalid',
                    'identity_claims_invalid',
                ]);
                $this->assertStringNotContainsString(
                    $cid,
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_rejects_inactive_or_subject_mismatched_introspection(): void
    {
        $cid = $this->syntheticCid();

        foreach ([
            ['active' => false, 'sub' => 'stable-subject', 'scope' => 'pid name'],
            ['active' => true, 'sub' => 'different-subject', 'scope' => 'pid name'],
        ] as $introspection) {
            Cache::clear();
            $this->fakeSuccessfulProvider(
                $cid,
                'stable-subject',
                [],
                $introspection,
            );

            try {
                app(ThaIdIdentityProvider::class)
                    ->authenticate('authorization-code-123');
                $this->fail('Invalid introspection must be rejected.');
            } catch (UpstreamAuthenticationException $exception) {
                $this->assertSame(
                    'introspection_invalid',
                    $exception->reason,
                );
            }
        }
    }

    public function test_rejects_signature_from_unknown_key_without_leaking_token(): void
    {
        $cid = $this->syntheticCid();
        [$attackerKey] = $this->ecKeyPair('attacker-key');
        $this->fakeSuccessfulProvider(
            $cid,
            'stable-subject',
            [],
            null,
            $attackerKey,
            'attacker-key',
        );

        try {
            app(ThaIdIdentityProvider::class)
                ->authenticate('authorization-code-123');
            $this->fail('An unknown signing key must be rejected.');
        } catch (UpstreamAuthenticationException $exception) {
            $this->assertSame('id_token_key_unknown', $exception->reason);
            $this->assertStringNotContainsString(
                'access-token-value',
                $exception->getMessage(),
            );
        }
    }

    public function test_requires_json_and_does_not_follow_provider_redirects(): void
    {
        Http::fake([
            config('services.thaid.token_url') => Http::response(
                '<html>redirected</html>',
                302,
                ['Location' => 'https://attacker.example.test/token'],
            ),
        ]);

        $this->expectException(UpstreamAuthenticationException::class);

        app(ThaIdIdentityProvider::class)
            ->authenticate('authorization-code-123');
    }

    public function test_rejects_malformed_discovery_metadata_safely(): void
    {
        $this->fakeSuccessfulProvider(
            $this->syntheticCid(),
            'stable-subject',
            [],
            null,
            null,
            'uat-key-1',
            ['id_token_signing_alg_values_supported' => 'ES256'],
        );

        try {
            app(ThaIdIdentityProvider::class)
                ->authenticate('authorization-code-123');
            $this->fail('Malformed discovery metadata must be rejected.');
        } catch (UpstreamAuthenticationException $exception) {
            $this->assertSame('discovery_invalid', $exception->reason);
        }
    }

    /**
     * @param  array<string, mixed>  $claimOverrides
     * @param  array<string, mixed>|null  $introspection
     * @param  array<string, mixed>  $discoveryOverrides
     */
    private function fakeSuccessfulProvider(
        string $cid,
        string $subject,
        array $claimOverrides = [],
        ?array $introspection = null,
        ?OpenSSLAsymmetricKey $signingKey = null,
        string $keyId = 'uat-key-1',
        array $discoveryOverrides = [],
    ): void {
        $accessToken = 'access-token-value';
        $claims = array_merge([
            'iss' => config('services.thaid.issuer'),
            'aud' => config('services.thaid.client_id'),
            'exp' => time() + 900,
            'iat' => time(),
            'sub' => $subject,
            'pid' => $cid,
            'at_hash' => JWT::urlsafeB64Encode(
                substr(hash('sha256', $accessToken, true), 0, 16),
            ),
        ], $claimOverrides);
        $idToken = JWT::encode(
            $claims,
            $signingKey ?? $this->privateKey,
            'ES256',
            $keyId,
        );

        Http::fake([
            config('services.thaid.token_url') => Http::response([
                'expire_in' => time() + 900,
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'scope' => 'pid name openid',
                'id_token' => $idToken,
            ], 200, ['Content-Type' => 'application/json']),
            config('services.thaid.discovery_url') => Http::response(
                array_merge([
                    'issuer' => config('services.thaid.issuer'),
                    'token_endpoint' => config(
                        'services.thaid.token_url',
                    ),
                    'jwks_uri' => 'https://idp.example.test/jwks/',
                    'id_token_signing_alg_values_supported' => ['ES256'],
                ], $discoveryOverrides),
                200,
                ['Content-Type' => 'application/json'],
            ),
            'https://idp.example.test/jwks/' => Http::response([
                'keys' => [$this->jwk],
            ], 200, ['Content-Type' => 'application/json']),
            config('services.thaid.introspection_url') => Http::response(
                $introspection ?? [
                    'active' => true,
                    'sub' => $subject,
                    'scope' => 'pid name',
                ],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);
    }

    /**
     * @return array{OpenSSLAsymmetricKey, array<string, mixed>}
     */
    private function ecKeyPair(string $keyId): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $privateKey);
        $details = openssl_pkey_get_details($privateKey);
        $this->assertIsArray($details);
        $this->assertIsArray($details['ec'] ?? null);
        $this->assertIsString($details['ec']['x'] ?? null);
        $this->assertIsString($details['ec']['y'] ?? null);

        return [
            $privateKey,
            [
                'kty' => 'EC',
                'use' => 'sig',
                'crv' => 'P-256',
                'alg' => 'ES256',
                'kid' => $keyId,
                'x' => JWT::urlsafeB64Encode($details['ec']['x']),
                'y' => JWT::urlsafeB64Encode($details['ec']['y']),
            ],
        ];
    }

    private function configureProvider(): void
    {
        config([
            'services.thaid.enabled' => true,
            'services.thaid.client_id' => 'thaid-test-client',
            'services.thaid.client_secret' => 'thaid-test-secret',
            'services.thaid.redirect_uri' => 'https://sso.example.test/sso/callback/thaid',
            'services.thaid.issuer' => 'https://idp.example.test',
            'services.thaid.token_url' => 'https://idp.example.test/token/',
            'services.thaid.introspection_url' => 'https://idp.example.test/introspect/',
            'services.thaid.discovery_url' => 'https://idp.example.test/.well-known/openid-configuration',
            'services.thaid.clock_skew_seconds' => 60,
            'services.thaid.discovery_cache_seconds' => 300,
            'services.thaid.jwks_cache_seconds' => 300,
            'services.upstream_http.connect_timeout_seconds' => 5,
            'services.upstream_http.timeout_seconds' => 15,
        ]);
    }
}

<?php

namespace App\Services;

use App\Contracts\ProviderIdIdentityProvider as ProviderIdIdentityProviderContract;
use App\Data\VerifiedExternalIdentity;
use App\Exceptions\UpstreamAuthenticationException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use stdClass;
use Throwable;

class ProviderIdIdentityProvider implements ProviderIdIdentityProviderContract
{
    private const MAX_JSON_BYTES = 1_048_576;

    private const MAX_KEY_BYTES = 16_384;

    private const MAX_TOKEN_BYTES = 32_768;

    public function authenticate(string $authorizationCode): VerifiedExternalIdentity
    {
        if (config('services.moph_id.enabled') !== true) {
            throw new UpstreamAuthenticationException('provider_disabled');
        }

        if (
            strlen($authorizationCode) < 8
            || strlen($authorizationCode) > 2048
            || preg_match('/[\x00-\x20\x7F]/', $authorizationCode) === 1
        ) {
            throw new UpstreamAuthenticationException(
                'authorization_code_invalid',
            );
        }

        $healthTokenResponse = $this->exchangeHealthIdCode(
            $authorizationCode,
        );
        $healthData = $this->envelopeData(
            $healthTokenResponse,
            'health_token_response_invalid',
            true,
        );
        $healthAccessToken = $this->requiredToken(
            $healthData,
            'access_token',
            'health_token_response_invalid',
        );
        $healthAccountId = $this->requiredIdentifier(
            $healthData,
            'account_id',
            'health_token_response_invalid',
        );
        $this->assertBearerAndExpiry(
            $healthData,
            'health_token_response_invalid',
        );

        $providerTokenResponse = $this->exchangeProviderIdToken(
            $healthAccessToken,
        );
        $providerData = $this->envelopeData(
            $providerTokenResponse,
            'provider_token_response_invalid',
        );
        $providerAccessToken = $this->requiredToken(
            $providerData,
            'access_token',
            'provider_token_response_invalid',
        );
        $providerAccountId = $this->requiredIdentifier(
            $providerData,
            'account_id',
            'provider_token_response_invalid',
        );
        $this->assertBearerAndExpiry(
            $providerData,
            'provider_token_response_invalid',
        );

        if (! hash_equals($healthAccountId, $providerAccountId)) {
            throw new UpstreamAuthenticationException(
                'provider_identity_mismatch',
            );
        }

        $this->verifyProviderToken($providerAccessToken, $providerAccountId);
        $profileResponse = $this->providerProfile($providerAccessToken);
        $profile = $this->envelopeData(
            $profileResponse,
            'provider_profile_invalid',
        );
        $profileAccountId = $this->requiredIdentifier(
            $profile,
            'account_id',
            'provider_profile_invalid',
        );

        if (! hash_equals($providerAccountId, $profileAccountId)) {
            throw new UpstreamAuthenticationException(
                'provider_identity_mismatch',
            );
        }

        try {
            return VerifiedExternalIdentity::providerId(
                $profileAccountId,
                $this->requiredProviderCidHash($profile),
                $this->organizationHcodes($profile),
            );
        } catch (\InvalidArgumentException) {
            throw new UpstreamAuthenticationException(
                'provider_profile_invalid',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeHealthIdCode(string $authorizationCode): array
    {
        return $this->postForm(
            $this->endpoint(
                'services.moph_id.health_id.base_url',
                'services.moph_id.health_id.token_path',
            ),
            [
                'grant_type' => 'authorization_code',
                'code' => $authorizationCode,
                'redirect_uri' => $this->redirectUriConfig(),
                'client_id' => $this->stringConfig(
                    'services.moph_id.health_id.client_id',
                ),
                'client_secret' => $this->stringConfig(
                    'services.moph_id.health_id.client_secret',
                ),
            ],
            'health_token_exchange_failed',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeProviderIdToken(string $healthAccessToken): array
    {
        return $this->postJson(
            $this->endpoint(
                'services.moph_id.provider_id.base_url',
                'services.moph_id.provider_id.token_path',
            ),
            [
                'client_id' => $this->providerClientId(),
                'secret_key' => $this->providerSecretKey(),
                'token_by' => 'Health ID',
                'token' => $healthAccessToken,
            ],
            'provider_token_exchange_failed',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function providerProfile(string $providerAccessToken): array
    {
        try {
            $response = $this->http()
                ->withToken($providerAccessToken)
                ->withHeaders([
                    'client-id' => $this->providerClientId(),
                    'secret-key' => $this->providerSecretKey(),
                ])
                ->get($this->endpoint(
                    'services.moph_id.provider_id.base_url',
                    'services.moph_id.provider_id.profile_path',
                ));
        } catch (Throwable) {
            throw new UpstreamAuthenticationException(
                'provider_profile_failed',
            );
        }

        return $this->jsonResponse($response, 'provider_profile_failed');
    }

    private function verifyProviderToken(
        string $providerAccessToken,
        string $expectedAccountId,
    ): void {
        $header = $this->jwtHeader($providerAccessToken);

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new UpstreamAuthenticationException(
                'provider_token_header_invalid',
            );
        }

        $publicKey = $this->providerPublicKey();

        try {
            $claims = $this->decodeProviderToken(
                $providerAccessToken,
                $publicKey,
            );
        } catch (UpstreamAuthenticationException $exception) {
            if ($exception->reason !== 'provider_token_signature_invalid') {
                throw $exception;
            }

            try {
                Cache::forget($this->providerPublicKeyCacheKey());
            } catch (Throwable) {
                throw $exception;
            }

            $claims = $this->decodeProviderToken(
                $providerAccessToken,
                $this->providerPublicKey(),
            );
        }

        $claimAccountId = $claims['account_id'] ?? null;

        if (
            $claimAccountId !== null
            && (! is_string($claimAccountId)
                || ! hash_equals($expectedAccountId, $claimAccountId))
        ) {
            throw new UpstreamAuthenticationException(
                'provider_identity_mismatch',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeProviderToken(
        string $providerAccessToken,
        string $publicKey,
    ): array {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->positiveIntConfig(
            'services.moph_id.clock_skew_seconds',
            0,
            300,
        );

        try {
            $headers = new stdClass;
            $claims = (array) JWT::decode(
                $providerAccessToken,
                new Key($publicKey, 'RS256'),
                $headers,
            );
        } catch (Throwable) {
            throw new UpstreamAuthenticationException(
                'provider_token_signature_invalid',
            );
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (($headers->alg ?? null) !== 'RS256') {
            throw new UpstreamAuthenticationException(
                'provider_token_header_invalid',
            );
        }

        return $claims;
    }

    private function providerPublicKey(): string
    {
        $url = $this->endpoint(
            'services.moph_id.provider_id.base_url',
            'services.moph_id.provider_id.public_key_path',
        );

        try {
            return Cache::remember(
                $this->providerPublicKeyCacheKey($url),
                $this->positiveIntConfig(
                    'services.moph_id.public_key_cache_seconds',
                    60,
                    3600,
                ),
                function () use ($url): string {
                    try {
                        $response = $this->http()
                            ->asJson()
                            ->post($url, [
                                'client_id' => $this->providerClientId(),
                                'secret_key' => $this->providerSecretKey(),
                            ]);
                    } catch (Throwable) {
                        throw new UpstreamAuthenticationException(
                            'provider_public_key_failed',
                        );
                    }

                    return $this->validPublicKey($response);
                },
            );
        } catch (UpstreamAuthenticationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new UpstreamAuthenticationException(
                'provider_public_key_failed',
            );
        }
    }

    private function providerPublicKeyCacheKey(?string $url = null): string
    {
        $url ??= $this->endpoint(
            'services.moph_id.provider_id.base_url',
            'services.moph_id.provider_id.public_key_path',
        );

        return 'sso:provider-id:public-key:'.hash('sha256', $url);
    }

    private function validPublicKey(Response $response): string
    {
        $contentType = strtolower(
            (string) $response->header('Content-Type'),
        );
        $body = trim($response->body());

        if (
            ! $response->successful()
            || ! str_contains($contentType, 'text/plain')
            || $body === ''
            || strlen($body) > self::MAX_KEY_BYTES
            || ! str_starts_with($body, '-----BEGIN PUBLIC KEY-----')
            || ! str_ends_with($body, '-----END PUBLIC KEY-----')
        ) {
            throw new UpstreamAuthenticationException(
                'provider_public_key_invalid',
            );
        }

        $key = openssl_pkey_get_public($body);
        $details = $key instanceof OpenSSLAsymmetricKey
            ? openssl_pkey_get_details($key)
            : false;

        if (
            ! is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || ! is_int($details['bits'] ?? null)
            || $details['bits'] < 2048
            || $details['bits'] > 8192
        ) {
            throw new UpstreamAuthenticationException(
                'provider_public_key_invalid',
            );
        }

        return $body;
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private function postForm(
        string $url,
        array $form,
        string $failureReason,
    ): array {
        try {
            $response = $this->http()->asForm()->post($url, $form);
        } catch (Throwable) {
            throw new UpstreamAuthenticationException($failureReason);
        }

        return $this->jsonResponse($response, $failureReason);
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function postJson(
        string $url,
        array $payload,
        string $failureReason,
    ): array {
        try {
            $response = $this->http()->asJson()->post($url, $payload);
        } catch (Throwable) {
            throw new UpstreamAuthenticationException($failureReason);
        }

        return $this->jsonResponse($response, $failureReason);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(
        Response $response,
        string $failureReason,
    ): array {
        $contentType = strtolower(
            (string) $response->header('Content-Type'),
        );
        $body = $response->body();

        if (
            ! $response->successful()
            || ! str_contains($contentType, 'application/json')
            || strlen($body) > self::MAX_JSON_BYTES
        ) {
            throw new UpstreamAuthenticationException($failureReason);
        }

        try {
            $payload = $response->json();
        } catch (Throwable) {
            throw new UpstreamAuthenticationException($failureReason);
        }

        if (! is_array($payload)) {
            throw new UpstreamAuthenticationException($failureReason);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelopeData(
        array $payload,
        string $failureReason,
        bool $healthResponse = false,
    ): array {
        $status = $payload['status'] ?? null;
        $validStatus = $healthResponse
            ? $status === 'success'
            : $status === 200 || $status === '200';
        $data = $payload['data'] ?? null;

        if (! $validStatus || ! is_array($data)) {
            throw new UpstreamAuthenticationException($failureReason);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertBearerAndExpiry(
        array $payload,
        string $failureReason,
    ): void {
        $tokenType = $payload['token_type'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;

        if (is_string($expiresIn) && ctype_digit($expiresIn)) {
            $expiresIn = (int) $expiresIn;
        }

        if (
            ! is_string($tokenType)
            || strcasecmp($tokenType, 'Bearer') !== 0
            || ! is_int($expiresIn)
            || $expiresIn < 1
            || $expiresIn > 315_360_000
        ) {
            throw new UpstreamAuthenticationException($failureReason);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredToken(
        array $payload,
        string $key,
        string $failureReason,
    ): string {
        $value = $payload[$key] ?? null;

        if (
            ! is_string($value)
            || $value === ''
            || strlen($value) > self::MAX_TOKEN_BYTES
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
        ) {
            throw new UpstreamAuthenticationException($failureReason);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredIdentifier(
        array $payload,
        string $key,
        string $failureReason,
    ): string {
        $value = $payload[$key] ?? null;

        if (
            ! is_string($value)
            || preg_match('/^[A-Za-z0-9._~-]{1,255}$/D', $value) !== 1
        ) {
            throw new UpstreamAuthenticationException($failureReason);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function requiredProviderCidHash(array $profile): string
    {
        $value = $profile['hash_cid'] ?? null;

        if (
            ! is_string($value)
            || preg_match('/^[a-fA-F0-9]{64}$/D', $value) !== 1
        ) {
            throw new UpstreamAuthenticationException(
                'provider_profile_invalid',
            );
        }

        return strtolower($value);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function organizationHcodes(array $profile): array
    {
        $organizations = $profile['organization'] ?? null;

        if (! is_array($organizations) || $organizations === []) {
            throw new UpstreamAuthenticationException(
                'provider_profile_invalid',
            );
        }

        $hcodes = [];

        foreach ($organizations as $organization) {
            $hcode = is_array($organization)
                ? ($organization['hcode'] ?? null)
                : null;

            if (
                ! is_string($hcode)
                || preg_match('/^[A-Za-z0-9_-]{1,20}$/D', trim($hcode)) !== 1
            ) {
                throw new UpstreamAuthenticationException(
                    'provider_profile_invalid',
                );
            }

            $hcodes[] = trim($hcode);
        }

        return array_values(array_unique($hcodes));
    }

    /**
     * @return array<string, mixed>
     */
    private function jwtHeader(string $token): array
    {
        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new UpstreamAuthenticationException(
                'provider_token_header_invalid',
            );
        }

        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new UpstreamAuthenticationException(
                'provider_token_header_invalid',
            );
        }

        try {
            $header = json_decode(
                JWT::urlsafeB64Decode($segments[0]),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            throw new UpstreamAuthenticationException(
                'provider_token_header_invalid',
            );
        }

        if (! is_array($header)) {
            throw new UpstreamAuthenticationException(
                'provider_token_header_invalid',
            );
        }

        return $header;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(
                $this->positiveIntConfig(
                    'services.upstream_http.connect_timeout_seconds',
                    1,
                    30,
                ),
            )
            ->timeout(
                $this->positiveIntConfig(
                    'services.upstream_http.timeout_seconds',
                    1,
                    60,
                ),
            )
            ->withOptions([
                'allow_redirects' => false,
                'verify' => true,
            ]);
    }

    private function endpoint(string $baseKey, string $pathKey): string
    {
        $baseUrl = rtrim($this->httpsConfig($baseKey), '/');
        $path = $this->stringConfig($pathKey);

        if (
            preg_match('#^/[A-Za-z0-9/_-]+$#D', $path) !== 1
            || str_contains($path, '//')
            || str_contains($path, '/./')
            || str_contains($path, '/../')
        ) {
            throw new UpstreamAuthenticationException(
                'provider_configuration_invalid',
            );
        }

        return $baseUrl.$path;
    }

    private function redirectUriConfig(): string
    {
        $value = $this->httpsConfig(
            'services.moph_id.health_id.redirect_uri',
        );
        $expected = rtrim(
            $this->stringConfig('app.url'),
            '/',
        ).'/sso/callback/provider-id';

        if (! hash_equals($expected, $value)) {
            throw new UpstreamAuthenticationException(
                'provider_configuration_invalid',
            );
        }

        return $value;
    }

    private function providerClientId(): string
    {
        return $this->stringConfig(
            'services.moph_id.provider_id.client_id',
        );
    }

    private function providerSecretKey(): string
    {
        return $this->stringConfig(
            'services.moph_id.provider_id.secret_key',
        );
    }

    private function httpsConfig(string $key): string
    {
        $value = $this->stringConfig($key);
        $parts = parse_url($value);

        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new UpstreamAuthenticationException(
                'provider_configuration_invalid',
            );
        }

        return $value;
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || trim($value) === '') {
            throw new UpstreamAuthenticationException(
                'provider_configuration_invalid',
            );
        }

        return trim($value);
    }

    private function positiveIntConfig(
        string $key,
        int $minimum,
        int $maximum,
    ): int {
        $value = config($key);

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new UpstreamAuthenticationException(
                'provider_configuration_invalid',
            );
        }

        return $value;
    }
}

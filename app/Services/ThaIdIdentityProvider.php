<?php

namespace App\Services;

use App\Contracts\ThaIdIdentityProvider as ThaIdIdentityProviderContract;
use App\Data\VerifiedExternalIdentity;
use App\Exceptions\UpstreamAuthenticationException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

class ThaIdIdentityProvider implements ThaIdIdentityProviderContract
{
    private const MAX_JSON_BYTES = 65_536;

    private const MAX_TOKEN_BYTES = 16_384;

    public function authenticate(string $authorizationCode): VerifiedExternalIdentity
    {
        if (config('services.thaid.enabled') !== true) {
            throw new UpstreamAuthenticationException(
                'provider_disabled',
            );
        }

        if (
            preg_match('/^[A-Za-z0-9._~-]{8,2048}$/D', $authorizationCode)
                !== 1
        ) {
            throw new UpstreamAuthenticationException(
                'authorization_code_invalid',
            );
        }

        $tokenResponse = $this->exchangeCode($authorizationCode);
        $accessToken = $this->requiredToken(
            $tokenResponse,
            'access_token',
        );
        $idToken = $this->requiredToken($tokenResponse, 'id_token');
        $tokenType = $tokenResponse['token_type'] ?? null;

        if (! is_string($tokenType) || strcasecmp($tokenType, 'Bearer') !== 0) {
            throw new UpstreamAuthenticationException(
                'token_response_invalid',
            );
        }

        $this->assertProviderExpiry($tokenResponse['expire_in'] ?? null);
        $this->assertRequiredScopes($tokenResponse['scope'] ?? null, [
            'openid',
            'pid',
        ]);
        $claims = $this->verifiedIdTokenClaims($idToken, $accessToken);
        $introspection = $this->introspect($accessToken);

        if (
            ($introspection['active'] ?? null) !== true
            || ! is_string($introspection['sub'] ?? null)
            || ! hash_equals($claims['sub'], $introspection['sub'])
        ) {
            throw new UpstreamAuthenticationException(
                'introspection_invalid',
            );
        }

        $this->assertRequiredScopes($introspection['scope'] ?? null, ['pid']);

        try {
            return VerifiedExternalIdentity::thaId(
                $claims['sub'],
                $claims['pid'],
            );
        } catch (\InvalidArgumentException) {
            throw new UpstreamAuthenticationException(
                'identity_claims_invalid',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCode(string $authorizationCode): array
    {
        return $this->postForm(
            $this->httpsConfig('services.thaid.token_url'),
            [
                'grant_type' => 'authorization_code',
                'code' => $authorizationCode,
                'redirect_uri' => $this->httpsConfig(
                    'services.thaid.redirect_uri',
                ),
            ],
            'token_exchange_failed',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function introspect(string $accessToken): array
    {
        return $this->postForm(
            $this->httpsConfig('services.thaid.introspection_url'),
            ['token' => 'Bearer '.$accessToken],
            'introspection_failed',
        );
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
            $response = $this->http()
                ->withBasicAuth(
                    $this->stringConfig('services.thaid.client_id'),
                    $this->stringConfig('services.thaid.client_secret'),
                )
                ->asForm()
                ->post($url, $form);
        } catch (Throwable) {
            throw new UpstreamAuthenticationException($failureReason);
        }

        return $this->jsonResponse($response, $failureReason);
    }

    /**
     * @return array{sub: string, pid: string}
     */
    private function verifiedIdTokenClaims(
        string $idToken,
        string $accessToken,
    ): array {
        $header = $this->jwtHeader($idToken);

        if (
            ($header['alg'] ?? null) !== 'ES256'
            || ! is_string($header['kid'] ?? null)
            || $header['kid'] === ''
            || strlen($header['kid']) > 255
        ) {
            throw new UpstreamAuthenticationException(
                'id_token_header_invalid',
            );
        }

        $keys = $this->signingKeys($header['kid']);
        $previousLeeway = JWT::$leeway;
        $clockSkew = $this->positiveIntConfig(
            'services.thaid.clock_skew_seconds',
            0,
            300,
        );
        JWT::$leeway = $clockSkew;

        try {
            $headers = new stdClass;
            $claims = (array) JWT::decode($idToken, $keys, $headers);
        } catch (Throwable) {
            throw new UpstreamAuthenticationException(
                'id_token_signature_invalid',
            );
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (($headers->alg ?? null) !== 'ES256') {
            throw new UpstreamAuthenticationException(
                'id_token_header_invalid',
            );
        }

        $issuer = $this->stringConfig('services.thaid.issuer');
        $clientId = $this->stringConfig('services.thaid.client_id');
        $subject = $claims['sub'] ?? null;
        $pid = $claims['pid'] ?? null;
        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        $atHash = $claims['at_hash'] ?? null;

        if (
            ($claims['iss'] ?? null) !== $issuer
            || ! $this->validAudience($claims['aud'] ?? null, $clientId, $claims)
            || ! is_int($issuedAt)
            || ! is_int($expiresAt)
            || $issuedAt > time() + $clockSkew
            || $expiresAt <= time() - $clockSkew
            || ! is_string($subject)
            || $subject === ''
            || strlen($subject) > 255
            || ! is_string($pid)
            || ! is_string($atHash)
            || ! hash_equals($this->accessTokenHash($accessToken), $atHash)
        ) {
            throw new UpstreamAuthenticationException(
                'id_token_claims_invalid',
            );
        }

        return ['sub' => $subject, 'pid' => $pid];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function validAudience(
        mixed $audience,
        string $clientId,
        array $claims,
    ): bool {
        if (is_string($audience)) {
            return hash_equals($clientId, $audience);
        }

        if (
            ! is_array($audience)
            || $audience === []
            || collect($audience)->contains(
                fn (mixed $value): bool => ! is_string($value),
            )
            || ! in_array($clientId, $audience, true)
        ) {
            return false;
        }

        return count($audience) === 1
            || (is_string($claims['azp'] ?? null)
                && hash_equals($clientId, $claims['azp']));
    }

    private function accessTokenHash(string $accessToken): string
    {
        return JWT::urlsafeB64Encode(
            substr(hash('sha256', $accessToken, true), 0, 16),
        );
    }

    /**
     * @return array<string, Key>
     */
    private function signingKeys(string $requiredKeyId): array
    {
        $jwks = $this->jwks();
        $keys = $this->parseSigningKeys($jwks);

        if (! isset($keys[$requiredKeyId])) {
            try {
                Cache::forget($this->jwksCacheKey());
            } catch (Throwable) {
                throw new UpstreamAuthenticationException(
                    'jwks_fetch_failed',
                );
            }
            $keys = $this->parseSigningKeys($this->jwks());
        }

        if (! isset($keys[$requiredKeyId])) {
            throw new UpstreamAuthenticationException(
                'id_token_key_unknown',
            );
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $jwks
     * @return array<string, Key>
     */
    private function parseSigningKeys(array $jwks): array
    {
        $rawKeys = $jwks['keys'] ?? null;

        if (! is_array($rawKeys)) {
            throw new UpstreamAuthenticationException('jwks_invalid');
        }

        $filtered = array_values(array_filter(
            $rawKeys,
            fn (mixed $key): bool => is_array($key)
                && ($key['kty'] ?? null) === 'EC'
                && ($key['crv'] ?? null) === 'P-256'
                && (! isset($key['use']) || $key['use'] === 'sig')
                && (! isset($key['alg']) || $key['alg'] === 'ES256'),
        ));

        try {
            return JWK::parseKeySet(['keys' => $filtered], 'ES256');
        } catch (Throwable) {
            throw new UpstreamAuthenticationException('jwks_invalid');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jwks(): array
    {
        $discovery = $this->discovery();
        $jwksUrl = $discovery['jwks_uri'] ?? null;

        if (! is_string($jwksUrl)) {
            throw new UpstreamAuthenticationException('discovery_invalid');
        }

        $this->assertSameProviderOrigin($jwksUrl);

        try {
            return Cache::remember(
                $this->jwksCacheKey(),
                $this->positiveIntConfig(
                    'services.thaid.jwks_cache_seconds',
                    60,
                    3600,
                ),
                fn (): array => $this->getJson(
                    $jwksUrl,
                    'jwks_fetch_failed',
                ),
            );
        } catch (UpstreamAuthenticationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new UpstreamAuthenticationException(
                'jwks_fetch_failed',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function discovery(): array
    {
        $url = $this->httpsConfig('services.thaid.discovery_url');

        try {
            return Cache::remember(
                'sso:thaid:discovery:'.hash('sha256', $url),
                $this->positiveIntConfig(
                    'services.thaid.discovery_cache_seconds',
                    60,
                    3600,
                ),
                function () use ($url): array {
                    $document = $this->getJson(
                        $url,
                        'discovery_fetch_failed',
                    );
                    $issuer = $this->stringConfig(
                        'services.thaid.issuer',
                    );
                    $algorithms = $document[
                        'id_token_signing_alg_values_supported'
                    ] ?? null;

                    if (
                        ($document['issuer'] ?? null) !== $issuer
                        || ($document['token_endpoint'] ?? null)
                            !== $this->httpsConfig(
                                'services.thaid.token_url',
                            )
                        || ! is_array($algorithms)
                        || ! in_array('ES256', $algorithms, true)
                        || ! is_string($document['jwks_uri'] ?? null)
                    ) {
                        throw new UpstreamAuthenticationException(
                            'discovery_invalid',
                        );
                    }

                    $this->assertSameProviderOrigin(
                        $document['jwks_uri'],
                    );

                    return $document;
                },
            );
        } catch (UpstreamAuthenticationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new UpstreamAuthenticationException(
                'discovery_fetch_failed',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url, string $failureReason): array
    {
        try {
            $response = $this->http()->get($url);
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

    /**
     * @return array<string, mixed>
     */
    private function jwtHeader(string $token): array
    {
        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new UpstreamAuthenticationException(
                'id_token_header_invalid',
            );
        }

        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new UpstreamAuthenticationException(
                'id_token_header_invalid',
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
                'id_token_header_invalid',
            );
        }

        if (! is_array($header)) {
            throw new UpstreamAuthenticationException(
                'id_token_header_invalid',
            );
        }

        return $header;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredToken(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (
            ! is_string($value)
            || $value === ''
            || strlen($value) > self::MAX_TOKEN_BYTES
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
        ) {
            throw new UpstreamAuthenticationException(
                'token_response_invalid',
            );
        }

        return $value;
    }

    /**
     * @param  list<string>  $required
     */
    private function assertRequiredScopes(
        mixed $scopeValue,
        array $required,
    ): void {
        if (! is_string($scopeValue) || strlen($scopeValue) > 1024) {
            throw new UpstreamAuthenticationException('scope_invalid');
        }

        $scopes = preg_split('/\s+/', trim($scopeValue));

        if (
            ! is_array($scopes)
            || $scopes === []
            || collect($required)->contains(
                fn (string $scope): bool => ! in_array(
                    $scope,
                    $scopes,
                    true,
                ),
            )
        ) {
            throw new UpstreamAuthenticationException('scope_invalid');
        }
    }

    private function assertProviderExpiry(mixed $expiry): void
    {
        if (
            (is_string($expiry) && ctype_digit($expiry))
            || is_int($expiry)
        ) {
            $expiry = (int) $expiry;
        }

        if (! is_int($expiry) || $expiry <= time()) {
            throw new UpstreamAuthenticationException(
                'token_response_invalid',
            );
        }
    }

    private function assertSameProviderOrigin(string $url): void
    {
        $validated = $this->validHttpsUrl($url);
        $issuer = $this->validHttpsUrl(
            $this->stringConfig('services.thaid.issuer'),
        );

        if (
            strtolower($validated['host'])
                !== strtolower($issuer['host'])
            || ($validated['port'] ?? 443) !== ($issuer['port'] ?? 443)
        ) {
            throw new UpstreamAuthenticationException(
                'provider_url_invalid',
            );
        }
    }

    private function httpsConfig(string $key): string
    {
        $value = $this->stringConfig($key);
        $this->validHttpsUrl($value);
        $this->assertSameProviderOriginUnlessIssuer($key, $value);

        return $value;
    }

    private function assertSameProviderOriginUnlessIssuer(
        string $key,
        string $url,
    ): void {
        if ($key !== 'services.thaid.issuer') {
            $this->assertSameProviderOrigin($url);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validHttpsUrl(string $url): array
    {
        $parts = parse_url($url);

        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new UpstreamAuthenticationException(
                'provider_url_invalid',
            );
        }

        return $parts;
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

    private function jwksCacheKey(): string
    {
        return 'sso:thaid:jwks:'.hash(
            'sha256',
            $this->stringConfig('services.thaid.discovery_url'),
        );
    }
}

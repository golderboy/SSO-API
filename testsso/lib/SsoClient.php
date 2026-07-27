<?php

declare(strict_types=1);

namespace Testsso;

use JsonException;
use RuntimeException;

final class SsoClientException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = 'SSO client operation failed.',
    ) {
        parent::__construct($message);
    }
}

final class SsoClient
{
    private const MAX_RESPONSE_BYTES = 1_048_576;

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function validateConfig(array $config): array
    {
        foreach ([
            'sso_base_url',
            'client_id',
            'client_secret',
            'redirect_uri',
            'scope',
        ] as $required) {
            if (! isset($config[$required]) || ! is_string($config[$required])) {
                throw new SsoClientException('configuration_invalid');
            }

            $config[$required] = trim($config[$required]);

            if ($config[$required] === '') {
                throw new SsoClientException('configuration_invalid');
            }
        }

        self::assertHttpsUrl($config['sso_base_url'], true);
        self::assertHttpsUrl($config['redirect_uri'], true);

        if (
            strlen($config['client_id']) > 255
            || str_starts_with($config['client_id'], 'REPLACE_')
            || strlen($config['client_secret']) < 16
            || strlen($config['client_secret']) > 512
            || str_starts_with($config['client_secret'], 'REPLACE_')
            || strlen($config['scope']) > 255
            || ! preg_match('/^[A-Za-z0-9._:-]+(?: [A-Za-z0-9._:-]+)*$/', $config['scope'])
        ) {
            throw new SsoClientException('configuration_invalid');
        }

        $config['sso_base_url'] = rtrim($config['sso_base_url'], '/');
        $config['authorization_url'] = $config['sso_base_url'].'/authorize';
        $config['token_url'] = $config['sso_base_url'].'/token';
        $config['userinfo_url'] = $config['sso_base_url'].'/userinfo';
        $config['revocation_url'] = $config['sso_base_url'].'/revoke';

        $config['transaction_ttl_seconds'] = self::boundedInteger(
            $config['transaction_ttl_seconds'] ?? 300,
            60,
            600,
        );
        $config['session_ttl_seconds'] = self::boundedInteger(
            $config['session_ttl_seconds'] ?? 1800,
            60,
            1800,
        );
        $config['connect_timeout_seconds'] = self::boundedInteger(
            $config['connect_timeout_seconds'] ?? 5,
            1,
            15,
        );
        $config['request_timeout_seconds'] = self::boundedInteger(
            $config['request_timeout_seconds'] ?? 15,
            2,
            30,
        );

        return $config;
    }

    /**
     * @return array{state: string, nonce: string, code_verifier: string, code_challenge: string, started_at: int}
     */
    public static function newTransaction(?int $now = null): array
    {
        $verifier = self::base64UrlEncode(random_bytes(64));

        return [
            'state' => self::base64UrlEncode(random_bytes(32)),
            'nonce' => self::base64UrlEncode(random_bytes(32)),
            'code_verifier' => $verifier,
            'code_challenge' => self::base64UrlEncode(
                hash('sha256', $verifier, true),
            ),
            'started_at' => $now ?? time(),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $transaction
     */
    public static function authorizationUrl(
        array $config,
        array $transaction,
    ): string {
        foreach (['state', 'nonce', 'code_challenge'] as $key) {
            if (! isset($transaction[$key]) || ! is_string($transaction[$key])) {
                throw new SsoClientException('transaction_invalid');
            }
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'scope' => $config['scope'],
            'state' => $transaction['state'],
            'nonce' => $transaction['nonce'],
            'code_challenge' => $transaction['code_challenge'],
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $config['authorization_url'].'?'.$query;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $transaction
     */
    public static function validateCallback(
        array $query,
        array $transaction,
        int $transactionTtlSeconds,
        ?int $now = null,
    ): string {
        if (isset($query['error'])) {
            throw new SsoClientException('authorization_denied');
        }

        foreach (['code', 'state'] as $key) {
            if (
                ! isset($query[$key])
                || ! is_string($query[$key])
                || $query[$key] === ''
            ) {
                throw new SsoClientException('callback_invalid');
            }
        }

        if (
            strlen($query['code']) > 4096
            || strlen($query['state']) > 512
            || ! isset($transaction['state'], $transaction['started_at'])
            || ! is_string($transaction['state'])
            || ! is_int($transaction['started_at'])
        ) {
            throw new SsoClientException('callback_invalid');
        }

        $currentTime = $now ?? time();

        if (
            $transaction['started_at'] > $currentTime
            || ($currentTime - $transaction['started_at']) > $transactionTtlSeconds
        ) {
            throw new SsoClientException('transaction_expired');
        }

        if (! hash_equals($transaction['state'], $query['state'])) {
            throw new SsoClientException('state_mismatch');
        }

        return $query['code'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{access_token: string, expires_in: int, scope: string}
     */
    public static function validateTokenResponse(array $payload): array
    {
        $accessToken = $payload['access_token'] ?? null;
        $tokenType = $payload['token_type'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;

        if (
            ! is_string($accessToken)
            || $accessToken === ''
            || strlen($accessToken) > 16_384
            || ! preg_match('/^[\x21-\x7E]+$/D', $accessToken)
            || ! is_string($tokenType)
            || strcasecmp($tokenType, 'Bearer') !== 0
        ) {
            throw new SsoClientException('token_response_invalid');
        }

        if (is_string($expiresIn) && ctype_digit($expiresIn)) {
            $expiresIn = (int) $expiresIn;
        }

        if (! is_int($expiresIn) || $expiresIn < 1 || $expiresIn > 86_400) {
            throw new SsoClientException('token_response_invalid');
        }

        $scope = $payload['scope'] ?? '';

        if (! is_string($scope) || strlen($scope) > 255) {
            throw new SsoClientException('token_response_invalid');
        }

        return [
            'access_token' => $accessToken,
            'expires_in' => $expiresIn,
            'scope' => $scope,
        ];
    }

    /**
     * Keep only claims that the test client is allowed to display or retain.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     subject: string,
     *     display_name: string,
     *     provider: string,
     *     provider_label: string,
     *     organization: string,
     *     hcode: string,
     *     roles: list<string>
     * }
     */
    public static function normalizeUserInfo(array $payload): array
    {
        $subject = self::boundedString($payload['sub'] ?? null, 255);

        if ($subject === null) {
            throw new SsoClientException('userinfo_invalid');
        }

        $displayName = self::boundedString($payload['name'] ?? null, 200)
            ?? self::boundedString($payload['preferred_username'] ?? null, 200)
            ?? 'ผู้ใช้งานที่ยืนยันแล้ว';

        $provider = strtolower(
            self::boundedString($payload['provider'] ?? null, 50) ?? '',
        );
        $authenticationMethods = $payload['amr'] ?? [];

        if ($provider === '' && is_array($authenticationMethods)) {
            foreach ($authenticationMethods as $method) {
                if (is_string($method) && strlen($method) <= 50) {
                    $normalizedMethod = strtolower($method);

                    if (in_array($normalizedMethod, [
                        'thaid',
                        'provider_id',
                        'providerid',
                        'moph_id',
                    ], true)) {
                        $provider = $normalizedMethod;
                        break;
                    }
                }
            }
        }

        [$provider, $providerLabel] = match ($provider) {
            'thaid' => ['thaid', 'ThaID'],
            'provider_id', 'providerid', 'moph_id' => [
                'provider_id',
                'Provider ID',
            ],
            default => ['sso', 'Sobmoei SSO'],
        };

        $organization = self::boundedString(
            $payload['org_name'] ?? $payload['organization_name'] ?? null,
            255,
        ) ?? 'หน่วยงานที่ได้รับสิทธิ';
        $hcode = self::boundedString($payload['hcode'] ?? null, 32) ?? '';

        if (
            $hcode !== ''
            && ! preg_match('/^[A-Za-z0-9_-]+$/', $hcode)
        ) {
            throw new SsoClientException('userinfo_invalid');
        }

        $roles = [];

        if (isset($payload['roles'])) {
            if (! is_array($payload['roles'])) {
                throw new SsoClientException('userinfo_invalid');
            }

            foreach ($payload['roles'] as $role) {
                if (
                    ! is_string($role)
                    || $role === ''
                    || strlen($role) > 100
                    || ! preg_match('/^[\p{L}\p{N}._: -]+$/u', $role)
                ) {
                    throw new SsoClientException('userinfo_invalid');
                }

                $roles[] = $role;

                if (count($roles) === 20) {
                    break;
                }
            }
        }

        return [
            'subject' => $subject,
            'display_name' => $displayName,
            'provider' => $provider,
            'provider_label' => $providerLabel,
            'organization' => $organization,
            'hcode' => $hcode,
            'roles' => array_values(array_unique($roles)),
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>|null  $form
     * @return array{status: int, body: string}
     */
    public static function httpRequest(
        string $method,
        string $url,
        array $headers,
        ?array $form,
        int $connectTimeoutSeconds,
        int $requestTimeoutSeconds,
    ): array {
        self::assertHttpsUrl($url, true);

        if (! extension_loaded('curl')) {
            throw new SsoClientException('curl_unavailable');
        }

        $handle = curl_init();

        if ($handle === false) {
            throw new SsoClientException('upstream_unavailable');
        }

        $responseBody = '';
        $headerLines = [];

        foreach ($headers as $name => $value) {
            if (
                ! preg_match('/^[A-Za-z0-9-]+$/D', $name)
                || preg_match('/[\r\n]/', $value)
            ) {
                throw new SsoClientException('upstream_unavailable');
            }

            $headerLines[] = $name.': '.$value;
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $requestTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Sobmoei-SSO-TestClient/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function (
                mixed $curl,
                string $chunk,
            ) use (&$responseBody): int {
                if (
                    strlen($responseBody) + strlen($chunk)
                    > self::MAX_RESPONSE_BYTES
                ) {
                    return 0;
                }

                $responseBody .= $chunk;

                return strlen($chunk);
            },
        ];

        if ($form !== null) {
            $options[CURLOPT_POSTFIELDS] = http_build_query(
                $form,
                '',
                '&',
                PHP_QUERY_RFC3986,
            );
        }

        curl_setopt_array($handle, $options);
        $executed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);

        if ($executed === false || $curlError !== 0 || $status === 0) {
            throw new SsoClientException('upstream_unavailable');
        }

        return [
            'status' => $status,
            'body' => $responseBody,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeJsonResponse(
        string $body,
        string $failureReason,
    ): array {
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new SsoClientException($failureReason);
        }

        try {
            $decoded = json_decode(
                $body,
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new SsoClientException($failureReason);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new SsoClientException($failureReason);
        }

        return $decoded;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function assertHttpsUrl(
        string $url,
        bool $allowPath,
    ): void {
        $parts = parse_url($url);

        if (
            $parts === false
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (! $allowPath && isset($parts['path']) && $parts['path'] !== '')
        ) {
            throw new SsoClientException('configuration_invalid');
        }
    }

    private static function boundedInteger(
        mixed $value,
        int $minimum,
        int $maximum,
    ): int {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new SsoClientException('configuration_invalid');
        }

        return $value;
    }

    private static function boundedString(
        mixed $value,
        int $maximumLength,
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > $maximumLength) {
            return null;
        }

        return $value;
    }
}

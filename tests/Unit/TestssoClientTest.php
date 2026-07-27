<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Testsso\SsoClient;
use Testsso\SsoClientException;

require_once dirname(__DIR__, 2).'/testsso/lib/SsoClient.php';

class TestssoClientTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return SsoClient::validateConfig([
            'sso_base_url' => 'https://sso.example.test/call',
            'client_id' => 'testsso-client',
            'client_secret' => 'testing-client-secret-at-least-32-bytes',
            'redirect_uri' => 'https://app.example.test/testsso/callback.php',
            'scope' => 'openid profile organization roles',
            'transaction_ttl_seconds' => 300,
            'session_ttl_seconds' => 1800,
        ]);
    }

    public function test_authorization_request_uses_state_nonce_and_pkce_without_secret(): void
    {
        $transaction = SsoClient::newTransaction(1_700_000_000);
        $url = SsoClient::authorizationUrl($this->config(), $transaction);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($transaction['state'], $query['state']);
        $this->assertSame($transaction['nonce'], $query['nonce']);
        $this->assertSame(
            $transaction['code_challenge'],
            $query['code_challenge'],
        );
        $this->assertArrayNotHasKey('client_secret', $query);
        $this->assertGreaterThanOrEqual(43, strlen($transaction['code_verifier']));
        $this->assertLessThanOrEqual(128, strlen($transaction['code_verifier']));
    }

    public function test_callback_requires_matching_unexpired_state(): void
    {
        $transaction = SsoClient::newTransaction(1_700_000_000);

        $this->assertSame(
            'one-time-code',
            SsoClient::validateCallback(
                [
                    'code' => 'one-time-code',
                    'state' => $transaction['state'],
                ],
                $transaction,
                300,
                1_700_000_100,
            ),
        );

        try {
            SsoClient::validateCallback(
                ['code' => 'code', 'state' => 'wrong-state'],
                $transaction,
                300,
                1_700_000_100,
            );
            $this->fail('A mismatched state must be rejected.');
        } catch (SsoClientException $exception) {
            $this->assertSame('state_mismatch', $exception->reason);
        }

        $this->expectException(SsoClientException::class);
        SsoClient::validateCallback(
            ['code' => 'code', 'state' => $transaction['state']],
            $transaction,
            300,
            1_700_000_301,
        );
    }

    public function test_config_rejects_non_https_endpoints(): void
    {
        $this->expectException(SsoClientException::class);

        SsoClient::validateConfig([
            'sso_base_url' => 'http://sso.example.test',
            'client_id' => 'testsso-client',
            'client_secret' => 'testing-client-secret-at-least-32-bytes',
            'redirect_uri' => 'https://app.example.test/callback.php',
            'scope' => 'openid',
        ]);
    }

    public function test_config_rejects_example_placeholders(): void
    {
        $this->expectException(SsoClientException::class);

        SsoClient::validateConfig([
            'sso_base_url' => 'https://sso.example.test',
            'client_id' => 'REPLACE_WITH_TESTSSO_CLIENT_ID',
            'client_secret' => 'REPLACE_WITH_TESTSSO_CLIENT_SECRET',
            'redirect_uri' => 'https://app.example.test/callback.php',
            'scope' => 'profile organization roles',
        ]);
    }

    public function test_token_response_is_strict_and_drops_id_token(): void
    {
        $token = SsoClient::validateTokenResponse([
            'access_token' => 'opaque-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 1800,
            'id_token' => 'must-not-be-retained',
            'scope' => 'openid profile',
        ]);

        $this->assertSame([
            'access_token' => 'opaque-access-token',
            'expires_in' => 1800,
            'scope' => 'openid profile',
        ], $token);
        $this->assertArrayNotHasKey('id_token', $token);
    }

    public function test_token_response_rejects_header_injection(): void
    {
        $this->expectException(SsoClientException::class);

        SsoClient::validateTokenResponse([
            'access_token' => "valid-looking\r\nInjected: header",
            'token_type' => 'Bearer',
            'expires_in' => 1800,
        ]);
    }

    public function test_userinfo_is_reduced_to_display_allowlist(): void
    {
        $userinfo = SsoClient::normalizeUserInfo([
            'sub' => 'internal-subject',
            'name' => 'ผู้ใช้งานทดสอบ',
            'provider' => 'provider_id',
            'org_name' => 'หน่วยงานทดสอบ',
            'hcode' => '12345',
            'roles' => ['staff', 'records.read'],
            'cid' => 'must-not-be-retained',
            'access_token' => 'must-not-be-retained',
            'raw_profile' => ['sensitive' => true],
        ]);

        $this->assertSame('Provider ID', $userinfo['provider_label']);
        $this->assertSame('12345', $userinfo['hcode']);
        $this->assertSame(['staff', 'records.read'], $userinfo['roles']);
        $this->assertArrayNotHasKey('cid', $userinfo);
        $this->assertArrayNotHasKey('access_token', $userinfo);
        $this->assertArrayNotHasKey('raw_profile', $userinfo);
    }

    public function test_support_files_are_blocked_by_htaccess(): void
    {
        $htaccess = (string) file_get_contents(
            dirname(__DIR__, 2).'/testsso/.htaccess',
        );

        $this->assertStringContainsString('bootstrap', $htaccess);
        $this->assertStringContainsString('config', $htaccess);
        $this->assertStringContainsString('RewriteRule ^lib', $htaccess);
        $this->assertStringContainsString('Require all denied', $htaccess);
    }

    public function test_userinfo_contract_identifies_provider_without_cid(): void
    {
        $contract = (string) file_get_contents(
            dirname(__DIR__, 2).'/docs/SSO_API_SPEC.yaml',
        );
        $userinfo = strstr($contract, '  /userinfo:');

        $this->assertIsString($userinfo);
        $this->assertStringContainsString(
            'enum: [thaid, provider_id]',
            $userinfo,
        );
        $this->assertStringNotContainsString("\n                  cid:", $userinfo);
        $this->assertStringNotContainsString(
            "\n                  access_token:",
            $userinfo,
        );
    }
}

<?php

declare(strict_types=1);

use Testsso\SsoClient;
use Testsso\SsoClientException;

require_once __DIR__.'/bootstrap.php';

testsso_start_session();
testsso_security_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $correlationId = testsso_correlation_id();
    testsso_record_error($correlationId, 'method_not_allowed', 405);
    header('Allow: GET');
    testsso_render_error(
        'คำขอไม่ถูกต้อง กรุณาเริ่มเข้าสู่ระบบใหม่',
        $correlationId,
        405,
    );
}

$transaction = $_SESSION['testsso_oauth_transaction'] ?? null;
unset($_SESSION['testsso_oauth_transaction']);

if (! is_array($transaction)) {
    $correlationId = testsso_correlation_id();
    testsso_record_error($correlationId, 'transaction_missing', 400);
    testsso_render_error(
        'ไม่พบรายการเข้าสู่ระบบที่รอดำเนินการ กรุณาเริ่มใหม่',
        $correlationId,
        400,
    );
}

try {
    $config = testsso_config();
    $code = SsoClient::validateCallback(
        $_GET,
        $transaction,
        $config['transaction_ttl_seconds'],
    );

    $tokenResponse = SsoClient::httpRequest(
        'POST',
        $config['token_url'],
        [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $config['redirect_uri'],
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code_verifier' => (string) $transaction['code_verifier'],
        ],
        $config['connect_timeout_seconds'],
        $config['request_timeout_seconds'],
    );

    if ($tokenResponse['status'] !== 200) {
        throw new SsoClientException('token_exchange_failed');
    }

    $token = SsoClient::validateTokenResponse(
        SsoClient::decodeJsonResponse(
            $tokenResponse['body'],
            'token_response_invalid',
        ),
    );
    $userinfoResponse = SsoClient::httpRequest(
        'GET',
        $config['userinfo_url'],
        [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token['access_token'],
        ],
        null,
        $config['connect_timeout_seconds'],
        $config['request_timeout_seconds'],
    );

    if ($userinfoResponse['status'] !== 200) {
        throw new SsoClientException('userinfo_failed');
    }

    $user = SsoClient::normalizeUserInfo(
        SsoClient::decodeJsonResponse(
            $userinfoResponse['body'],
            'userinfo_invalid',
        ),
    );
    $now = time();
    $expiresAt = $now + min(
        $token['expires_in'],
        $config['session_ttl_seconds'],
    );

    $_SESSION = [];

    if (! session_regenerate_id(true)) {
        throw new SsoClientException('unexpected_error');
    }

    $_SESSION['testsso_auth'] = [
        'access_token' => $token['access_token'],
        'user' => $user,
        'authenticated_at' => $now,
        'expires_at' => $expiresAt,
    ];
    $_SESSION['testsso_logout_csrf'] = SsoClient::base64UrlEncode(
        random_bytes(32),
    );

    header('Location: index.php', true, 303);
    exit;
} catch (SsoClientException $exception) {
    $correlationId = testsso_correlation_id();
    $status = match ($exception->reason) {
        'configuration_invalid', 'curl_unavailable' => 503,
        'upstream_unavailable' => 502,
        default => 400,
    };
    testsso_record_error($correlationId, $exception->reason, $status);
    testsso_render_error(
        'การยืนยันตัวตนหรือสิทธิการใช้งานไม่ผ่าน',
        $correlationId,
        $status,
    );
} catch (Throwable) {
    $correlationId = testsso_correlation_id();
    testsso_record_error($correlationId, 'unexpected_error', 500);
    testsso_render_error(
        'ระบบไม่สามารถดำเนินการได้ กรุณาลองใหม่อีกครั้ง',
        $correlationId,
        500,
    );
}

<?php

declare(strict_types=1);

use Testsso\SsoClient;
use Testsso\SsoClientException;

require_once __DIR__.'/bootstrap.php';

testsso_start_session();
testsso_security_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $correlationId = testsso_correlation_id();
    testsso_record_error($correlationId, 'method_not_allowed', 405);
    header('Allow: POST');
    testsso_render_error(
        'คำขอออกจากระบบไม่ถูกต้อง',
        $correlationId,
        405,
    );
}

$submittedCsrf = $_POST['csrf_token'] ?? null;
$sessionCsrf = $_SESSION['testsso_logout_csrf'] ?? null;

if (
    ! is_string($submittedCsrf)
    || ! is_string($sessionCsrf)
    || ! hash_equals($sessionCsrf, $submittedCsrf)
) {
    $correlationId = testsso_correlation_id();
    testsso_record_error($correlationId, 'logout_csrf_invalid', 403);
    testsso_render_error(
        'ไม่สามารถยืนยันคำขอออกจากระบบได้',
        $correlationId,
        403,
    );
}

$accessToken = $_SESSION['testsso_auth']['access_token'] ?? null;

if (is_string($accessToken) && $accessToken !== '') {
    try {
        $config = testsso_config();
        $revocationResponse = SsoClient::httpRequest(
            'POST',
            $config['revocation_url'],
            [
                'Accept' => 'application/json',
                'Authorization' => 'Basic '.base64_encode(
                    $config['client_id'].':'.$config['client_secret'],
                ),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            [
                'token' => $accessToken,
                'token_type_hint' => 'access_token',
            ],
            $config['connect_timeout_seconds'],
            $config['request_timeout_seconds'],
        );

        if (
            $revocationResponse['status'] < 200
            || $revocationResponse['status'] >= 300
        ) {
            $correlationId = testsso_correlation_id();
            testsso_record_error(
                $correlationId,
                'upstream_unavailable',
                $revocationResponse['status'],
            );
        }
    } catch (SsoClientException) {
        $correlationId = testsso_correlation_id();
        testsso_record_error($correlationId, 'upstream_unavailable', 502);
    }
}

testsso_clear_session();
$main = <<<'HTML'
    <section class="state-layout" aria-labelledby="logout-heading">
        <div class="state-panel state-panel--neutral">
            <div class="state-icon state-icon--neutral" aria-hidden="true">
                <svg viewBox="0 0 48 48">
                    <path d="M15 24h22M29 16l8 8-8 8M23 10H11v28h12"/>
                </svg>
            </div>
            <h1 id="logout-heading">ออกจากระบบแล้ว</h1>
            <p>เซสชันของเว็บไซต์ทดสอบถูกล้างเรียบร้อยแล้ว</p>
        </div>
        <div class="state-actions">
            <a class="button button--primary" href="index.php">เข้าสู่ระบบอีกครั้ง</a>
        </div>
    </section>
    HTML;

testsso_render_document('ออกจากระบบแล้ว', $main, 200, false);

<?php

declare(strict_types=1);

use Testsso\SsoClient;
use Testsso\SsoClientException;

require_once __DIR__.'/lib/SsoClient.php';

const TESTSSO_SESSION_NAME = 'sobmoei_testsso';
const TESTSSO_CONFIG_PATH = '/etc/sobmoei/testsso.php';

/**
 * @return array<string, mixed>
 */
function testsso_config(): array
{
    $configPath = getenv('TESTSSO_CONFIG_FILE');

    if (! is_string($configPath) || trim($configPath) === '') {
        $configPath = TESTSSO_CONFIG_PATH;
    }

    $fileConfig = [];

    if (is_file($configPath) && is_readable($configPath)) {
        $loaded = require $configPath;

        if (! is_array($loaded)) {
            throw new SsoClientException('configuration_invalid');
        }

        $fileConfig = $loaded;
    }

    $defaults = [
        'sso_base_url' => 'https://sobmoeiservice.moph.go.th/call',
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => 'https://sobmoeiservice.moph.go.th/testsso/callback.php',
        'scope' => 'openid profile organization roles',
        'transaction_ttl_seconds' => 300,
        'session_ttl_seconds' => 1800,
        'connect_timeout_seconds' => 5,
        'request_timeout_seconds' => 15,
    ];

    $environmentMap = [
        'sso_base_url' => 'TESTSSO_SSO_BASE_URL',
        'client_id' => 'TESTSSO_CLIENT_ID',
        'client_secret' => 'TESTSSO_CLIENT_SECRET',
        'redirect_uri' => 'TESTSSO_REDIRECT_URI',
        'scope' => 'TESTSSO_SCOPE',
        'transaction_ttl_seconds' => 'TESTSSO_TRANSACTION_TTL_SECONDS',
        'session_ttl_seconds' => 'TESTSSO_SESSION_TTL_SECONDS',
        'connect_timeout_seconds' => 'TESTSSO_CONNECT_TIMEOUT_SECONDS',
        'request_timeout_seconds' => 'TESTSSO_REQUEST_TIMEOUT_SECONDS',
    ];
    $environmentConfig = [];

    foreach ($environmentMap as $key => $environmentName) {
        $value = getenv($environmentName);

        if (is_string($value) && trim($value) !== '') {
            $environmentConfig[$key] = trim($value);
        }
    }

    return SsoClient::validateConfig(
        array_replace($defaults, $fileConfig, $environmentConfig),
    );
}

function testsso_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name(TESTSSO_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/testsso',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (! session_start()) {
        throw new RuntimeException('Unable to start the test client session.');
    }
}

function testsso_security_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Content-Type: text/html; charset=UTF-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; font-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

function testsso_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function testsso_correlation_id(): string
{
    return sprintf(
        '%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6)),
    );
}

function testsso_record_error(
    string $correlationId,
    string $event,
    int $status,
): void {
    $allowedEvents = [
        'authorization_denied',
        'callback_invalid',
        'configuration_invalid',
        'curl_unavailable',
        'logout_csrf_invalid',
        'method_not_allowed',
        'state_mismatch',
        'token_exchange_failed',
        'token_response_invalid',
        'transaction_expired',
        'transaction_invalid',
        'transaction_missing',
        'unexpected_error',
        'upstream_unavailable',
        'userinfo_failed',
        'userinfo_invalid',
    ];
    $safeEvent = in_array($event, $allowedEvents, true)
        ? $event
        : 'unexpected_error';

    error_log(sprintf(
        '[testsso] event=%s status=%d correlation_id=%s',
        $safeEvent,
        $status,
        $correlationId,
    ));
}

function testsso_clear_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();
}

function testsso_render_document(
    string $title,
    string $main,
    int $status = 200,
    bool $showFooter = true,
    string $headerAction = '',
): never {
    http_response_code($status);
    $footer = $showFooter
        ? <<<'HTML'
            <footer class="site-footer">
                <p>ระบบทดสอบสำหรับตรวจสอบการเชื่อมต่อเท่านั้น ไม่ใช่ระบบงานจริง</p>
                <p>© Sobmoei SSO Test Client</p>
            </footer>
            HTML
        : '';

    echo '<!doctype html>';
    echo '<html lang="th">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.testsso_escape($title).' · Sobmoei SSO</title>';
    echo '<link rel="stylesheet" href="assets/app.css">';
    echo '</head>';
    echo '<body>';
    echo <<<'HTML'
        <header class="site-header">
            <div class="header-inner">
                <a class="brand" href="index.php" aria-label="Sobmoei SSO">
                    <svg class="brand-mark" viewBox="0 0 52 44" aria-hidden="true">
                        <path d="M13 33 26 10l13 23H13Z"/>
                        <circle cx="26" cy="9" r="6"/>
                        <circle cx="12" cy="34" r="6"/>
                        <circle cx="40" cy="34" r="6"/>
                    </svg>
                    <span>
                        <strong>Sobmoei SSO</strong>
                        <small>ระบบทดสอบการเข้าสู่ระบบ</small>
                    </span>
                </a>
                <div class="header-action">
        HTML;
    echo $headerAction;
    echo <<<'HTML'
                </div>
            </div>
        </header>
        HTML;
    echo '<main class="page-shell">'.$main.'</main>';
    echo $footer;
    echo '</body>';
    echo '</html>';

    exit;
}

function testsso_render_error(
    string $message,
    string $correlationId,
    int $status,
): never {
    $safeMessage = testsso_escape($message);
    $safeCorrelationId = testsso_escape($correlationId);
    $main = <<<HTML
        <section class="state-layout" aria-labelledby="error-heading">
            <div class="state-panel state-panel--error">
                <div class="state-icon state-icon--error" aria-hidden="true">
                    <svg viewBox="0 0 48 48">
                        <path d="m15 15 18 18M33 15 15 33"/>
                    </svg>
                </div>
                <h1 id="error-heading">ไม่สามารถเข้าสู่ระบบได้</h1>
                <p>{$safeMessage}</p>
                <dl class="reference-row">
                    <dt>รหัสอ้างอิง</dt>
                    <dd>{$safeCorrelationId}</dd>
                </dl>
            </div>
            <div class="state-actions">
                <a class="button button--primary" href="index.php?login=1">ลองเข้าสู่ระบบอีกครั้ง</a>
                <a class="button button--secondary" href="index.php">กลับหน้าหลัก</a>
            </div>
        </section>
        HTML;

    testsso_render_document(
        'ไม่สามารถเข้าสู่ระบบได้',
        $main,
        $status,
        false,
    );
}

<?php

declare(strict_types=1);

use Testsso\SsoClient;
use Testsso\SsoClientException;

require_once __DIR__.'/bootstrap.php';

testsso_start_session();
testsso_security_headers();

try {
    $config = testsso_config();
} catch (SsoClientException) {
    $correlationId = testsso_correlation_id();
    testsso_record_error($correlationId, 'configuration_invalid', 503);
    testsso_render_error(
        'การตั้งค่าระบบทดสอบยังไม่พร้อม กรุณาแจ้งผู้ดูแลระบบ',
        $correlationId,
        503,
    );
}

$authentication = $_SESSION['testsso_auth'] ?? null;
$now = time();

if (
    ! is_array($authentication)
    || ! isset($authentication['expires_at'])
    || ! is_int($authentication['expires_at'])
    || $authentication['expires_at'] <= $now
) {
    unset($_SESSION['testsso_auth'], $_SESSION['testsso_logout_csrf']);
    $transaction = SsoClient::newTransaction($now);
    $_SESSION['testsso_oauth_transaction'] = $transaction;

    header(
        'Location: '.SsoClient::authorizationUrl($config, $transaction),
        true,
        302,
    );
    exit;
}

$user = $authentication['user'] ?? [];

if (! is_array($user)) {
    testsso_clear_session();
    header('Location: index.php', true, 303);
    exit;
}

$logoutCsrf = $_SESSION['testsso_logout_csrf'] ?? null;

if (! is_string($logoutCsrf) || strlen($logoutCsrf) < 32) {
    $logoutCsrf = SsoClient::base64UrlEncode(random_bytes(32));
    $_SESSION['testsso_logout_csrf'] = $logoutCsrf;
}

$displayName = testsso_escape((string) ($user['display_name'] ?? 'ผู้ใช้งานที่ยืนยันแล้ว'));
$providerLabel = testsso_escape((string) ($user['provider_label'] ?? 'Sobmoei SSO'));
$organization = testsso_escape((string) ($user['organization'] ?? 'หน่วยงานที่ได้รับสิทธิ'));
$hcode = trim((string) ($user['hcode'] ?? ''));

if ($hcode !== '') {
    $organization .= ' ('.testsso_escape($hcode).')';
}

$roles = $user['roles'] ?? [];
$roleLabel = is_array($roles) && $roles !== []
    ? implode(', ', array_map(
        static fn (mixed $role): string => testsso_escape((string) $role),
        $roles,
    ))
    : 'สิทธิที่ได้รับจากระบบ';
$expiresAt = (new DateTimeImmutable('@'.$authentication['expires_at']))
    ->setTimezone(new DateTimeZone('Asia/Bangkok'))
    ->format('d/m/Y H:i:s');
$safeCsrf = testsso_escape($logoutCsrf);

$headerAction = <<<HTML
    <form action="logout.php" method="post">
        <input type="hidden" name="csrf_token" value="{$safeCsrf}">
        <button class="button button--secondary button--compact" type="submit">
            ออกจากระบบ
        </button>
    </form>
    HTML;
$main = <<<HTML
    <section class="success-banner" aria-labelledby="success-heading">
        <div class="state-icon state-icon--success" aria-hidden="true">
            <svg viewBox="0 0 48 48">
                <path d="m12 25 8 8 17-19"/>
            </svg>
        </div>
        <h1 id="success-heading">เข้าสู่ระบบสำเร็จ</h1>
        <p>การยืนยันตัวตนและสิทธิการใช้งานผ่านระบบกลางสำเร็จ</p>
    </section>
    <section class="connection-section" aria-labelledby="connection-heading">
        <h2 id="connection-heading">ข้อมูลการเชื่อมต่อ</h2>
        <dl class="connection-grid">
            <div class="connection-row">
                <dt>ผู้ใช้งาน</dt>
                <dd>{$displayName}</dd>
            </div>
            <div class="connection-row">
                <dt>ผู้ให้บริการยืนยันตัวตน</dt>
                <dd>{$providerLabel}</dd>
            </div>
            <div class="connection-row">
                <dt>หน่วยงาน</dt>
                <dd>{$organization}</dd>
            </div>
            <div class="connection-row">
                <dt>สิทธิการใช้งาน</dt>
                <dd>{$roleLabel}</dd>
            </div>
            <div class="connection-row">
                <dt>เวลาหมดอายุเซสชัน</dt>
                <dd>{$expiresAt} น.</dd>
            </div>
        </dl>
    </section>
    HTML;

testsso_render_document(
    'เข้าสู่ระบบสำเร็จ',
    $main,
    200,
    true,
    $headerAction,
);

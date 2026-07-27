<?php

declare(strict_types=1);

/*
 * Copy this file to /etc/sobmoei/testsso.php on the AlmaLinux server.
 * Never place real client credentials in the web root or Git repository.
 */
return [
    'sso_base_url' => 'https://sobmoeiservice.moph.go.th/call',
    'client_id' => 'REPLACE_WITH_TESTSSO_CLIENT_ID',
    'client_secret' => 'REPLACE_WITH_TESTSSO_CLIENT_SECRET',
    'redirect_uri' => 'https://sobmoeiservice.moph.go.th/testsso/callback.php',
    'scope' => 'openid profile organization roles',
    'transaction_ttl_seconds' => 300,
    'session_ttl_seconds' => 1800,
    'connect_timeout_seconds' => 5,
    'request_timeout_seconds' => 15,
];

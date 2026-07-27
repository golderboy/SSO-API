<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'thaid' => [
        'enabled' => (bool) env('THAID_ENABLED', false),
        'client_id' => env('THAID_CLIENT_ID'),
        'client_secret' => env('THAID_CLIENT_SECRET'),
        'redirect_uri' => env('THAID_REDIRECT_URI'),
        'issuer' => env('THAID_ISSUER'),
        'authorization_url' => env('THAID_AUTHORIZATION_URL'),
        'token_url' => env('THAID_TOKEN_URL'),
        'introspection_url' => env('THAID_INTROSPECTION_URL'),
        'revocation_url' => env('THAID_REVOCATION_URL'),
        'discovery_url' => env('THAID_DISCOVERY_URL'),
        'scopes' => env('THAID_SCOPES', 'pid name openid'),
    ],

    'moph_id' => [
        'enabled' => (bool) env('MOPH_ID_ENABLED', false),
        'health_id' => [
            'client_id' => env('HEALTH_ID_CLIENT_ID'),
            'client_secret' => env('HEALTH_ID_CLIENT_SECRET'),
            'redirect_uri' => env('HEALTH_ID_REDIRECT_URI'),
            'base_url' => env('HEALTH_ID_BASE_URL'),
            'authorization_path' => env('HEALTH_ID_AUTHORIZATION_PATH', '/oauth/redirect'),
            'token_path' => env('HEALTH_ID_TOKEN_PATH', '/api/v1/token'),
            'public_key_path' => env('HEALTH_ID_PUBLIC_KEY_PATH', '/api/v1/oauth/public-key'),
        ],
        'provider_id' => [
            'client_id' => env('PROVIDER_ID_CLIENT_ID'),
            'secret_key' => env('PROVIDER_ID_SECRET_KEY'),
            'base_url' => env('PROVIDER_ID_BASE_URL'),
            'token_path' => env('PROVIDER_ID_TOKEN_PATH', '/api/v1/services/token'),
            'profile_path' => env('PROVIDER_ID_PROFILE_PATH', '/api/v1/services/profile'),
            'public_key_path' => env('PROVIDER_ID_PUBLIC_KEY_PATH', '/api/v1/services/public-key'),
        ],
    ],

    'upstream_http' => [
        'connect_timeout_seconds' => (int) env('UPSTREAM_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout_seconds' => (int) env('UPSTREAM_TIMEOUT_SECONDS', 15),
    ],
];

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
        'client_id' => env('THAID_CLIENT_ID'),
        'client_secret' => env('THAID_CLIENT_SECRET'),
        'redirect_uri' => env('THAID_REDIRECT_URI'),
        'issuer' => env('THAID_ISSUER'),
    ],

    'health_id' => [
        'client_id' => env('HEALTH_ID_CLIENT_ID'),
        'client_secret' => env('HEALTH_ID_CLIENT_SECRET'),
        'redirect_uri' => env('HEALTH_ID_REDIRECT_URI'),
        'base_url' => env('HEALTH_ID_BASE_URL'),
    ],

    'provider_id' => [
        'client_id' => env('PROVIDER_ID_CLIENT_ID'),
        'secret_key' => env('PROVIDER_ID_SECRET_KEY'),
        'base_url' => env('PROVIDER_ID_BASE_URL'),
    ],

];

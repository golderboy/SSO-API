<?php

return [
    'cid_lookup_key' => env('CID_LOOKUP_KEY'),
    'audit_hash_key' => env('AUDIT_HASH_KEY'),
    // A fixed non-secret hash keeps failed login work comparable when no user exists.
    'dummy_password_hash' => '$2y$12$f4XzvojKA9YFBIFmb8yRd.ZyCbuiy9U8PRTce3HcbeN3h.BPttEQq',
    'api_key_header' => env('SSO_API_KEY_HEADER', 'X-API-Key'),
    'api_key_length' => (int) env('SSO_API_KEY_LENGTH', 64),
    'default_page_size' => (int) env('SSO_DEFAULT_PAGE_SIZE', 20),
    'max_page_size' => (int) env('SSO_MAX_PAGE_SIZE', 100),
    'oauth' => [
        'authorization_code_ttl_minutes' => (int) env(
            'SSO_AUTHORIZATION_CODE_TTL_MINUTES',
            5,
        ),
        'access_token_ttl_minutes' => (int) env(
            'SSO_ACCESS_TOKEN_TTL_MINUTES',
            30,
        ),
        'refresh_token_ttl_minutes' => (int) env(
            'SSO_REFRESH_TOKEN_TTL_MINUTES',
            30,
        ),
    ],
];

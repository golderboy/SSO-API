<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Downstream SSO guard and routes
    |--------------------------------------------------------------------------
    |
    | Passport authenticates a dedicated SSO subject. Admin API authentication
    | remains on Sanctum and the User model. The empty path intentionally
    | exposes /authorize and /token at the Laravel root; Apache publishes them
    | externally below the /call reverse-proxy prefix.
    |
    */
    'guard' => 'sso_web',
    'middleware' => [],
    'path' => '',

    /*
    |--------------------------------------------------------------------------
    | OAuth signing keys
    |--------------------------------------------------------------------------
    */
    'key_path' => env('PASSPORT_KEY_PATH'),
    'private_key' => env('PASSPORT_PRIVATE_KEY'),
    'public_key' => env('PASSPORT_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Passport database connection
    |--------------------------------------------------------------------------
    */
    'connection' => env('PASSPORT_CONNECTION'),
];

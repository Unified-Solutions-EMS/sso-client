<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SSO Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL of the SSO application (e.g., https://sso.unified-apps.com).
    |
    */
    'base_url' => env('SSO_BASE_URL', 'https://sso.test'),

    /*
    |--------------------------------------------------------------------------
    | OAuth2 Client Credentials
    |--------------------------------------------------------------------------
    |
    | The Passport client ID and secret for this application.
    |
    */
    'client_id' => env('SSO_CLIENT_ID'),
    'client_secret' => env('SSO_CLIENT_SECRET'),
    'redirect_uri' => env('SSO_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | Token Lifetimes
    |--------------------------------------------------------------------------
    |
    | Access token lifetime in seconds. Refresh happens automatically via
    | middleware when the access token expires.
    |
    */
    'token_lifetime' => env('SSO_TOKEN_LIFETIME', 3600),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for HTTP requests to the SSO server.
    |
    */
    'timeout' => env('SSO_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Local Dev Auth Bypass
    |--------------------------------------------------------------------------
    |
    | When true and APP_ENV=local, allows local username/password login
    | without SSO redirect.
    |
    */
    'enable_local_dev_auth' => env('LOCAL_DEV_AUTH', false),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Customize the route paths used by the SSO client package.
    |
    */
    'routes' => [
        'redirect' => '/auth/sso/redirect',
        'callback' => '/auth/sso/callback',
        'logout' => '/auth/sso/logout',
    ],

];

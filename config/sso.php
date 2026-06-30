<?php

use Illuminate\Support\Str;

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
    | Application Slug
    |--------------------------------------------------------------------------
    |
    | This app's slug in the SSO application registry (e.g. "cloudpcr"). Used
    | to pick this app's slice of role data out of the SSO payload and to
    | guard app-scoped webhooks. Defaults to a slug of the app name.
    |
    */
    'app_slug' => env('SSO_APP_SLUG', Str::slug(env('APP_NAME', 'app'))),

    /*
    |--------------------------------------------------------------------------
    | Core API Key (server-to-server)
    |--------------------------------------------------------------------------
    |
    | Shared symmetric key used to call SSO's internal endpoints (e.g. the
    | company roster). Same value in every app — CORE_APP_API_KEY.
    |
    */
    'core_api_key' => env('CORE_APP_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Roster Reconciliation
    |--------------------------------------------------------------------------
    |
    | The scheduled `sso:sync-users` command pulls each locally-known company's
    | full roster from SSO and upserts every member, so an app's user list is
    | complete even for people who have never logged in. This is the periodic
    | safety net behind login/webhook provisioning. Set the cron to control
    | cadence; disable if an app manages its own roster.
    |
    */
    'roster_sync' => [
        'enabled' => env('SSO_ROSTER_SYNC_ENABLED', true),
        'cron' => env('SSO_ROSTER_SYNC_CRON', '0 * * * *'),
    ],

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
    | Webhook Secret
    |--------------------------------------------------------------------------
    |
    | HMAC secret used to verify incoming webhook payloads from the SSO server.
    | Must match the webhook_secret configured for this app in the SSO admin.
    |
    */
    'webhook_secret' => env('SSO_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Data Provider
    |--------------------------------------------------------------------------
    |
    | A class implementing DashboardDataProvider that returns widget data
    | for the SSO dashboard. Set to null if this app has no dashboard widget.
    |
    */
    'dashboard_provider' => null,

    /*
    |--------------------------------------------------------------------------
    | Action Handlers
    |--------------------------------------------------------------------------
    |
    | Map of action names to handler classes implementing SsoActionHandler.
    | SSO sends HMAC-signed POST requests to /api/sso/actions/{action}.
    |
    */
    'action_handlers' => [
        // 'create-service-request' => \App\Services\SsoActions\CreateServiceRequest::class,
    ],

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

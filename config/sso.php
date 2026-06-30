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
    | Core API Key
    |--------------------------------------------------------------------------
    |
    | Shared symmetric key for server-to-server calls to SSO's internal API
    | (device policy / challenge / verify / register). Same value across every
    | app.
    |
    */
    'core_api_key' => env('CORE_APP_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Device Authorization (device lockdown)
    |--------------------------------------------------------------------------
    |
    | When an agency enables device lock for this app, the `sso.device-lock`
    | middleware requires the browser to prove (via the Unified device
    | extension) that its device is authorized before the app will render.
    |
    | - enabled: master switch for enforcing the feature in this app.
    | - policy_cache_ttl: seconds to cache a company's policy locally so the
    |   middleware doesn't call SSO on every request.
    | - session_ttl: seconds a successful handshake stays bound to the session
    |   before the extension must re-prove. Also how quickly a revoked device
    |   is forced back through verification.
    | - fail_open: when SSO's policy endpoint can't be reached, allow the
    |   request rather than locking everyone out during an SSO outage.
    |
    */
    'device' => [
        'enabled' => env('SSO_DEVICE_LOCK_ENABLED', true),
        'policy_cache_ttl' => (int) env('SSO_DEVICE_POLICY_TTL', 300),
        'session_ttl' => (int) env('SSO_DEVICE_SESSION_TTL', 1800),
        'fail_open' => (bool) env('SSO_DEVICE_FAIL_OPEN', true),
    ],

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

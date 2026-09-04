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
    | Legacy Cookie Scrub
    |--------------------------------------------------------------------------
    |
    | Cookies the legacy ASP.NET application set, several of them on the parent
    | domain, which browsers keep sending to every Laravel app on the platform
    | long after the customer moved off it. PurgeLegacyApexCookies expires each
    | listed name in both its host-only and parent-domain form whenever the
    | request still carries it.
    |
    | Every name below is one the legacy app provably SET (Unified.Base):
    |
    |   .AspNetCore.Identity.Application  Web.Mvc/Startup/Startup.cs:121-131,
    |                                     the only cookie given an explicit
    |                                     Domain of ".{App:Domain}"
    |   Abp.TenantId                      Web.Core/Controllers/CloudPCRControllerBase.cs:23,
    |                                     and client side with abp.domain from
    |                                     Areas/App/Views/Layout/_Layout.cshtml:109
    |   userInfo                          Common/Modals/_InactivityControllerNotifyModal.js:65,
    |                                     written with abp.domain
    |   utmSource / utmMedium /           Views/Account/Login.cshtml:39-53, written
    |   utmCampaign / utmTerm /           with abp.domain for any utm_* query param
    |   utmContent
    |   v4-sso-attempts                   Web.Mvc/Startup/AuthConfigurer.cs:34,89
    |   UserLastActivity                  Common/Scripts/InactivityController.js:31,65
    |   cookieconsent_status              Common/Scripts/cookieConsent.js
    |   Public-XSRF-TOKEN                 Web.Public/Startup/CloudPCRWebFrontEndModule.cs:30
    |
    | Deliberately NOT listed: XSRF-TOKEN (the legacy app sets it host-only, but
    | it is also every Laravel app's own CSRF cookie), anything ending in
    | _session, Abp.AuthToken (the only write is commented out), enc_auth_token
    | (a query parameter, never a cookie), and the framework cookies whose names
    | carry a generated hash. The middleware refuses the first two categories
    | outright regardless of what this list says.
    |
    */
    'legacy_cookies' => [
        'apex_domain' => env('SSO_LEGACY_COOKIE_DOMAIN', '.unified-apps.com'),
        'names' => [
            '.AspNetCore.Identity.Application',
            'Abp.TenantId',
            'userInfo',
            'v4-sso-attempts',
            'UserLastActivity',
            'cookieconsent_status',
            'Public-XSRF-TOKEN',
            'utmSource',
            'utmMedium',
            'utmCampaign',
            'utmTerm',
            'utmContent',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Callback Loop Breaker
    |--------------------------------------------------------------------------
    |
    | A failing SSO callback normally redirects to the login route, which
    | re-enters the SSO flow, which succeeds instantly against the user's live
    | SSO session and lands back on the failing callback. Any deterministic
    | failure therefore loops forever (UNI-416). After this many consecutive
    | failures inside the window, the callback renders the sso::sign-in-failed
    | view with a 500 instead of redirecting.
    |
    */
    'callback_failure_threshold' => env('SSO_CALLBACK_FAILURE_THRESHOLD', 3),
    'callback_failure_window_seconds' => env('SSO_CALLBACK_FAILURE_WINDOW', 120),

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

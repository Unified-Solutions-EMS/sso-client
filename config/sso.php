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
    | Trial Sample-Account Purge
    |--------------------------------------------------------------------------
    |
    | Policy for `sso:purge-fake-users`, which clears the throwaway crew logins
    | a trial signup creates once SSO confirms the company has converted.
    |
    | The command discovers references to those accounts from the schema. It
    | unlinks whatever it finds (never deletes the record) unless the table is
    | listed in `pivot_tables`, where the row IS the membership and is removed.
    | A NOT NULL reference in no list is reported as a blocker and aborts the
    | run — add the table here if the row is pure membership, otherwise make
    | the column nullable. Apps append their own: Billing `agency_user`,
    | Crew-Scheduling `time_off_policy_user`, Drug-Tracking
    | `location_checkout_members`.
    |
    */
    'fake_user_purge' => [
        'users_table' => 'users',
        'companies_table' => 'companies',

        'pivot_tables' => [
            'company_user',
            'company_user_roles',
            'model_has_roles',
            'model_has_permissions',
            'notifications',
            'sessions',
            'oauth_access_tokens',
        ],

        // Columns that reference users by convention rather than a declared
        // foreign key, which is how most of these apps are built.
        'reference_columns' => [
            'user_id',
            'created_by',
            'updated_by',
        ],

        // table => [id column, type column] for polymorphic references, so a
        // row belonging to another morph type is never touched.
        'polymorphic' => [
            'notifications' => ['notifiable_id', 'notifiable_type'],
            'model_has_roles' => ['model_id', 'model_type'],
            'model_has_permissions' => ['model_id', 'model_type'],
        ],
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

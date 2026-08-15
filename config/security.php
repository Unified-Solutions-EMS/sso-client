<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Kill switch / test-suite default
    |--------------------------------------------------------------------------
    |
    | Null (the default) means: enabled everywhere except while running unit
    | tests, where real HTTP emits from the sync queue would slow suites and
    | pollute SSO. Set true/false to force either way.
    |
    */

    'enabled' => env('SECURITY_EVENTS_ENABLED'),

    /*
    |--------------------------------------------------------------------------
    | Ingest endpoint + token
    |--------------------------------------------------------------------------
    |
    | Where security events are sent. When `endpoint` is null the package
    | derives it from `sso.base_url` (SSO's /api/internal/security-events/ingest
    | route), so apps already wired to SSO need zero extra config. The token
    | is the platform's shared CORE_APP_API_KEY — per convention, no
    | per-feature secrets.
    |
    */

    'endpoint' => env('SECURITY_EVENTS_ENDPOINT'),

    'token' => env('CORE_APP_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | App key
    |--------------------------------------------------------------------------
    |
    | Identifies the sending app in SSO's security_events table. Falls back
    | to METRICS_APP_KEY and then to the SSO app slug, so most apps never
    | set this explicitly.
    |
    */

    'app_key' => env('SECURITY_APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Auth event listeners
    |--------------------------------------------------------------------------
    |
    | When enabled the package listens for Laravel's Failed / Lockout /
    | PasswordReset auth events and records them automatically. Disable
    | only if the app records its own auth security events.
    |
    */

    'listen_auth_events' => env('SECURITY_LISTEN_AUTH_EVENTS', true),

    /*
    |--------------------------------------------------------------------------
    | Honeytokens
    |--------------------------------------------------------------------------
    |
    | `honeytokens` is a comma-separated list of decoy API keys. They are
    | never valid credentials; any request presenting one is recorded as a
    | critical honeytoken.api_key_used event — a guaranteed-true-positive
    | signal that a planted credential has been harvested.
    |
    | `canary_emails` is a comma-separated list of decoy account emails.
    | Any login attempt against one records a critical
    | auth.canary_login_attempt event.
    |
    */

    'honeytokens' => env('SECURITY_HONEYTOKEN_KEYS'),

    'canary_emails' => env('SECURITY_CANARY_EMAILS'),

    /*
    |--------------------------------------------------------------------------
    | TLS verification / queue
    |--------------------------------------------------------------------------
    */

    'verify_ssl' => env('SECURITY_EVENTS_VERIFY_SSL', env('METRICS_VERIFY_SSL', true)),

    'queue_connection' => env('SECURITY_QUEUE_CONNECTION', env('METRICS_QUEUE_CONNECTION')),
    'queue' => env('SECURITY_QUEUE', env('METRICS_QUEUE')),

];

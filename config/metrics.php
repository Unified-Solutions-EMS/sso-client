<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Ingest endpoint + token
    |--------------------------------------------------------------------------
    |
    | The fully-qualified URL of SSO's /api/internal/metrics/ingest route
    | and the platform's shared CORE_APP_API_KEY. Both are required for
    | the queued send to succeed; if either is missing the job logs a
    | warning and silently no-ops.
    |
    | Per platform convention, all cross-app server-to-server calls use
    | CORE_APP_API_KEY rather than introducing a per-feature secret.
    |
    */

    'endpoint' => env('METRICS_ENDPOINT'),

    'token' => env('CORE_APP_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | App key
    |--------------------------------------------------------------------------
    |
    | Identifies the sending app in the rollup table — e.g. "crew",
    | "cloudpcr", "fire", "hr", "reporting". Pick a stable short slug;
    | downstream dashboards group on this.
    |
    */

    'app_key' => env('METRICS_APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | TLS verification
    |--------------------------------------------------------------------------
    |
    | Disable only for local dev against a self-signed SSO cert.
    |
    */

    'verify_ssl' => env('METRICS_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Local → SSO id translation
    |--------------------------------------------------------------------------
    |
    | The package translates the consuming app's local company / user ids
    | to the corresponding SSO ids before sending — per DEV_GUIDELINES §4
    | we do not conflate sso_company_id with each app's local company_id.
    |
    | The default EloquentMetricContextResolver looks up the configured
    | model and reads the configured columns. Apps with non-standard
    | schemas can bind a custom MetricContextResolver in their service
    | provider.
    |
    */

    'company_model' => env('METRICS_COMPANY_MODEL', 'App\\Models\\Company'),
    'user_model' => env('METRICS_USER_MODEL', 'App\\Models\\User'),

    'company_sso_id_column' => env('METRICS_COMPANY_SSO_ID_COLUMN', 'sso_company_id'),
    'user_sso_id_column' => env('METRICS_USER_SSO_ID_COLUMN', 'sso_id'),

    /*
    |--------------------------------------------------------------------------
    | Session-metric dedupe window
    |--------------------------------------------------------------------------
    |
    | TrackSessionMetric records one "session.start" event per user every
    | N minutes — a heartbeat, not a per-request counter. Keeps the
    | metric_events firehose shaped right for "is the user actively using
    | the app right now" queries.
    |
    */

    'session_dedupe_minutes' => env('METRICS_SESSION_DEDUPE_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Queue connection / queue
    |--------------------------------------------------------------------------
    |
    | Where SendMetricToUnified runs. Leave null to use the app default.
    |
    */

    'queue_connection' => env('METRICS_QUEUE_CONNECTION'),
    'queue' => env('METRICS_QUEUE'),

];

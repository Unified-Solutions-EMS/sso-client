<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shared security headers (UNI-1778)
|--------------------------------------------------------------------------
|
| Read by Unified\SsoClient\Middleware\SecurityHeaders, which an app appends
| to its `web` group (alias `sso.security-headers`). Nothing here changes an
| app until it adds the middleware, except `force_https`, which the service
| provider applies at boot. The middleware never overwrites a header the
| app (or a route middleware) already set on the response.
|
| See "Security headers" in the package CLAUDE.md for the rollout order.
|
*/

return [

    // Kill switch for the middleware. False makes it a no-op.
    'enabled' => env('SECURITY_HEADERS_ENABLED', true),

    /*
    | Strict-Transport-Security. Null (the default) means "only when
    | APP_ENV=production". Vapor apps that run live out of their `staging`
    | environment (HR, Truck-Checks, Fleet-Management) set SECURITY_HSTS=true.
    | One year, includeSubDomains, never `preload` (preload is a one-way
    | commitment made at the DNS-zone level, not per app).
    */
    'hsts' => [
        'enabled' => env('SECURITY_HSTS'),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
    ],

    /*
    | URL::forceScheme('https') at boot. Null (the default) means "only when
    | APP_ENV=production"; same staging-as-live note as HSTS.
    */
    'force_https' => env('SECURITY_FORCE_HTTPS'),

    'nosniff' => true,

    'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

    /*
    | Frame protection: `Content-Security-Policy: frame-ancestors 'self' ...`
    | plus `X-Frame-Options: SAMEORIGIN` for browsers that predate
    | frame-ancestors (current browsers ignore X-Frame-Options when
    | frame-ancestors is present). 'self' because several apps frame their
    | own pages (claim previews, PDFs, document viewers).
    |
    | `frame_ancestors` is a comma-separated list of extra parent origins.
    | The wiki needs the public website's origins (Unified University is
    | embedded on /university); no other app is framed cross-origin today.
    */
    'frame_protection' => env('SECURITY_FRAME_PROTECTION', true),

    'frame_ancestors' => env('SECURITY_FRAME_ANCESTORS', ''),

    /*
    | Permissions-Policy. Camera and microphone stay available to the app
    | itself (chart photos, license and med scans, mail capture, dictation),
    | as does geolocation (CAD, hydrants, dispatch, mobile web views).
    | Payment is left alone for Stripe. Only features no app uses are off.
    */
    'permissions_policy' => env(
        'SECURITY_PERMISSIONS_POLICY',
        'camera=(self), microphone=(self), geolocation=(self), usb=(), serial=(), hid=(), midi=(), browsing-topics=()'
    ),

    /*
    | Full script/style Content-Security-Policy, REPORT-ONLY. Off by default;
    | turn on per app with SECURITY_CSP_REPORT_ONLY=true, read the
    | csp.violation security events for a while, tune `directives`, and only
    | then consider enforcing (a later, per-app step). `{sso}` in a source
    | list is replaced with the origin of sso.base_url.
    |
    | Violations post to the package's /api/security/csp-report route, which
    | records an info `csp.violation` event for `sample_rate` of reports
    | (blocked host and directive only; never the page URL or a script sample).
    */
    'csp_report_only' => [
        'enabled' => env('SECURITY_CSP_REPORT_ONLY', false),

        'report' => env('SECURITY_CSP_REPORT', true),

        'sample_rate' => (float) env('SECURITY_CSP_REPORT_SAMPLE_RATE', 0.1),

        'directives' => [
            'default-src' => ["'self'"],
            'script-src' => [
                "'self'", "'unsafe-inline'", "'unsafe-eval'",
                'https://maps.googleapis.com', 'https://maps.gstatic.com',
                'https://js.stripe.com', 'https://www.googletagmanager.com',
                'https://us-assets.i.posthog.com', 'https://browser.sentry-cdn.com',
            ],
            'style-src' => [
                "'self'", "'unsafe-inline'",
                'https://fonts.googleapis.com', 'https://fonts.bunny.net',
            ],
            'font-src' => [
                "'self'", 'data:',
                'https://fonts.gstatic.com', 'https://fonts.bunny.net',
            ],
            'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
            'media-src' => ["'self'", 'data:', 'blob:'],
            'connect-src' => [
                "'self'", '{sso}',
                'https://maps.googleapis.com',
                'https://*.ingest.sentry.io', 'https://*.ingest.us.sentry.io',
                'https://us.i.posthog.com', 'https://us-assets.i.posthog.com',
                'https://api.stripe.com',
                'wss:',
            ],
            'frame-src' => [
                "'self'",
                'https://js.stripe.com', 'https://hooks.stripe.com',
                'https://www.youtube.com', 'https://www.youtube-nocookie.com', 'https://player.vimeo.com',
            ],
            'worker-src' => ["'self'", 'blob:'],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'", '{sso}'],
        ],
    ],

];

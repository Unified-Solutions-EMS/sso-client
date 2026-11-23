<?php

declare(strict_types=1);

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Unified\SsoClient\Security\CorsOrigins;

/**
 * Baseline browser security headers for an app's `web` group (UNI-1778).
 *
 *   ->withMiddleware(fn (Middleware $m) => $m->appendToGroup('web', 'sso.security-headers'))
 *
 * Every header is config-driven (config/security-headers.php) and only set
 * when the response does not already carry it, so a route that sets its own
 * framing or CSP (Billing's `frame.self` previews) keeps its value.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('security-headers.enabled', true)) {
            return $response;
        }

        if (config('security-headers.nosniff', true)) {
            $this->setIfMissing($response, 'X-Content-Type-Options', 'nosniff');
        }

        $this->setIfMissing($response, 'Referrer-Policy', (string) config('security-headers.referrer_policy', ''));

        if (config('security-headers.frame_protection', true)) {
            $this->setIfMissing($response, 'Content-Security-Policy', 'frame-ancestors '.implode(' ', $this->frameAncestors()));
            $this->setIfMissing($response, 'X-Frame-Options', 'SAMEORIGIN');
        }

        $this->setIfMissing($response, 'Permissions-Policy', (string) config('security-headers.permissions_policy', ''));

        if ($this->hstsEnabled()) {
            $this->setIfMissing($response, 'Strict-Transport-Security', $this->hstsValue());
        }

        if (config('security-headers.csp_report_only.enabled', false)) {
            $this->setIfMissing($response, 'Content-Security-Policy-Report-Only', $this->reportOnlyPolicy());
        }

        return $response;
    }

    protected function setIfMissing(Response $response, string $name, string $value): void
    {
        if ($value === '' || $response->headers->has($name)) {
            return;
        }

        $response->headers->set($name, $value);
    }

    /**
     * @return list<string>
     */
    protected function frameAncestors(): array
    {
        $extra = CorsOrigins::allowed((string) config('security-headers.frame_ancestors', ''));

        return ["'self'", ...$extra];
    }

    protected function hstsEnabled(): bool
    {
        $enabled = config('security-headers.hsts.enabled');

        if ($enabled === null || $enabled === '') {
            return app()->isProduction();
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    protected function hstsValue(): string
    {
        $value = 'max-age='.(int) config('security-headers.hsts.max_age', 31536000);

        if (filter_var(config('security-headers.hsts.include_subdomains', true), FILTER_VALIDATE_BOOLEAN)) {
            $value .= '; includeSubDomains';
        }

        return $value;
    }

    protected function reportOnlyPolicy(): string
    {
        $sso = CorsOrigins::origin((string) config('sso.base_url', ''));
        $directives = [];

        foreach ((array) config('security-headers.csp_report_only.directives', []) as $directive => $sources) {
            $sources = array_values(array_filter(
                array_map(fn (string $source): ?string => $source === '{sso}' ? $sso : $source, (array) $sources),
            ));

            $directives[] = trim($directive.' '.implode(' ', array_unique($sources)));
        }

        if (config('security-headers.csp_report_only.report', true)) {
            $directives[] = 'report-uri '.route('sso.csp-report', [], false);
        }

        return implode('; ', $directives);
    }
}

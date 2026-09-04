<?php

declare(strict_types=1);

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expires cookies left behind by the legacy ASP.NET application.
 *
 * The legacy host set several cookies on `.unified-apps.com` (see
 * CloudPCR.Web.Mvc/Startup/Startup.cs, which reads App:Domain and assigns
 * `options.Cookie.Domain = ".{domain}"`, and the JS `abp.domain` writes). A
 * parent-domain cookie is sent to every subdomain, so long after a customer
 * moved off the legacy app their browser keeps shipping those cookies to every
 * Laravel app on the platform. That is dead weight on every request and, once
 * it is large enough, a 400 "Request Header Or Cookie Too Large" the customer
 * can only fix by clearing their browser.
 *
 * The legacy app's own logout tried to clear these, but it wrote host-only
 * deletions with no domain attribute, so the parent-domain copies survived.
 * This middleware writes both variants.
 *
 * Runs on every `web` request in every app. Clean requests cost one
 * `isset()` on the cookie bag and return untouched.
 */
class PurgeLegacyApexCookies
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $names = $this->purgeableNames($request);

        if ($names === []) {
            return $response;
        }

        $apexDomain = (string) config('sso.legacy_cookies.apex_domain', '.unified-apps.com');
        $secure = $request->isSecure();

        foreach ($names as $name) {
            // Host-only and parent-domain copies are distinct cookies to the
            // browser and each needs its own expiry.
            $response->headers->setCookie($this->expired($name, null, $secure));

            if ($apexDomain !== '') {
                $response->headers->setCookie($this->expired($name, $apexDomain, $secure));
            }
        }

        return $response;
    }

    /**
     * The configured legacy names actually present on this request, minus
     * anything that could be a cookie this app owns.
     *
     * @return array<int, string>
     */
    protected function purgeableNames(Request $request): array
    {
        $configured = (array) config('sso.legacy_cookies.names', []);

        if ($configured === []) {
            return [];
        }

        $protected = $this->protectedNames();

        $names = [];
        foreach ($configured as $name) {
            $name = (string) $name;

            if ($name === '' || ! $request->cookies->has($name)) {
                continue;
            }

            // Belt and braces: expiring the host app's own session, CSRF or
            // remember cookie would log every user out on every request. No
            // configured list gets to do that, however it was edited.
            if (
                in_array($name, $protected, true)
                || str_ends_with($name, '_session')
                || str_starts_with($name, 'remember_web')
            ) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @return array<int, string>
     */
    protected function protectedNames(): array
    {
        return array_values(array_filter([
            (string) config('session.cookie'),
            'XSRF-TOKEN',
            'laravel_session',
        ]));
    }

    protected function expired(string $name, ?string $domain, bool $secure): Cookie
    {
        // Value and flags are irrelevant to deletion; the browser matches on
        // name + domain + path, and a past expiry drops the cookie.
        return Cookie::create(
            name: $name,
            value: '',
            expire: 1,
            path: '/',
            domain: $domain,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}

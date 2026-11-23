<?php

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Unified\SsoClient\Security\SecurityEvents;

/**
 * Validates CORE_APP_API_KEY for cross-app internal endpoints registered
 * by this package. Mirrors the per-app App\Http\Middleware\ValidateCoreApiKey
 * implementations so behavior is identical, but lives here so package routes
 * don't depend on each app having registered the `core.api` alias.
 *
 * During a key rotation the key that was current before it
 * (config('sso.core_api_key_previous')) is accepted too, so receiving apps
 * can be switched one at a time without rejecting callers that have not
 * moved yet. See "Rotating CORE_APP_API_KEY" in the package CLAUDE.md.
 */
class ValidateCoreApiKey
{
    /**
     * One previous-key event per route per window is enough to see whether
     * traffic has moved; one per request would double every internal call's
     * outbound HTTP on apps that run the send job on a sync queue.
     */
    private const PREVIOUS_KEY_EVENT_WINDOW_SECONDS = 300;

    public function handle(Request $request, Closure $next)
    {
        $current = (string) config('app.core_api_key', '');

        if ($current === '') {
            return response()->json(['error' => 'Service not configured'], 503);
        }

        $provided = $this->extractKey($request);

        if ($this->matches($current, $provided)) {
            return $next($request);
        }

        if ($this->matches((string) config('sso.core_api_key_previous', ''), $provided)) {
            $this->recordPreviousKeyUse($request);

            return $next($request);
        }

        $this->recordFailure($provided);

        return response()->json(['error' => 'Unauthorized'], 401);
    }

    protected function extractKey(Request $request): ?string
    {
        $authorization = $request->header('Authorization');

        if ($authorization) {
            if (str_starts_with(strtolower($authorization), 'bearer ')) {
                return substr($authorization, 7);
            }

            return $authorization;
        }

        return $request->header('X-API-KEY');
    }

    /**
     * An empty expected or provided key never matches, so an unset previous
     * key cannot let a request with no key (or an empty one) through.
     */
    protected function matches(string $expected, ?string $provided): bool
    {
        if ($expected === '' || $provided === null || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    protected function recordPreviousKeyUse(Request $request): void
    {
        $route = (string) ($request->route()?->getName() ?? $request->path());

        try {
            $first = Cache::add(
                'sso-client:previous-core-key-used:'.sha1($route),
                true,
                self::PREVIOUS_KEY_EVENT_WINDOW_SECONDS,
            );
        } catch (Throwable) {
            $first = true;
        }

        if ($first) {
            app(SecurityEvents::class)->info('internal_api.previous_key_used', ['route' => $route]);
        }
    }

    protected function recordFailure(?string $provided): void
    {
        $security = app(SecurityEvents::class);

        if ($provided && $security->isHoneytoken($provided)) {
            $security->critical('honeytoken.api_key_used');

            return;
        }

        $security->warning('internal_api.auth_failed', [
            'key_provided' => (bool) $provided,
        ]);
    }
}

<?php

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Unified\SsoClient\Security\SecurityEvents;

/**
 * Validates CORE_APP_API_KEY for cross-app internal endpoints registered
 * by this package. Mirrors the per-app App\Http\Middleware\ValidateCoreApiKey
 * implementations so behavior is identical, but lives here so package routes
 * don't depend on each app having registered the `core.api` alias.
 */
class ValidateCoreApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $expected = config('app.core_api_key');

        if (empty($expected)) {
            return response()->json(['error' => 'Service not configured'], 503);
        }

        $provided = $this->extractKey($request);

        if (! $provided || ! hash_equals((string) $expected, (string) $provided)) {
            $this->recordFailure($provided);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
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

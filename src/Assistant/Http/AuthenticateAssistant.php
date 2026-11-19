<?php

namespace Unified\SsoClient\Assistant\Http;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Unified\SsoClient\Assistant\AssistantVisitor;

/**
 * Stands in for `auth` on the proxy routes. A signed-in user passes as
 * before. A guest passes only when this app runs the Assistant for website
 * visitors (sso.assistant.visitor), limited to 60 requests a minute per IP;
 * otherwise the request goes through Laravel's own `auth` middleware and is
 * refused exactly as it was.
 */
class AuthenticateAssistant
{
    public const VISITOR_PER_MINUTE = 60;

    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null || ! AssistantVisitor::enabled()) {
            return app(Authenticate::class)->handle($request, $next);
        }

        $key = 'sso-assistant-visitor:'.$request->ip();

        if ($this->limiter->tooManyAttempts($key, self::VISITOR_PER_MINUTE)) {
            return response()->json(
                ['error' => 'Too many messages at once. Try again in a minute.'],
                429,
                ['Retry-After' => (string) $this->limiter->availableIn($key)],
            );
        }

        $this->limiter->hit($key, 60);

        return $next($request);
    }
}

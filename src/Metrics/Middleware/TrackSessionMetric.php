<?php

declare(strict_types=1);

namespace Unified\SsoClient\Metrics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Unified\SsoClient\Metrics\Facades\Metrics;

/**
 * Records one "session.start" metric per authenticated user every
 * METRICS_SESSION_DEDUPE_MINUTES (default 30) — a heartbeat, not a
 * per-request counter.
 *
 * Apps opt in by appending the alias to the `web` middleware group,
 * e.g. in bootstrap/app.php:
 *
 *   $middleware->appendToGroup('web', \Unified\SsoClient\Metrics\Middleware\TrackSessionMetric::class);
 */
class TrackSessionMetric
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = Auth::user();
        if ($user === null) {
            return $response;
        }

        $minutes = (int) config('metrics.session_dedupe_minutes', 30);
        $cacheKey = 'metrics:sessions:user:'.$user->getAuthIdentifier();

        if (Cache::add($cacheKey, true, Carbon::now()->addMinutes($minutes))) {
            Metrics::increment('session.start', context: [
                'route' => $request->route()?->getName() ?? $request->path(),
            ]);
        }

        return $response;
    }
}

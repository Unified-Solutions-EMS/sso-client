<?php

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\SsoSessionState;

class EnsureSsoSessionIsFresh
{
    public function __construct(
        protected SsoClient $ssoClient,
        protected SsoSessionState $sessionState,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        // Allow local dev auth bypass
        if (config('sso.enable_local_dev_auth') && app()->environment('local')) {
            return $next($request);
        }

        // If no access token stored, user needs to authenticate via SSO
        if (! $this->sessionState->getAccessToken()) {
            return $this->handleUnauthenticated($request);
        }

        // If token is expired, try to refresh
        if ($this->sessionState->isTokenExpired()) {
            $refreshToken = $this->sessionState->getRefreshToken();

            if (! $refreshToken) {
                Log::info('SSO session: token expired, no refresh token');

                return $this->handleUnauthenticated($request);
            }

            try {
                $tokens = $this->ssoClient->refreshToken($refreshToken);

                $this->sessionState->storeTokens(
                    $tokens['access_token'],
                    $tokens['refresh_token'] ?? $refreshToken,
                    $tokens['expires_in'] ?? 3600,
                );
            } catch (\Throwable $e) {
                Log::warning('SSO session: token refresh failed', ['message' => $e->getMessage()]);

                return $this->handleUnauthenticated($request);
            }
        }

        return $next($request);
    }

    protected function handleUnauthenticated(Request $request)
    {
        Auth::logout();
        $this->sessionState->forget();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $redirectRoute = config('sso.routes.redirect', '/auth/sso/redirect');

        return redirect()->to($redirectRoute.'?intended='.urlencode($request->fullUrl()));
    }
}

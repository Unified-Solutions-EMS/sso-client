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
            if (! $this->attemptTokenRefresh()) {
                return $this->handleUnauthenticated($request);
            }
        }

        // Periodically validate the token against the SSO server to detect
        // revoked tokens (e.g. user logged out of SSO). This ensures that
        // logout propagates to all apps within ~2 minutes.
        if ($this->sessionState->needsServerValidation()) {
            try {
                $this->ssoClient->fetchUser($this->sessionState->getAccessToken());
                $this->sessionState->markTokenValidated();
            } catch (\Throwable $e) {
                Log::info('SSO session: server-side token validation failed, attempting refresh', [
                    'message' => $e->getMessage(),
                ]);

                // Token may have been revoked — try refreshing
                if (! $this->attemptTokenRefresh()) {
                    // On transient network failures, allow one grace period before
                    // logging the user out. Only unauthenticate if validation has
                    // not succeeded for an extended period (2x the normal interval).
                    $lastValidated = $this->sessionState->getLastValidatedAt();
                    $graceSeconds = config('sso.validation_grace_seconds', 300);

                    if ($lastValidated && (now()->timestamp - $lastValidated) < $graceSeconds) {
                        Log::info('SSO session: token refresh failed, within grace period — allowing request');

                        return $next($request);
                    }

                    return $this->handleUnauthenticated($request);
                }

                $this->sessionState->markTokenValidated();
            }
        }

        return $next($request);
    }

    protected function attemptTokenRefresh(): bool
    {
        $refreshToken = $this->sessionState->getRefreshToken();

        if (! $refreshToken) {
            Log::info('SSO session: token expired, no refresh token');

            return false;
        }

        try {
            $tokens = $this->ssoClient->refreshToken($refreshToken);

            $this->sessionState->storeTokens(
                $tokens['access_token'],
                $tokens['refresh_token'] ?? $refreshToken,
                $tokens['expires_in'] ?? 3600,
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('SSO session: token refresh failed', ['message' => $e->getMessage()]);

            return false;
        }
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

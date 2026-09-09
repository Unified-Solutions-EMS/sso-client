<?php

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\SsoSessionState;
use Unified\SsoClient\SsoSingleFlight;

class EnsureSsoSessionIsFresh
{
    public function __construct(
        protected SsoClient $ssoClient,
        protected SsoSessionState $sessionState,
        protected SsoSingleFlight $singleFlight,
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

        // Cheap defensive check: ensure the locally authenticated user still
        // corresponds to the SSO user stored in this session. If a developer
        // (or a stale cookie) ever caused these to drift, force re-auth rather
        // than serving another user's data.
        $expectedSsoUserId = $this->sessionState->getSsoUserId();
        if (Auth::check() && $expectedSsoUserId !== null) {
            $localSsoId = Auth::user()->sso_id ?? null;
            if ($localSsoId !== null && (string) $localSsoId !== (string) $expectedSsoUserId) {
                Log::warning('SSO session: local Auth user does not match session SSO user id, forcing re-auth', [
                    'auth_user_sso_id' => $localSsoId,
                    'session_sso_user_id' => $expectedSsoUserId,
                ]);

                return $this->handleUnauthenticated($request);
            }
        }

        // Validate the token against the SSO server to detect revoked tokens
        // (e.g. user logged out of SSO and a different user logged in on the
        // same browser). The interval is intentionally short — see
        // SsoSessionState::needsServerValidation() — so logout propagates to
        // every app within seconds rather than minutes.
        if ($this->sessionState->needsServerValidation()) {
            try {
                $payload = $this->ssoClient->fetchUser($this->sessionState->getAccessToken());

                // Defensive: confirm the SSO server still considers this token
                // to belong to the user we stored on login. If they differ,
                // somebody else now owns this token — bail out immediately.
                $returnedId = $payload['user']['id'] ?? $payload['id'] ?? null;
                if ($expectedSsoUserId !== null && $returnedId !== null && (string) $returnedId !== (string) $expectedSsoUserId) {
                    Log::warning('SSO session: token validated but returned a different user, forcing re-auth', [
                        'expected' => $expectedSsoUserId,
                        'returned' => $returnedId,
                    ]);

                    return $this->handleUnauthenticated($request);
                }

                $this->sessionState->markTokenValidated();
            } catch (\Throwable $e) {
                Log::info('SSO session: server-side token validation failed, attempting refresh', [
                    'message' => $e->getMessage(),
                ]);

                // Token may have been revoked — try refreshing
                if (! $this->attemptTokenRefresh()) {
                    // On transient network failures, allow one grace period before
                    // logging the user out. Only unauthenticate if validation has
                    // not succeeded for an extended period.
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

        // Passport rotates refresh tokens, so this exchange may only happen
        // once per token. A polling dashboard has several requests in flight
        // when the access token ages out; they all carry the same refresh
        // token, and without coordination the losers present one SSO has
        // already revoked, get told no, and sign the user out mid-shift over a
        // session that is in fact perfectly healthy (UNI-455). Single-flight
        // means one request does the exchange and the rest store its result.
        try {
            $tokens = $this->singleFlight->refreshTokens(
                $refreshToken,
                fn (): array => $this->ssoClient->refreshToken($refreshToken),
            );
        } catch (\Throwable $e) {
            Log::warning('SSO session: token refresh failed', ['message' => $e->getMessage()]);

            return false;
        }

        if ($tokens === null) {
            Log::info('SSO session: another request holds this refresh token and published no result');

            return false;
        }

        $this->sessionState->storeTokens(
            $tokens['access_token'],
            $tokens['refresh_token'] ?? $refreshToken,
            $tokens['expires_in'] ?? 3600,
        );

        return true;
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

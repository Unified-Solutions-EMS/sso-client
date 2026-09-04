<?php

namespace Unified\SsoClient\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\SsoSessionState;

class SsoCallbackController extends Controller
{
    public function __construct(
        protected SsoClient $ssoClient,
        protected SsoSessionState $sessionState,
        protected SsoUserSynchronizerContract $synchronizer,
    ) {}

    /**
     * Redirect to the SSO authorization page.
     */
    public function redirect(Request $request)
    {
        // Store intended URL for post-login redirect
        $intendedUrl = $request->query('intended', url('/dashboard'));
        $this->sessionState->storeIntendedUrl($intendedUrl);

        $auth = $this->ssoClient->buildAuthorizeUrl();

        $this->sessionState->storeOAuthState($auth['state'], $auth['code_verifier']);

        return redirect()->away($auth['url']);
    }

    /**
     * Handle the OAuth callback from the SSO server.
     */
    public function callback(Request $request)
    {
        // Verify state to prevent CSRF
        $expectedState = $this->sessionState->getOAuthState();

        if (! $expectedState || $request->query('state') !== $expectedState) {
            Log::warning('SSO callback: state mismatch', [
                'expected' => $expectedState ? substr($expectedState, 0, 8).'...' : 'null',
                'received' => $request->query('state') ? substr($request->query('state'), 0, 8).'...' : 'null',
            ]);

            // A state mismatch loops exactly like a sync failure does when the
            // session cannot hold the state it just wrote: login redirects out,
            // SSO answers immediately from its own live session, and the state
            // is gone again on the way back.
            return $this->failCallback('Sign in failed. Please try again.');
        }

        $code = $request->query('code');

        if (! $code) {
            Log::warning('SSO callback: no authorization code received', [
                'error' => $request->query('error'),
                'error_description' => $request->query('error_description'),
            ]);

            return $this->failCallback('Sign in was cancelled or failed.');
        }

        try {
            // Exchange code for tokens
            $tokens = $this->ssoClient->exchangeCode($code, $this->sessionState->getCodeVerifier());

            // Fetch user profile from SSO
            $payload = $this->ssoClient->fetchUser($tokens['access_token']);

            // Synchronize user/company/roles into local database
            [$user, $company] = $this->synchronizer->synchronize($payload);

            if (! $user) {
                Log::error('SSO callback: synchronizer returned no user');

                return $this->failCallback('We could not finish setting up your account. Please contact support.');
            }

            // Capture the post-login redirect target BEFORE any session reset
            // below. The intended URL was stored on the prior /redirect request
            // (e.g. an impersonation deep link to /university/my-training), and
            // session()->invalidate() on the user-switch path would otherwise
            // wipe it, silently dropping the user on the default /dashboard.
            $intendedUrl = $this->sessionState->pullIntendedUrl('/dashboard');

            // If a different user was previously logged into this browser
            // session (e.g. user A logged out of SSO and user B is now coming
            // through callback on the same browser, or an admin opening a
            // downstream app while impersonating), wipe the old session
            // entirely before logging the new user in. Otherwise stale
            // session keys from the previous user can leak across the
            // boundary.
            $previousAuthId = Auth::id();
            if ($previousAuthId !== null && (int) $previousAuthId !== (int) $user->id) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            } else {
                // Always rotate the session id on login to prevent fixation.
                $request->session()->regenerate();
            }

            // Log in locally
            Auth::login($user, true);

            // The trip completed: forget any earlier failures so a later
            // unrelated hiccup starts from zero.
            $this->sessionState->clearCallbackFailures();

            // Store tokens AFTER the session reset — storing before would put
            // them in the about-to-be-invalidated session, leaving the freshly
            // logged-in user with no SSO tokens on the user-switch path.
            $this->sessionState->storeTokens(
                $tokens['access_token'],
                $tokens['refresh_token'] ?? null,
                $tokens['expires_in'] ?? 3600,
            );

            // Store SSO user ID and selected company in session
            $this->sessionState->storeSsoUserId($payload['user']['id'] ?? $user->id);

            if ($company) {
                $this->sessionState->storeSelectedCompanyId($company->id);
            }

            // Load company-scoped roles if the user model supports it
            if ($company && method_exists($user, 'loadRolesForCompany')) {
                $user->loadRolesForCompany($company->id);
            }

            return redirect()->to($intendedUrl);

        } catch (\Throwable $e) {
            Log::error('SSO callback error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->failCallback('Sign in failed. Please try again.', $e);
        }
    }

    /**
     * End a failed callback without setting up an invisible redirect loop.
     *
     * Bouncing to the login route re-enters the SSO flow, and SSO answers
     * instantly from its own still-valid session, so any failure that repeats
     * deterministically (UNI-416: a company link collision thrown inside the
     * sync transaction) becomes ERR_TOO_MANY_REDIRECTS with nothing in Sentry
     * and only the app log to show for it.
     *
     * So: report the exception, count consecutive failures, and once the
     * counter trips render a real error page instead of redirecting. The
     * counter clears on the next success and ages out of its own window.
     *
     * Edge case worth naming: if the session store itself is what is broken,
     * the counter cannot persist either and the breaker never trips. That is
     * accepted. The counter is a loop breaker, not a session repair — the
     * state-mismatch branch above is the signal for that failure mode.
     */
    protected function failCallback(string $userMessage, ?\Throwable $e = null)
    {
        if ($e !== null) {
            // Hand the exception to the app's handler so Sentry sees it. The
            // old code only wrote to the log, which is why the loop went
            // unnoticed for as long as it did.
            report($e);
        }

        $failures = $this->sessionState->recordCallbackFailure();
        $threshold = (int) config('sso.callback_failure_threshold', 3);

        if ($failures < $threshold) {
            return redirect()->route('login')->with('error', $userMessage);
        }

        Log::error('SSO callback: consecutive failures tripped the loop breaker', [
            'failures' => $failures,
            'threshold' => $threshold,
        ]);

        return response()->view('sso::sign-in-failed', ['message' => $userMessage], 500);
    }

    /**
     * Log out locally and redirect to SSO logout.
     */
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->sessionState->forget();

        $redirectUri = url('/login');
        $logoutUrl = $this->ssoClient->buildLogoutUrl($redirectUri);

        return redirect()->away($logoutUrl);
    }
}

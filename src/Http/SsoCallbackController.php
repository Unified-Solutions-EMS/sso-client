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

            return redirect()->route('login')->with('error', 'SSO authentication failed. Please try again.');
        }

        $code = $request->query('code');

        if (! $code) {
            Log::warning('SSO callback: no authorization code received', [
                'error' => $request->query('error'),
                'error_description' => $request->query('error_description'),
            ]);

            return redirect()->route('login')->with('error', 'SSO authentication was cancelled or failed.');
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

                return redirect()->route('login')->with('error', 'Failed to sync your account. Please contact support.');
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

            return redirect()->route('login')->with('error', 'SSO authentication failed. Please try again.');
        }
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

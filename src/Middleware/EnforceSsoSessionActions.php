<?php

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Unified\SsoClient\Models\SsoSessionAction;
use Unified\SsoClient\SsoSessionState;

/**
 * Drain pending session actions for the current user. Webhooks from
 * SSO write rows in `sso_session_actions`; this middleware reads them
 * on the user's next request and applies the side effects.
 *
 * Two action kinds today:
 *   - force_logout: kill the local session and bounce through SSO logout
 *     so every app's session for this user gets cleared.
 *   - set_company: SSO impersonation said "this user should now be
 *     scoped to company X" — we update selected_company_id and load the
 *     correct company-scoped roles.
 *
 * Runs only on authenticated requests; unauthenticated traffic has
 * nothing to apply. Safe to register in the global `web` group.
 */
class EnforceSsoSessionActions
{
    public function __construct(protected SsoSessionState $sessionState) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $userId = (int) Auth::id();

        $actions = SsoSessionAction::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get();

        if ($actions->isEmpty()) {
            return $next($request);
        }

        $forceLogout = false;
        $setCompanyId = null;

        foreach ($actions as $action) {
            if ($action->action === SsoSessionAction::ACTION_FORCE_LOGOUT) {
                $forceLogout = true;
            } elseif ($action->action === SsoSessionAction::ACTION_SET_COMPANY) {
                $setCompanyId = (int) ($action->payload['company_id'] ?? 0);
            }

            $action->delete();
        }

        if ($forceLogout) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $ssoUrl = rtrim((string) config('sso.server_url'), '/');
            if ($ssoUrl) {
                return redirect()->away($ssoUrl.'/auth/logout');
            }

            return redirect('/login');
        }

        if ($setCompanyId !== null && $setCompanyId > 0) {
            $this->sessionState->storeSelectedCompanyId($setCompanyId);

            $user = Auth::user();
            if ($user && method_exists($user, 'loadRolesForCompany')) {
                $user->loadRolesForCompany($setCompanyId);
            }
        }

        return $next($request);
    }
}

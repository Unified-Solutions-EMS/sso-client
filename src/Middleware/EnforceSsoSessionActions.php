<?php

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Unified\SsoClient\Concerns\SyncsCompanyRoles;
use Unified\SsoClient\Models\SsoSessionAction;
use Unified\SsoClient\SsoSessionState;

/**
 * Drain pending session actions for the current user. Webhooks from
 * SSO write rows in `sso_session_actions`; this middleware reads them
 * on the user's next request and applies the side effects.
 *
 * Three action kinds today:
 *   - force_logout: kill the local session and bounce through SSO logout
 *     so every app's session for this user gets cleared.
 *   - set_company: SSO impersonation said "this user should now be
 *     scoped to company X" — we update selected_company_id and load the
 *     correct company-scoped roles.
 *   - reload_roles: SSO changed the user's role in company X. A session
 *     with X selected rewrites Spatie's roles for X before the request
 *     runs, or, when the change was No access, signs out of this app and
 *     goes back through the SSO redirect so the callback picks a company
 *     the person can still use. These rows are not deleted when applied:
 *     each session remembers the highest one it has seen, so every open
 *     session (other browsers, other instances) applies the change once.
 *
 * Runs only on authenticated requests; unauthenticated traffic has
 * nothing to apply. Safe to register in the global `web` group.
 */
class EnforceSsoSessionActions
{
    /** Unix time the callback signed this session in; a sign-out issued before it is not for this session. */
    public const SESSION_SIGNED_IN_AT = 'sso_signed_in_at';

    /** Highest reload_roles action id this session has already applied. */
    public const SESSION_ROLES_SEEN_ID = 'sso_roles_seen_action_id';

    public function __construct(protected SsoSessionState $sessionState) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $userId = (int) Auth::id();

        SsoSessionAction::query()
            ->where('user_id', $userId)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        $actions = SsoSessionAction::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get();

        if ($actions->isEmpty()) {
            return $next($request);
        }

        $signedInAt = (int) $request->session()->get(self::SESSION_SIGNED_IN_AT, 0);
        $rolesSeenId = (int) $request->session()->get(self::SESSION_ROLES_SEEN_ID, 0);
        $forceLogout = false;
        $setCompanyId = null;
        $roleChanges = [];

        foreach ($actions as $action) {
            if ($action->action === SsoSessionAction::ACTION_RELOAD_ROLES) {
                if ((int) $action->getKey() > $rolesSeenId) {
                    $roleChanges[] = $action;
                }

                continue;
            }

            if ($action->action === SsoSessionAction::ACTION_FORCE_LOGOUT) {
                // A sign-out SSO issued before this session was signed in is
                // about an earlier session; this one is newer and stays.
                $issuedAt = strtotime((string) ($action->payload['issued_at'] ?? '')) ?: null;
                if ($issuedAt === null || $signedInAt === 0 || $issuedAt >= $signedInAt) {
                    $forceLogout = true;
                }
            } elseif ($action->action === SsoSessionAction::ACTION_SET_COMPANY) {
                $setCompanyId = (int) ($action->payload['company_id'] ?? 0);
            }

            $action->delete();
        }

        if ($forceLogout) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $ssoUrl = rtrim((string) (config('sso.server_url') ?: config('sso.base_url')), '/');
            if ($ssoUrl) {
                return redirect()->away($ssoUrl.'/auth/logout');
            }

            return redirect()->to(config('sso.routes.redirect', '/auth/sso/redirect').'?intended='.urlencode($request->fullUrl()));
        }

        if ($setCompanyId !== null && $setCompanyId > 0) {
            $this->sessionState->storeSelectedCompanyId($setCompanyId);

            $user = Auth::user();
            if ($user && method_exists($user, 'loadRolesForCompany')) {
                $user->loadRolesForCompany($setCompanyId);
            }
        }

        if ($roleChanges !== [] && $this->applyRoleChanges($request, $roleChanges, $setCompanyId !== null && $setCompanyId > 0)) {
            return $this->leaveCompany($request);
        }

        return $next($request);
    }

    /**
     * Apply the role changes this session has not seen yet. Only a change for
     * the company this session has selected matters: Spatie holds the roles
     * of that one company, and a change elsewhere is picked up by the company
     * switch that would select it. Returns true when the person no longer has
     * access to the selected company.
     *
     * Fail open: if the reload throws, the request goes ahead with the roles
     * it had (today's behavior) and the change is tried again on the next
     * request.
     *
     * @param  list<SsoSessionAction>  $changes
     */
    protected function applyRoleChanges(Request $request, array $changes, bool $rolesJustLoaded): bool
    {
        $selectedCompanyId = (int) $this->sessionState->getSelectedCompanyId();
        $highestId = max(array_map(static fn (SsoSessionAction $action): int => (int) $action->getKey(), $changes));
        $reload = false;
        $removed = false;

        foreach ($changes as $change) {
            if ($selectedCompanyId <= 0 || (int) ($change->payload['company_id'] ?? 0) !== $selectedCompanyId) {
                continue;
            }

            $removed = (bool) ($change->payload['removed'] ?? false);
            $reload = ! $removed;
        }

        if ($reload && ! $rolesJustLoaded) {
            try {
                $this->reloadRoles(Auth::user(), $selectedCompanyId);
            } catch (\Throwable $e) {
                Log::warning('SSO session: could not reload roles after a role change, keeping the current ones', [
                    'user_id' => Auth::id(),
                    'company_id' => $selectedCompanyId,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        $request->session()->put(self::SESSION_ROLES_SEEN_ID, $highestId);

        return $removed;
    }

    /**
     * An app that overrides loadRolesForCompany() keeps its own loading; the
     * package trait's copy is swapped for refreshRolesForCompany(), which
     * leaves the app-wide permission cache alone.
     */
    protected function reloadRoles(mixed $user, int $companyId): void
    {
        if (! $user || ! method_exists($user, 'loadRolesForCompany')) {
            return;
        }

        $ownLoader = (new \ReflectionMethod($user, 'loadRolesForCompany'))->getFileName()
            !== (new \ReflectionClass(SyncsCompanyRoles::class))->getFileName();

        if (! $ownLoader && method_exists($user, 'refreshRolesForCompany')) {
            $user->refreshRolesForCompany($companyId);

            return;
        }

        $user->loadRolesForCompany($companyId);
    }

    /**
     * The person was set to No access for the company this session has
     * selected. Sign out of this app only (never SSO itself, so an
     * impersonating staff member keeps their own SSO session) and go back
     * through the SSO redirect: SSO answers from its live session and the
     * callback selects a company the person can still use, or shows the
     * no-access page when there is none. The removed company is dropped
     * from the session so nothing here keeps reading it meanwhile, and the
     * session keeps the seen marker so the trip back does not bounce again.
     */
    protected function leaveCompany(Request $request): Response
    {
        $removedCompanyId = $this->sessionState->getSelectedCompanyId();

        Auth::logout();
        $this->sessionState->forget();
        $request->session()->forget(SsoSessionState::KEY_SELECTED_COMPANY_ID);

        if ((string) $request->session()->get('impersonated_company_id') === (string) $removedCompanyId) {
            $request->session()->forget('impersonated_company_id');
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->to(config('sso.routes.redirect', '/auth/sso/redirect').'?intended='.urlencode($request->fullUrl()));
    }
}

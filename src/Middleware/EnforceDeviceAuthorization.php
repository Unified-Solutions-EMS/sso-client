<?php

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Unified\SsoClient\Device\DeviceGuard;
use Unified\SsoClient\Device\DeviceSessionState;

/**
 * Blocks a request when the agency has locked this app to authorized devices
 * and the browser hasn't proven (via the Unified device extension) that its
 * device is authorized. Runs after auth + company selection, so the user and
 * their active company are known.
 *
 * Order of decisions (any "yes" lets the request through):
 *   feature off → no user → no active company → app not locked by policy →
 *   policy in grace period → user has a bypass → session already device-verified
 *
 * Otherwise the request is locked: navigations get the handshake interstitial,
 * XHR/Livewire calls get 423 with the interstitial URL so the SPA can redirect.
 */
class EnforceDeviceAuthorization
{
    public function __construct(
        private readonly DeviceGuard $guard,
        private readonly DeviceSessionState $session,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('sso.device.enabled', true)) {
            return $next($request);
        }

        // Never gate the handshake endpoints themselves.
        if ($request->routeIs('sso.device.*')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $company = $this->activeCompany($request);

        if ($company === null) {
            return $next($request);
        }

        $ssoCompanyId = $company->sso_company_id ?? null;

        if ($ssoCompanyId === null) {
            return $next($request);
        }

        $policy = $this->guard->policyFor($ssoCompanyId);

        if ($policy === null) {
            // SSO unreachable: allow or deny per config rather than guessing.
            return config('sso.device.fail_open', true)
                ? $next($request)
                : $this->lock($request, $ssoCompanyId);
        }

        $appSlug = (string) config('sso.app_slug');

        if (! $this->guard->appIsLocked($policy, $appSlug)) {
            return $next($request);
        }

        if ($this->guard->inGracePeriod($policy)) {
            return $next($request);
        }

        if ($this->userHasBypass($user, (int) $company->getKey(), $appSlug)) {
            return $next($request);
        }

        $boundDeviceId = $this->session->boundDeviceIdFor($ssoCompanyId);

        if ($boundDeviceId !== null) {
            if (! $this->guard->deviceIsRevoked($boundDeviceId)) {
                return $next($request);
            }

            // The device.revoked webhook blacklisted this device; drop the
            // binding so the interstitial re-runs a fresh handshake.
            $this->session->clear();
        }

        return $this->lock($request, $ssoCompanyId);
    }

    private function activeCompany(Request $request): ?object
    {
        $companyId = $request->session()->get('selected_company_id');

        if (empty($companyId)) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = config('sso.company_model', config('metrics.company_model', 'App\\Models\\Company'));

        return $model::query()->find($companyId);
    }

    private function userHasBypass(object $user, int $companyId, string $appSlug): bool
    {
        return method_exists($user, 'hasDeviceBypass')
            && $user->hasDeviceBypass($companyId, $appSlug);
    }

    private function lock(Request $request, int|string $ssoCompanyId): Response
    {
        if ($request->expectsJson() || $request->ajax() || $request->hasHeader('X-Livewire')) {
            return response()->json([
                'message' => 'This application is not authorized on this device.',
                'device_authorization_url' => url()->current(),
            ], Response::HTTP_LOCKED);
        }

        return response()->view('sso::device.locked', [
            'appName' => config('app.name'),
            'ssoCompanyId' => $ssoCompanyId,
            'companyName' => $this->activeCompany($request)?->name,
            'intendedUrl' => $request->fullUrl(),
            'challengeUrl' => route('sso.device.challenge'),
            'verifyUrl' => route('sso.device.verify'),
            'registerUrl' => route('sso.device.register'),
        ], Response::HTTP_LOCKED);
    }
}

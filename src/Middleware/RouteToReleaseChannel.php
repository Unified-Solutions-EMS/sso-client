<?php

declare(strict_types=1);

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Unified\SsoClient\Release\ReleaseChannel;
use Unified\SsoClient\SsoSessionState;

/**
 * Two jobs on plain page loads, in this order:
 *
 * 1. The agency hand-across. SSO opens an app with `?sso_company=<SSO
 *    company id>`. When the signed-in session here already has that agency
 *    selected the parameter is dropped (redirect to the clean URL). When it
 *    has another agency, or nobody is signed in, the browser goes through
 *    the normal SSO sign-in with `intended` = the clean URL, so SSO, not the
 *    link, says which agency is selected. The parameter is only ever a hint
 *    that a handshake is needed; it never selects anything itself. This is
 *    also what fixes an app session that outlived an agency switch in SSO
 *    (UNI-1928).
 *
 * 2. The release-channel move. A signed-in person whose selected agency is
 *    on the other shared-database deployment (stable or beta) is sent there,
 *    same path and query plus `sso_company`, so the other deployment can do
 *    its own hand-across.
 *
 * Auto-appended to the web group after EnforceSsoSessionActions, so an
 * impersonation's company change is in the session before either step
 * reads it. Never touches API, Livewire, sign-in or Assistant requests,
 * form posts or background requests; staff admin pages are never moved
 * (`sso.release.never_move`). Nothing moves on alpha, for guests, without a
 * selected agency, or toward a channel with no address. A browser bounced
 * three times in a minute stops being moved and the loop is logged.
 */
class RouteToReleaseChannel
{
    public const LAUNCH_PARAMETER = 'sso_company';

    public const MOVES_KEY = 'sso_release.moves';

    public const HANDOFF_KEY = 'sso_release.handoff';

    public const MAX_MOVES = 3;

    public const WINDOW_SECONDS = 60;

    /** How long a hand-across is remembered for the "same link twice in a row" guard. */
    public const HANDOFF_WINDOW_SECONDS = 300;

    public function __construct(protected SsoSessionState $sessionState) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()
            || (! $request->isMethod('GET') && ! $request->isMethod('HEAD'))
            || $request->is(...$this->untouchedPaths())) {
            return $next($request);
        }

        // An Inertia visit is a background request, but it is how React apps
        // move between pages, so it is moved with Inertia's own full-reload
        // answer. It never carries the launch parameter.
        if ($request->hasHeader('X-Inertia')) {
            return $this->moveIfNeeded($request, $next);
        }

        if ($request->ajax() || $request->expectsJson() || $request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        if ($request->query->has(self::LAUNCH_PARAMETER)) {
            return $this->handAcross($request);
        }

        return $this->moveIfNeeded($request, $next);
    }

    protected function moveIfNeeded(Request $request, Closure $next): Response
    {
        $target = $this->moveTarget($request, (string) $request->getRequestUri());

        if ($target === null || $this->isLooping($request)) {
            return $next($request);
        }

        return $this->moveTo($request, $target);
    }

    /**
     * Decide what a launch link does. Always answers with a redirect: the
     * clean URL, the other deployment, or the SSO sign-in.
     */
    protected function handAcross(Request $request): Response
    {
        $hint = trim((string) $request->query(self::LAUNCH_PARAMETER));
        $cleanUri = $this->requestUriWithout($request);
        $cleanUrl = $request->getSchemeAndHttpHost().$cleanUri;

        // Without a company model there is nothing to compare the hint with;
        // dropping it keeps today's behaviour rather than sending every launch
        // through SSO.
        if (! ctype_digit($hint) || ! class_exists(ReleaseChannel::companyModel())) {
            return redirect()->to($cleanUrl);
        }

        $selected = Auth::check() ? $this->selectedCompany($request) : null;
        $selectedSsoId = $selected !== null ? trim((string) $selected->getAttribute('sso_company_id')) : '';

        if ($selectedSsoId !== '' && $selectedSsoId === $hint) {
            $request->session()->forget(self::HANDOFF_KEY);

            $target = $this->moveTarget($request, $cleanUri);

            if ($target !== null && ! $this->isLooping($request)) {
                return $this->moveTo($request, $target);
            }

            return redirect()->to($cleanUrl);
        }

        if ($this->handedAcrossJustNow($request, $cleanUrl, $hint)) {
            // SSO answered the last handshake for this same link with another
            // agency (the person has no access here to the one named, or SSO
            // changed its mind). SSO is authoritative: serve what it chose.
            Log::warning('RouteToReleaseChannel: SSO selected a different agency than the launch link named, serving the one SSO selected', [
                'launch_sso_company_id' => $hint,
                'selected_sso_company_id' => $selectedSsoId !== '' ? $selectedSsoId : null,
                'user_id' => Auth::id(),
            ]);
            $request->session()->forget(self::HANDOFF_KEY);

            return redirect()->to($cleanUrl);
        }

        $request->session()->put(self::HANDOFF_KEY, ['url' => $cleanUrl, 'sso_company' => $hint, 'at' => time()]);

        return redirect()->to(config('sso.routes.redirect', '/auth/sso/redirect').'?intended='.urlencode($cleanUrl));
    }

    protected function handedAcrossJustNow(Request $request, string $cleanUrl, string $hint): bool
    {
        $last = $request->session()->get(self::HANDOFF_KEY);

        return is_array($last)
            && ($last['url'] ?? null) === $cleanUrl
            && ($last['sso_company'] ?? null) === $hint
            && is_int($last['at'] ?? null)
            && $last['at'] > time() - self::HANDOFF_WINDOW_SECONDS;
    }

    /**
     * Where this page belongs, or null to serve it here.
     */
    protected function moveTarget(Request $request, string $requestUri): ?string
    {
        if (! ReleaseChannel::routingActive() || ! Auth::check() || $request->is(...$this->neverMovedPaths())) {
            return null;
        }

        $company = $this->selectedCompany($request);

        if ($company === null) {
            return null;
        }

        $channel = ReleaseChannel::forCompany($company);
        $base = ReleaseChannel::url($channel);

        if ($channel === ReleaseChannel::current() || $base === null) {
            return null;
        }

        if (strcasecmp((string) parse_url($base, PHP_URL_HOST), $request->getHost()) === 0) {
            return null;
        }

        $ssoCompanyId = trim((string) $company->getAttribute('sso_company_id'));

        if ($ssoCompanyId === '') {
            return $base.$requestUri;
        }

        return $base.$requestUri.(str_contains($requestUri, '?') ? '&' : '?').self::LAUNCH_PARAMETER.'='.rawurlencode($ssoCompanyId);
    }

    protected function moveTo(Request $request, string $target): Response
    {
        if ($request->hasHeader('X-Inertia')) {
            return response('', 409, ['X-Inertia-Location' => $target]);
        }

        return redirect()->away($target);
    }

    protected function selectedCompany(Request $request): ?Model
    {
        $companyId = $this->sessionState->getSelectedCompanyId();

        if ($companyId === null || $companyId === '' || (int) $companyId <= 0) {
            return null;
        }

        $model = ReleaseChannel::companyModel();

        if (! class_exists($model)) {
            return null;
        }

        return $model::query()->whereKey($companyId)->first();
    }

    protected function requestUriWithout(Request $request): string
    {
        $query = Arr::query(Arr::except($request->query(), [self::LAUNCH_PARAMETER]));
        $path = $request->getBaseUrl().$request->getPathInfo();

        return $query === '' ? $path : $path.'?'.$query;
    }

    /**
     * Paths neither step ever acts on.
     *
     * @return list<string>
     */
    protected function untouchedPaths(): array
    {
        $paths = ['api/*', 'up', 'livewire/*'];

        foreach ((array) config('sso.routes', []) as $path) {
            $paths[] = trim((string) $path, '/');
        }

        $assistant = trim((string) config('sso.assistant.path', '/assistant'), '/');
        $paths[] = $assistant;
        $paths[] = $assistant.'/*';

        return array_values(array_filter($paths, static fn (string $path): bool => $path !== '' && $path !== '/*'));
    }

    /**
     * Paths that stay on the deployment they were opened on.
     *
     * @return list<string>
     */
    protected function neverMovedPaths(): array
    {
        $paths = array_map(
            static fn (mixed $path): string => trim((string) $path, '/'),
            (array) config('sso.release.never_move', ['admin', 'admin/*']),
        );

        return array_values(array_filter($paths, static fn (string $path): bool => $path !== ''));
    }

    protected function isLooping(Request $request): bool
    {
        $now = time();
        $recent = array_values(array_filter(
            (array) $request->session()->get(self::MOVES_KEY, []),
            static fn (mixed $at): bool => is_int($at) && $at > $now - self::WINDOW_SECONDS,
        ));

        if (count($recent) >= self::MAX_MOVES) {
            Log::warning('RouteToReleaseChannel: redirect loop detected, serving here', [
                'channel' => ReleaseChannel::current(),
                'company_id' => $this->sessionState->getSelectedCompanyId(),
            ]);

            return true;
        }

        $recent[] = $now;
        $request->session()->put(self::MOVES_KEY, $recent);

        return false;
    }
}

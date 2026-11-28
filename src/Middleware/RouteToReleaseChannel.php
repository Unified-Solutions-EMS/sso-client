<?php

declare(strict_types=1);

namespace Unified\SsoClient\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Inertia\Inertia;
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
 * form posts or background requests, nor anything the browser says is not
 * a page (`Sec-Fetch-Dest` other than document / iframe / frame). A frame
 * may be moved but never starts an SSO sign-in. Inertia visits get
 * Inertia's 409 full reload; wire:navigate gets a page that makes the
 * browser load the address itself. Staff admin pages and the dashboard
 * widget files are never moved (`sso.release.never_move` plus
 * `never_move_also`). Nothing moves on alpha, for guests, without a
 * selected agency, or toward a channel with no address. A browser bounced
 * three times in a minute stops being moved and the loop is logged;
 * prefetches and wire:navigate fetches never count toward that.
 */
class RouteToReleaseChannel
{
    public const LAUNCH_PARAMETER = 'sso_company';

    public const MOVES_KEY = 'sso_release.moves';

    public const HANDOFF_KEY = 'sso_release.handoff';

    /** Set on the page that makes the browser load another address itself (wire:navigate). */
    public const FULL_PAGE_LOAD_HEADER = 'X-Sso-Release-Location';

    /** How long that page waits before its no-script fallback loads the address. */
    public const REFRESH_FALLBACK_SECONDS = 3;

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

        // Livewire's wire:navigate fetches the next page in the background
        // and swaps it in. A redirect to another origin fails inside that
        // fetch, so the answer is a page that makes the browser load the
        // address itself. Hover prefetches look exactly like clicks here, so
        // nothing is recorded and nothing starts a sign-in: the full page
        // load that follows does both.
        if ($request->headers->has('X-Livewire-Navigate')) {
            return $this->navigateIfNeeded($request, $next);
        }

        // An Inertia visit is a background request, but it is how React apps
        // move between pages, so it is moved with Inertia's own full-reload
        // answer. It never carries the launch parameter.
        if ($request->hasHeader('X-Inertia')) {
            return $this->moveInertiaVisitIfNeeded($request, $next);
        }

        // Browsers name what a request is for. Only a page (or a frame) is
        // ever moved or handed across: scripts the SSO dashboard loads,
        // images, file previews and plain fetch() calls are served here.
        // Without the header (older clients, curl, tests) the rules below
        // decide, as before.
        $destination = $this->fetchDestination($request);

        if ($destination !== null && ! in_array($destination, ['document', 'iframe', 'frame'], true)) {
            return $next($request);
        }

        if ($request->ajax() || $request->expectsJson() || $request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        $prefetch = $this->isPrefetch($request);

        if ($request->query->has(self::LAUNCH_PARAMETER)) {
            // A frame (SSO's hidden warm-up frame) or a prefetch never starts
            // an SSO sign-in: that belongs to the page the person is looking at.
            $mayStartSignIn = ! $prefetch && $destination !== 'iframe' && $destination !== 'frame';

            return $this->handAcross($request, $next, $mayStartSignIn, ! $prefetch);
        }

        return $this->moveIfNeeded($request, $next, ! $prefetch);
    }

    protected function moveIfNeeded(Request $request, Closure $next, bool $record = true): Response
    {
        $target = $this->moveTarget($request, (string) $request->getRequestUri());

        if ($target === null || ($record && $this->isLooping($request))) {
            return $next($request);
        }

        return $this->moveTo($request, $target);
    }

    protected function moveInertiaVisitIfNeeded(Request $request, Closure $next): Response
    {
        $target = $this->moveTarget($request, (string) $request->getRequestUri());

        if ($target === null) {
            return $next($request);
        }

        // A prefetch answer is kept by Inertia and used only if the link is
        // clicked; Inertia's own version reload replaces this answer with a
        // full load of this same address, which is moved (and counted) then.
        $record = ! $this->isPrefetch($request) && ! $this->inertiaWillReloadForNewAssets($request);

        if ($record && $this->isLooping($request)) {
            return $next($request);
        }

        return $this->moveTo($request, $target);
    }

    protected function navigateIfNeeded(Request $request, Closure $next): Response
    {
        if ($request->query->has(self::LAUNCH_PARAMETER)) {
            return $this->fullPageLoad($request->fullUrl());
        }

        $target = $this->moveTarget($request, (string) $request->getRequestUri());

        return $target === null ? $next($request) : $this->fullPageLoad($target);
    }

    /**
     * A page whose only job is to make the browser load `$url` itself.
     * Livewire swaps it in like any page it fetched: the head script runs
     * (it re-runs scripts it has not seen), and the refresh tag is the
     * fallback for a content security policy that blocks inline scripts.
     * The refresh waits a few seconds: at 0 Livewire 3 inserts it after the
     * script has already started the load and the browser loads the page
     * twice; the wait is dropped with this page once the next one arrives.
     * `location.replace` keeps the address that was clicked out of history.
     */
    protected function fullPageLoad(string $url): Response
    {
        $attribute = e($url);
        $script = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $nonce = Vite::cspNonce();
        $nonceAttribute = $nonce !== null ? ' nonce="'.e($nonce).'"' : '';

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            .'<meta http-equiv="refresh" content="'.self::REFRESH_FALLBACK_SECONDS.';url='.$attribute.'">'
            .'<script'.$nonceAttribute.'>window.location.replace('.$script.');</script>'
            .'<title>Loading</title></head>'
            .'<body><p><a href="'.$attribute.'">Continue</a></p></body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            self::FULL_PAGE_LOAD_HEADER => $url,
        ]);
    }

    /**
     * Inertia's middleware answers a visit made with old assets with its own
     * 409 to this same address, whatever this middleware said. That is only
     * knowable once its middleware has set the version, i.e. when it runs
     * around this one; before that the version reads empty and this
     * middleware's answer stands.
     */
    protected function inertiaWillReloadForNewAssets(Request $request): bool
    {
        $version = $this->inertiaAssetVersion();

        return $version !== null && $version !== '' && (string) $request->header('X-Inertia-Version', '') !== $version;
    }

    /**
     * The asset version Inertia's middleware has set for this request, or
     * null when Inertia is not installed.
     */
    protected function inertiaAssetVersion(): ?string
    {
        if (! class_exists(Inertia::class)) {
            return null;
        }

        try {
            return (string) Inertia::getVersion();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The browser's `Sec-Fetch-Dest`, lowercased, or null when not sent.
     */
    protected function fetchDestination(Request $request): ?string
    {
        $destination = strtolower(trim((string) $request->headers->get('Sec-Fetch-Dest', '')));

        return $destination === '' ? null : $destination;
    }

    /**
     * Inertia's prefetch (`Purpose: prefetch`) and the browser's own
     * (`Sec-Purpose: prefetch`, also sent for prerendering).
     */
    protected function isPrefetch(Request $request): bool
    {
        foreach (['Purpose', 'Sec-Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if (str_contains(strtolower((string) $request->headers->get($header, '')), 'prefetch')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide what a launch link does: redirect to the clean URL, the other
     * deployment, or the SSO sign-in. A frame or prefetch whose agency does
     * not match is served as it is instead of starting a sign-in.
     */
    protected function handAcross(Request $request, Closure $next, bool $mayStartSignIn = true, bool $record = true): Response
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

            if ($target !== null && (! $record || ! $this->isLooping($request))) {
                return $this->moveTo($request, $target);
            }

            return redirect()->to($cleanUrl);
        }

        if (! $mayStartSignIn) {
            return $next($request);
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
            [
                ...array_values((array) config('sso.release.never_move', ['admin', 'admin/*', 'widget', 'widget/*'])),
                ...array_values((array) config('sso.release.never_move_also', [])),
            ],
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

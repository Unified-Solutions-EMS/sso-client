<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Unified\SsoClient\Middleware\PurgeLegacyApexCookies;
use Unified\SsoClient\Tests\TestCase;

/**
 * UNI-418: the legacy ASP.NET app set cookies on `.unified-apps.com`, so
 * browsers keep sending them to every Laravel app on the platform. Its own
 * logout only wrote host-only deletions, which never matched the
 * parent-domain copies.
 */
class PurgeLegacyApexCookiesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('session.cookie', 'cloudpcr_session');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/scrub', fn () => 'ok')->middleware(PurgeLegacyApexCookies::class);
        $router->get('/web-scrub', fn () => 'ok')->middleware('web');
    }

    /**
     * @return array<int, Cookie>
     */
    private function cookiesNamed(TestResponse $response, string $name): array
    {
        return array_values(array_filter(
            $response->baseResponse->headers->getCookies(),
            fn (Cookie $cookie) => $cookie->getName() === $name,
        ));
    }

    public function test_a_legacy_cookie_is_expired_host_only_and_on_the_apex_domain(): void
    {
        $response = $this->call('GET', '/scrub', cookies: ['.AspNetCore.Identity.Application' => 'CfDJ8Nz...']);

        $response->assertOk();

        $expired = $this->cookiesNamed($response, '.AspNetCore.Identity.Application');
        $this->assertCount(2, $expired, 'Both the host-only and parent-domain copies need deleting.');

        $domains = array_map(fn (Cookie $cookie) => $cookie->getDomain(), $expired);
        sort($domains);
        $this->assertSame([null, '.unified-apps.com'], $domains);

        foreach ($expired as $cookie) {
            $this->assertLessThan(time(), $cookie->getExpiresTime());
            $this->assertSame('/', $cookie->getPath());
        }
    }

    public function test_every_configured_legacy_name_present_on_the_request_is_scrubbed(): void
    {
        $response = $this->call('GET', '/scrub', cookies: [
            'Abp.TenantId' => '3875',
            'userInfo' => '{"userName":"medic"}',
            'v4-sso-attempts' => '2',
        ]);

        foreach (['Abp.TenantId', 'userInfo', 'v4-sso-attempts'] as $name) {
            $this->assertCount(2, $this->cookiesNamed($response, $name), "{$name} was not scrubbed.");
        }
    }

    public function test_a_clean_request_gets_no_set_cookie_headers(): void
    {
        $response = $this->call('GET', '/scrub');

        $response->assertOk();
        $this->assertSame([], $response->baseResponse->headers->getCookies());
    }

    public function test_a_configured_name_that_is_not_on_the_request_is_left_alone(): void
    {
        $response = $this->call('GET', '/scrub', cookies: ['userInfo' => '{}']);

        $this->assertCount(2, $this->cookiesNamed($response, 'userInfo'));
        $this->assertSame([], $this->cookiesNamed($response, 'Abp.TenantId'));
    }

    public function test_the_host_apps_own_session_and_csrf_cookies_are_never_scrubbed(): void
    {
        // Even if someone adds them to the list, the middleware refuses.
        config()->set('sso.legacy_cookies.names', array_merge(
            (array) config('sso.legacy_cookies.names'),
            ['cloudpcr_session', 'XSRF-TOKEN', 'laravel_session', 'remember_web_59ba36ad'],
        ));

        $response = $this->call('GET', '/scrub', cookies: [
            'cloudpcr_session' => 'abc',
            'XSRF-TOKEN' => 'def',
            'laravel_session' => 'ghi',
            'remember_web_59ba36ad' => 'jkl',
            'userInfo' => '{}',
        ]);

        foreach (['cloudpcr_session', 'XSRF-TOKEN', 'laravel_session', 'remember_web_59ba36ad'] as $name) {
            $this->assertSame([], $this->cookiesNamed($response, $name), "{$name} must never be expired.");
        }

        $this->assertCount(2, $this->cookiesNamed($response, 'userInfo'));
    }

    public function test_an_empty_name_list_disables_the_scrub(): void
    {
        config()->set('sso.legacy_cookies.names', []);

        $response = $this->call('GET', '/scrub', cookies: ['userInfo' => '{}']);

        $this->assertSame([], $response->baseResponse->headers->getCookies());
    }

    public function test_the_apex_domain_is_configurable(): void
    {
        config()->set('sso.legacy_cookies.apex_domain', '.example.test');

        $response = $this->call('GET', '/scrub', cookies: ['userInfo' => '{}']);

        $domains = array_map(
            fn (Cookie $cookie) => $cookie->getDomain(),
            $this->cookiesNamed($response, 'userInfo'),
        );
        sort($domains);
        $this->assertSame([null, '.example.test'], $domains);
    }

    public function test_the_middleware_is_appended_to_the_web_group_in_every_app(): void
    {
        $middleware = $this->app->make(Kernel::class)
            ->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(PurgeLegacyApexCookies::class, $middleware);
    }

    /**
     * The scrub runs inside EncryptCookies, which re-issues every outgoing
     * cookie with an encrypted value. Deletion has to survive that, and the
     * app's own session cookie has to come through untouched.
     */
    public function test_the_deletion_survives_the_full_web_middleware_stack(): void
    {
        $response = $this->call('GET', '/web-scrub', cookies: ['userInfo' => '{}']);

        $response->assertOk();

        $expired = $this->cookiesNamed($response, 'userInfo');
        $this->assertCount(2, $expired);
        foreach ($expired as $cookie) {
            $this->assertLessThan(time(), $cookie->getExpiresTime());
        }

        $session = $this->cookiesNamed($response, 'cloudpcr_session');
        $this->assertCount(1, $session);
        $this->assertGreaterThan(time(), $session[0]->getExpiresTime());
    }
}

<?php

namespace Unified\SsoClient\Tests\Feature\Security;

use Unified\SsoClient\Middleware\SecurityHeaders;
use Unified\SsoClient\Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.example.test/');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(SecurityHeaders::class)->group(function ($router): void {
            $router->get('/page', fn () => response('ok'));
            $router->get('/framed', fn () => response('ok')
                ->header('Content-Security-Policy', "frame-ancestors 'self' https://parent.example.test")
                ->header('X-Frame-Options', 'DENY')
                ->header('Referrer-Policy', 'no-referrer'));
        });
    }

    public function test_alias_is_registered(): void
    {
        $this->assertSame(SecurityHeaders::class, $this->app['router']->getMiddleware()['sso.security-headers']);
    }

    public function test_baseline_headers_are_set(): void
    {
        $response = $this->get('/page')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(self), usb=(), serial=(), hid=(), midi=(), browsing-topics=()');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_extra_frame_ancestors_are_normalized_and_star_is_dropped(): void
    {
        config(['security-headers.frame_ancestors' => 'https://www.example.test/university, *, https://example.test']);

        $this->get('/page')->assertHeader(
            'Content-Security-Policy',
            "frame-ancestors 'self' https://www.example.test https://example.test",
        );
    }

    public function test_headers_the_response_already_set_are_not_overwritten(): void
    {
        $response = $this->get('/framed')->assertOk();

        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://parent.example.test");
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_is_absent_outside_production_by_default(): void
    {
        $this->get('/page')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_in_production_by_default(): void
    {
        $this->app['env'] = 'production';

        $this->get('/page')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_hsts_can_be_forced_on_for_a_staging_environment_that_is_live(): void
    {
        $this->app['env'] = 'staging';
        config(['security-headers.hsts.enabled' => 'true', 'security-headers.hsts.include_subdomains' => 'false']);

        $this->get('/page')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_hsts_can_be_turned_off_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['security-headers.hsts.enabled' => false]);

        $this->get('/page')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_never_carries_preload(): void
    {
        $this->app['env'] = 'production';

        $this->assertStringNotContainsString('preload', (string) $this->get('/page')->headers->get('Strict-Transport-Security'));
    }

    public function test_report_only_csp_is_sent_when_enabled(): void
    {
        config(['security-headers.csp_report_only.enabled' => true]);

        $response = $this->get('/page')->assertOk();

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $policy);
        $this->assertStringContainsString("form-action 'self' https://sso.example.test", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString('report-uri /api/security/csp-report', $policy);
        $this->assertStringNotContainsString('{sso}', $policy);
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function test_report_only_csp_omits_report_uri_when_reporting_is_off(): void
    {
        config(['security-headers.csp_report_only.enabled' => true, 'security-headers.csp_report_only.report' => false]);

        $this->assertStringNotContainsString('report-uri', (string) $this->get('/page')->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_individual_headers_can_be_turned_off(): void
    {
        config([
            'security-headers.frame_protection' => false,
            'security-headers.permissions_policy' => '',
            'security-headers.nosniff' => false,
        ]);

        $response = $this->get('/page');

        $response->assertHeaderMissing('X-Frame-Options');
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Permissions-Policy');
        $response->assertHeaderMissing('X-Content-Type-Options');
    }

    public function test_kill_switch_sends_nothing(): void
    {
        config(['security-headers.enabled' => false]);

        $response = $this->get('/page');

        $response->assertHeaderMissing('X-Content-Type-Options');
        $response->assertHeaderMissing('X-Frame-Options');
    }
}

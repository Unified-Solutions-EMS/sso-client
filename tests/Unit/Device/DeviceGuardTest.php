<?php

namespace Unified\SsoClient\Tests\Unit\Device;

use Illuminate\Support\Facades\Http;
use Unified\SsoClient\Device\DeviceGuard;
use Unified\SsoClient\Tests\TestCase;

class DeviceGuardTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.core_api_key', 'test-core-key');
        $app['config']->set('sso.device.policy_cache_ttl', 300);
    }

    public function test_it_fetches_and_caches_the_policy(): void
    {
        Http::fake([
            'https://sso.test/api/internal/companies/*/device-policy' => Http::response([
                'mode' => 'selected', 'locked_app_slugs' => ['cloudpcr'], 'grace_until' => null,
            ]),
        ]);

        $guard = app(DeviceGuard::class);

        $first = $guard->policyFor(70);
        $second = $guard->policyFor(70);

        $this->assertSame('selected', $first['mode']);
        $this->assertSame($first, $second);
        Http::assertSentCount(1); // second call served from cache
    }

    public function test_it_returns_null_and_does_not_cache_on_failure(): void
    {
        Http::fake(['https://sso.test/*' => Http::response([], 500)]);

        $guard = app(DeviceGuard::class);

        $this->assertNull($guard->policyFor(70));
        $this->assertNull($guard->policyFor(70));
        Http::assertSentCount(2); // not cached, so it retries
    }

    public function test_app_is_locked_respects_mode(): void
    {
        $guard = app(DeviceGuard::class);

        $this->assertTrue($guard->appIsLocked(['mode' => 'all', 'locked_app_slugs' => []], 'cloudpcr'));
        $this->assertTrue($guard->appIsLocked(['mode' => 'selected', 'locked_app_slugs' => ['cloudpcr']], 'cloudpcr'));
        $this->assertFalse($guard->appIsLocked(['mode' => 'selected', 'locked_app_slugs' => ['cad']], 'cloudpcr'));
        $this->assertFalse($guard->appIsLocked(['mode' => 'off', 'locked_app_slugs' => []], 'cloudpcr'));
    }

    public function test_grace_period_detection(): void
    {
        $guard = app(DeviceGuard::class);

        $this->assertTrue($guard->inGracePeriod(['mode' => 'all', 'locked_app_slugs' => [], 'grace_until' => now()->addDay()->toIso8601String()]));
        $this->assertFalse($guard->inGracePeriod(['mode' => 'all', 'locked_app_slugs' => [], 'grace_until' => now()->subDay()->toIso8601String()]));
        $this->assertFalse($guard->inGracePeriod(['mode' => 'all', 'locked_app_slugs' => [], 'grace_until' => null]));
    }
}

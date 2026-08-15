<?php

namespace Unified\SsoClient\Tests\Feature\Security;

use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\Tests\TestCase;

class DisabledInTestsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Deliberately NOT setting security.enabled: fully configured app,
        // but under a test runner recording must default to off so app
        // suites never fire real HTTP at SSO from the sync queue.
        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', 'testapp');
        $app['config']->set('security.token', 'core-api-key');
    }

    public function test_recording_defaults_to_off_under_unit_tests(): void
    {
        Queue::fake();

        event(new Failed('web', null, ['email' => 'attacker@example.com']));

        Queue::assertNothingPushed();
    }
}

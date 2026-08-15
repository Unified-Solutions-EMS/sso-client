<?php

namespace Unified\SsoClient\Tests\Feature\Security;

use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\Tests\TestCase;

class DisabledAuthEventsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', 'testapp');
        $app['config']->set('security.token', 'core-api-key');
        $app['config']->set('security.listen_auth_events', false);
    }

    public function test_auth_events_ignored_when_disabled(): void
    {
        Queue::fake();

        event(new Failed('web', null, ['email' => 'attacker@example.com']));

        Queue::assertNothingPushed();
    }
}

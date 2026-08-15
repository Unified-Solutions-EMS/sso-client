<?php

namespace Unified\SsoClient\Tests\Feature\Security;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\Security\Jobs\SendSecurityEventToUnified;
use Unified\SsoClient\Tests\TestCase;

class AuthEventSecurityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('security.enabled', true);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', 'testapp');
        $app['config']->set('security.token', 'core-api-key');
        $app['config']->set('security.canary_emails', 'canary@agency-demo.com');
    }

    public function test_failed_login_is_recorded(): void
    {
        Queue::fake();

        event(new Failed('web', null, ['email' => 'attacker@example.com', 'password' => 'guess']));

        Queue::assertPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'auth.failed_login'
                && $job->payload['severity'] === 'warning'
                && $job->payload['context']['email'] === 'attacker@example.com'
                && $job->payload['context']['user_known'] === false
                && ! isset($job->payload['context']['password']);
        });
    }

    public function test_canary_login_attempt_is_critical(): void
    {
        Queue::fake();

        event(new Failed('web', null, ['email' => 'canary@agency-demo.com', 'password' => 'guess']));

        Queue::assertPushed(SendSecurityEventToUnified::class, fn ($job) => $job->payload['event'] === 'auth.canary_login_attempt'
            && $job->payload['severity'] === 'critical');
        Queue::assertPushed(SendSecurityEventToUnified::class, fn ($job) => $job->payload['event'] === 'auth.failed_login');
    }

    public function test_lockout_is_recorded(): void
    {
        Queue::fake();

        event(new Lockout(Request::create('/login', 'POST', ['email' => 'attacker@example.com'])));

        Queue::assertPushed(SendSecurityEventToUnified::class, fn ($job) => $job->payload['event'] === 'auth.lockout'
            && $job->payload['context']['email'] === 'attacker@example.com');
    }
}

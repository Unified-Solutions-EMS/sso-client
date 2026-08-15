<?php

namespace Unified\SsoClient\Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\Security\Facades\SecurityEvents;
use Unified\SsoClient\Security\Jobs\SendSecurityEventToUnified;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\TestCase;

class SecurityEventsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('security.enabled', true);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', 'testapp');
        $app['config']->set('security.token', 'core-api-key');
        $app['config']->set('metrics.company_model', Company::class);
        $app['config']->set('metrics.user_model', User::class);
    }

    public function test_record_dispatches_job_with_payload(): void
    {
        Queue::fake();

        SecurityEvents::critical('honeytoken.api_key_used', ['note' => 'decoy']);

        Queue::assertPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['app_key'] === 'testapp'
                && $job->payload['event'] === 'honeytoken.api_key_used'
                && $job->payload['severity'] === 'critical'
                && $job->payload['context'] === ['note' => 'decoy']
                && $job->payload['occurred_at'] !== null;
        });
    }

    public function test_severity_helpers_set_severity(): void
    {
        Queue::fake();

        SecurityEvents::info('auth.password_reset');
        SecurityEvents::warning('auth.failed_login');

        Queue::assertPushed(SendSecurityEventToUnified::class, fn ($job) => $job->payload['severity'] === 'info');
        Queue::assertPushed(SendSecurityEventToUnified::class, fn ($job) => $job->payload['severity'] === 'warning');
    }

    public function test_local_ids_are_translated_to_sso_ids(): void
    {
        Queue::fake();

        DB::table('companies')->insert(['name' => 'A', 'sso_company_id' => '42']);
        DB::table('users')->insert(['name' => 'U', 'email' => 'u@test.com', 'sso_id' => '17']);

        $companyId = (int) DB::table('companies')->value('id');
        $userId = (int) DB::table('users')->value('id');

        SecurityEvents::warning('auth.failed_login', [
            'local_company_id' => $companyId,
            'local_user_id' => $userId,
        ]);

        Queue::assertPushed(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['sso_company_id'] === 42
                && $job->payload['sso_user_id'] === 17
                && ! isset($job->payload['context']['local_company_id']);
        });
    }

    public function test_noops_when_not_configured(): void
    {
        Queue::fake();

        config()->set('security.token', null);

        SecurityEvents::warning('auth.failed_login');

        Queue::assertNothingPushed();
    }

    public function test_honeytoken_and_canary_matching(): void
    {
        config()->set('security.honeytokens', 'decoy-key-1, decoy-key-2');
        config()->set('security.canary_emails', 'canary@agency-demo.com');

        $this->assertTrue(SecurityEvents::isHoneytoken('decoy-key-2'));
        $this->assertFalse(SecurityEvents::isHoneytoken('real-key'));
        $this->assertFalse(SecurityEvents::isHoneytoken(null));
        $this->assertTrue(SecurityEvents::isCanaryEmail('Canary@Agency-Demo.com'));
        $this->assertFalse(SecurityEvents::isCanaryEmail('user@agency-demo.com'));
    }
}

<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\Security\Jobs\SendSecurityEventToUnified;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\Stubs\StubRunTrialPurger;
use Unified\SsoClient\Tests\Stubs\StubTrialDataPurger;
use Unified\SsoClient\Tests\TestCase;

class TrialPurgeVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! class_exists('App\\Models\\User')) {
            class_alias(User::class, 'App\\Models\\User');
            class_alias(Company::class, 'App\\Models\\Company');
            class_alias(Role::class, 'App\\Models\\Role');
        }

        if (! class_exists('App\\Services\\TrialDataPurger')) {
            class_alias(StubTrialDataPurger::class, 'App\\Services\\TrialDataPurger');
            class_alias(StubRunTrialPurger::class, 'App\\Jobs\\RunTrialPurger');
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', 'testapp');
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('security.enabled', true);
        $app['config']->set('security.token', 'core-key');
    }

    private function postPurgeWebhook(int|string $ssoCompanyId): TestResponse
    {
        $body = json_encode([
            'event' => 'trial.purge_data',
            'company' => ['id' => $ssoCompanyId, 'name' => 'Some Agency'],
            'admin_user_id' => 'admin-sso-uuid',
        ]);

        return $this->call('POST', '/api/sso/provision', [], [], [], [
            'HTTP_X-SSO-Signature' => hash_hmac('sha256', $body, 'whsec'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function fakeTrialsEndpoint(array $trialIds, ?array $purgePendingIds = null, int $status = 200): void
    {
        $payload = [
            'data' => array_map(fn (int $id): array => [
                'id' => $id,
                'name' => "Trial {$id}",
                'status' => 'trial',
                'trial_expires_at' => null,
                'trial_expired' => false,
            ], $trialIds),
        ];

        if ($purgePendingIds !== null) {
            $payload['purge_pending'] = $purgePendingIds;
        }

        Http::fake([
            'sso.test/api/internal/companies/trials' => Http::response($payload, $status),
        ]);
    }

    public function test_purge_runs_when_company_is_a_current_trial(): void
    {
        Bus::fake();
        $this->fakeTrialsEndpoint([42]);
        Company::create(['name' => 'Trial Co', 'sso_company_id' => '42']);

        $this->postPurgeWebhook(42)
            ->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'trial.purge_data'])
            ->assertJsonMissing(['skipped' => true]);

        Bus::assertDispatched(StubRunTrialPurger::class, function (StubRunTrialPurger $job): bool {
            return $job->company->sso_company_id === '42'
                && $job->adminSsoId === 'admin-sso-uuid';
        });
    }

    public function test_purge_runs_when_company_has_a_pending_purge_intent(): void
    {
        Bus::fake();
        $this->fakeTrialsEndpoint([7], purgePendingIds: [42]);
        Company::create(['name' => 'Just Converted Co', 'sso_company_id' => '42']);

        $this->postPurgeWebhook(42)->assertOk()->assertJsonMissing(['skipped' => true]);

        Bus::assertDispatched(StubRunTrialPurger::class);
    }

    public function test_purge_blocked_when_company_is_not_purgeable(): void
    {
        Bus::fake();
        $this->fakeTrialsEndpoint([7, 9], purgePendingIds: [11]);
        Company::create(['name' => 'Live Customer', 'sso_company_id' => '42']);

        $this->postPurgeWebhook(42)
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'action' => 'trial.purge_data',
                'skipped' => true,
                'reason' => 'not_purgeable',
            ]);

        Bus::assertNotDispatched(StubRunTrialPurger::class);
        Bus::assertDispatched(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'trial.purge_blocked'
                && $job->payload['severity'] === 'warning'
                && $job->payload['context']['reason'] === 'not_purgeable';
        });
    }

    public function test_purge_blocked_when_sso_is_unreachable(): void
    {
        Bus::fake();
        Http::fake(fn () => throw new ConnectionException('connection refused'));
        Company::create(['name' => 'Trial Co', 'sso_company_id' => '42']);

        $this->postPurgeWebhook(42)
            ->assertOk()
            ->assertJson(['skipped' => true, 'reason' => 'sso_unreachable']);

        Bus::assertNotDispatched(StubRunTrialPurger::class);
        Bus::assertDispatched(SendSecurityEventToUnified::class, function (SendSecurityEventToUnified $job): bool {
            return $job->payload['event'] === 'trial.purge_blocked';
        });
    }

    public function test_purge_blocked_when_sso_returns_an_error(): void
    {
        Bus::fake();
        $this->fakeTrialsEndpoint([42], status: 500);
        Company::create(['name' => 'Trial Co', 'sso_company_id' => '42']);

        $this->postPurgeWebhook(42)
            ->assertOk()
            ->assertJson(['skipped' => true, 'reason' => 'sso_unreachable']);

        Bus::assertNotDispatched(StubRunTrialPurger::class);
    }

    public function test_purge_blocked_when_core_api_key_is_missing(): void
    {
        Bus::fake();
        Http::fake();
        config(['app.core_api_key' => '']);
        Company::create(['name' => 'Trial Co', 'sso_company_id' => '42']);

        $this->postPurgeWebhook(42)
            ->assertOk()
            ->assertJson(['skipped' => true, 'reason' => 'sso_not_configured']);

        Bus::assertNotDispatched(StubRunTrialPurger::class);
        Http::assertNothingSent();
    }

    public function test_pre_upgrade_sso_response_without_purge_pending_still_allows_trial_purge(): void
    {
        Bus::fake();
        $this->fakeTrialsEndpoint([42], purgePendingIds: null);
        Company::create(['name' => 'Trial Co', 'sso_company_id' => '42']);

        $this->postPurgeWebhook(42)->assertOk()->assertJsonMissing(['skipped' => true]);

        Bus::assertDispatched(StubRunTrialPurger::class);
    }

    public function test_unknown_company_is_acked_without_calling_sso(): void
    {
        Bus::fake();
        Http::fake();

        $this->postPurgeWebhook(999)
            ->assertOk()
            ->assertJson(['status' => 'ok', 'reason' => 'company_not_found']);

        Bus::assertNotDispatched(StubRunTrialPurger::class);
        Http::assertNothingSent();
    }
}

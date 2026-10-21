<?php

namespace Unified\SsoClient\Tests\Feature\Trial;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\Tests\Stubs\StubPlainTrialDataSeeder;
use Unified\SsoClient\Tests\Stubs\StubTrialDataSeeder;
use Unified\SsoClient\Trial\Jobs\SeedTrialAfterMasterDataSync;
use Unified\SsoClient\Trial\TrialContext;

/**
 * `master_data.context` against an app whose RunTrialSeeder still takes
 * three constructor arguments (every app today): the context is passed as a
 * fourth argument, PHP drops it, and seeding goes on exactly as before. The
 * four-argument job is TrialContextJobSeedTest, the no-job path
 * TrialContextDirectSeedTest (both need a process of their own).
 */
class TrialSeedContextTest extends TrialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        StubPlainTrialDataSeeder::$calls = [];

        foreach (['divisions', 'locations', 'vehicles'] as $entity) {
            config(["sso.master_data.{$entity}" => false]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return require __DIR__.'/../../Fixtures/trial_seed_context.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(bool $withContext = true): array
    {
        $masterData = ['ready' => true, 'counts' => ['qualifications' => 1, 'agency' => 1, 'contacts' => 2, 'devices' => 2, 'state_configurations' => 1]];

        if ($withContext) {
            $masterData['context'] = $this->context();
        }

        return ['master_data' => $masterData];
    }

    private function fakeSso(int $ssoCompanyId): void
    {
        Http::fake(["sso.test/api/internal/companies/{$ssoCompanyId}/qualifications" => Http::response([
            'company' => ['id' => $ssoCompanyId],
            'assignments' => [],
            'qualifications' => [['id' => 41, 'name' => 'Narcotics Custodian', 'description' => null, 'applies_to' => [], 'is_active' => true, 'updated_at' => '2026-10-21T12:00:00Z']],
        ])]);
    }

    private function assertSeededWithoutContext(): void
    {
        $this->assertCount(1, StubTrialDataSeeder::$calls, 'the seeder ran once');
        $this->assertSame('admin-uuid', StubTrialDataSeeder::$calls[0]['admin']);
        $this->assertNull(StubTrialDataSeeder::$calls[0]['context']);
        $this->assertSame([], StubTrialDataSeeder::$contexts, 'a three-argument job never hands a context over');
    }

    public function test_a_three_argument_job_still_seeds_when_the_context_is_passed(): void
    {
        config(['sso.master_data.qualifications' => false]);
        Http::fake();
        $this->company(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->payload())
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'action' => 'trial.seed_data']);

        $this->assertSeededWithoutContext();
        Http::assertNothingSent();
    }

    public function test_a_three_argument_job_still_seeds_after_the_mirror_sync(): void
    {
        $this->company(77);
        $this->fakeSso(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->payload())
            ->assertOk()
            ->assertJson(['master_data' => 'syncing']);

        $this->assertSeededWithoutContext();
    }

    public function test_the_queued_sync_job_carries_the_raw_master_data(): void
    {
        Queue::fake([SeedTrialAfterMasterDataSync::class]);
        $this->company(77);
        $this->fakeSso(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->payload())->assertOk();

        $pushed = Queue::pushed(SeedTrialAfterMasterDataSync::class)->first();
        $this->assertSame($this->context(), $pushed->masterData['context']);
        $this->assertEquals(TrialContext::fromArray($this->context()), TrialContext::fromPayload($pushed->masterData));
        $this->assertSame([], StubTrialDataSeeder::$calls);

        $this->app->call([unserialize(serialize($pushed)), 'handle']);

        $this->assertCount(1, StubTrialDataSeeder::$calls);
    }

    public function test_a_seeder_without_the_interface_still_seeds(): void
    {
        $this->app->bind('App\\Services\\TrialDataSeeder', StubPlainTrialDataSeeder::class);
        $this->company(77);
        $this->fakeSso(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->payload())->assertOk();

        $this->assertCount(1, StubPlainTrialDataSeeder::$calls);
    }

    public function test_payloads_without_a_context_seed_as_before(): void
    {
        $this->company(77);
        $this->fakeSso(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->payload(withContext: false))->assertOk();
        $this->postTrialWebhook('trial.seed_data', 77)->assertOk();

        $this->assertCount(2, StubTrialDataSeeder::$calls);
    }
}

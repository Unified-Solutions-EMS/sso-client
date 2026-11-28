<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\MasterData\Devices\Jobs\WriteThroughDevice;
use Unified\SsoClient\MasterData\Personnel\Jobs\PullPersonnelRecord;
use Unified\SsoClient\Release\ReleaseChannel;
use Unified\SsoClient\Tests\Feature\Trial\TrialTestCase;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\StubRunLegacyCadMigration;
use Unified\SsoClient\Tests\Stubs\StubRunTrialSeeder;
use Unified\SsoClient\Trial\AppTrialJobs;
use Unified\SsoClient\Trial\Jobs\PurgeTrialThenCleanMirrors;
use Unified\SsoClient\Trial\Jobs\ReportTrialSeedStatus;
use Unified\SsoClient\Trial\Jobs\SeedTrialAfterMasterDataSync;
use Unified\SsoClient\Trial\TrialSeedStatusReporter;

/**
 * A beta agency's work runs on beta's worker. Everything else, and every app
 * where release channels are not set up, keeps the queue it had before
 * (null: the job's own queue or the connection default).
 */
class ReleaseChannelQueueTest extends TrialTestCase
{
    private Company $stableAgency;

    private Company $betaAgency;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! class_exists('App\\Jobs\\RunLegacyCadMigration')) {
            class_alias(StubRunLegacyCadMigration::class, 'App\\Jobs\\RunLegacyCadMigration');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        ReleaseChannel::flushSchemaCache();

        $this->stableAgency = Company::query()->create(['name' => 'Stable', 'sso_company_id' => '501', 'core_tenant_id' => 'T501']);
        $this->betaAgency = Company::query()->create(['name' => 'Beta', 'sso_company_id' => '502', 'core_tenant_id' => 'T502', 'release_channel' => 'beta']);
    }

    protected function tearDown(): void
    {
        ReleaseChannel::flushSchemaCache();

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.release.channel', 'stable');
        $app['config']->set('sso.release.urls', ['stable' => 'https://stable.test', 'beta' => 'https://beta.test']);
        $app['config']->set('sso.release.company_model', Company::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        (require __DIR__.'/../../../database/migrations/2026_11_28_000000_add_release_channel_to_companies_table.php')->up();
    }

    private function unconfigure(): void
    {
        config(['sso.release.urls.beta' => null]);
    }

    public function test_the_queue_follows_the_agencys_channel(): void
    {
        $this->assertSame('beta', ReleaseChannel::queueForCompany($this->betaAgency->id));
        $this->assertSame('beta', ReleaseChannel::queueForCompany($this->betaAgency));
        $this->assertSame('beta', ReleaseChannel::queueForCompany(Company::query()->select(['id'])->find($this->betaAgency->id)));
        $this->assertSame('beta', ReleaseChannel::queueForSsoCompanyId(502));
        $this->assertSame('beta', ReleaseChannel::queueForSsoCompanyId('502'));

        $this->assertNull(ReleaseChannel::queueForCompany($this->stableAgency->id));
        $this->assertNull(ReleaseChannel::queueForCompany(null));
        $this->assertNull(ReleaseChannel::queueForSsoCompanyId(null));
        $this->assertNull(ReleaseChannel::queueForSsoCompanyId(999));
    }

    public function test_a_named_stable_queue_is_used_when_configured(): void
    {
        config(['sso.release.queues.stable' => 'stable-work']);

        $this->assertSame('stable-work', ReleaseChannel::queueForCompany($this->stableAgency->id));
        $this->assertSame('stable-work', ReleaseChannel::workerQueue('stable'));
    }

    public function test_the_stable_worker_queue_defaults_to_the_connections_own(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.queue' => 'truck-checks']);

        $this->assertSame('truck-checks', ReleaseChannel::workerQueue('stable'));
        $this->assertSame('beta', ReleaseChannel::workerQueue('beta'));
    }

    public function test_the_beta_deployment_sends_stable_agencies_back_to_the_default_queue(): void
    {
        config(['sso.release.channel' => 'beta', 'sso.release.urls.beta' => null]);

        $this->assertSame('beta', ReleaseChannel::queueForCompany($this->betaAgency->id));
        $this->assertNull(ReleaseChannel::queueForCompany($this->stableAgency->id));
    }

    public function test_alpha_never_routes(): void
    {
        config(['sso.release.channel' => 'alpha']);

        $this->assertNull(ReleaseChannel::queueForCompany($this->betaAgency->id));
    }

    public function test_unconfigured_apps_keep_todays_queues_without_reading_the_database(): void
    {
        $this->unconfigure();
        DB::enableQueryLog();

        $this->assertNull(ReleaseChannel::queueForCompany($this->betaAgency->id));
        $this->assertNull(ReleaseChannel::queueForSsoCompanyId(502));
        $this->assertNull((new PullPersonnelRecord($this->betaAgency->id, 502, 900))->queue);
        $this->assertNull((new WriteThroughDevice($this->betaAgency->id, ['local_id' => 1]))->queue);
        $this->assertNull((new ReportTrialSeedStatus(502, TrialSeedStatusReporter::SEEDED))->queue);
        $this->assertNull((new SeedTrialAfterMasterDataSync($this->betaAgency, [], 'admin', []))->queue);
        $this->assertNull((new PurgeTrialThenCleanMirrors($this->betaAgency, 'admin', []))->queue);

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_package_jobs_for_one_agency_go_on_its_queue(): void
    {
        $this->assertSame('beta', (new PullPersonnelRecord($this->betaAgency->id, 502, 900))->queue);
        $this->assertSame('beta', (new WriteThroughDevice($this->betaAgency->id, ['local_id' => 1]))->queue);
        $this->assertSame('beta', (new ReportTrialSeedStatus(502, TrialSeedStatusReporter::SEEDED))->queue);
        $this->assertSame('beta', (new SeedTrialAfterMasterDataSync($this->betaAgency, [], 'admin', []))->queue);
        $this->assertSame('beta', (new PurgeTrialThenCleanMirrors($this->betaAgency, 'admin', []))->queue);

        $this->assertNull((new PullPersonnelRecord($this->stableAgency->id, 501, 900))->queue);
        $this->assertNull((new WriteThroughDevice($this->stableAgency->id, ['local_id' => 1]))->queue);
        $this->assertNull((new ReportTrialSeedStatus(501, TrialSeedStatusReporter::SEEDED))->queue);
        $this->assertNull((new SeedTrialAfterMasterDataSync($this->stableAgency, [], 'admin', []))->queue);
        $this->assertNull((new PurgeTrialThenCleanMirrors($this->stableAgency, 'admin', []))->queue);
    }

    public function test_the_seed_webhook_with_shared_lists_queues_the_sync_on_the_agencys_queue(): void
    {
        Queue::fake();

        $this->postTrialWebhook('trial.seed_data', 502, ['master_data' => ['ready' => true, 'counts' => []]])
            ->assertOk()
            ->assertJsonPath('master_data', 'syncing');

        Queue::assertPushedOn('beta', SeedTrialAfterMasterDataSync::class);
    }

    public function test_the_app_seeder_chain_runs_on_the_agencys_queue(): void
    {
        Queue::fake();

        app(AppTrialJobs::class)->seed($this->betaAgency, [], 'admin');

        Queue::assertPushedOn('beta', StubRunTrialSeeder::class, fn (StubRunTrialSeeder $job): bool => $job->chainQueue === 'beta');
    }

    public function test_the_app_seeder_chain_is_untouched_for_a_stable_agency_and_when_unconfigured(): void
    {
        Queue::fake();

        app(AppTrialJobs::class)->seed($this->stableAgency, [], 'admin');

        $this->unconfigure();
        app(AppTrialJobs::class)->seed($this->betaAgency, [], 'admin');

        Queue::assertPushed(StubRunTrialSeeder::class, 2);
        Queue::assertPushed(StubRunTrialSeeder::class, fn (StubRunTrialSeeder $job): bool => $job->queue === null && $job->chainQueue === null);
        Queue::assertNotPushed(StubRunTrialSeeder::class, fn (StubRunTrialSeeder $job): bool => $job->queue !== null);
    }

    public function test_the_legacy_import_from_the_webhook_goes_on_the_agencys_queue(): void
    {
        Queue::fake();

        $this->postWebhook('cad.migrate_data', ['company' => ['id' => 502, 'legacy_tenant_id' => 'T502']])->assertOk();
        $this->postWebhook('cad.migrate_data', ['company' => ['id' => 501, 'legacy_tenant_id' => 'T501']])->assertOk();

        Queue::assertPushedOn('beta', StubRunLegacyCadMigration::class, fn (StubRunLegacyCadMigration $job): bool => $job->legacyTenantId === 'T502');
        Queue::assertPushed(StubRunLegacyCadMigration::class, fn (StubRunLegacyCadMigration $job): bool => $job->legacyTenantId === 'T501' && $job->queue === null);
    }
}

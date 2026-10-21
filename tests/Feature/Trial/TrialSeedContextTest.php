<?php

namespace Unified\SsoClient\Tests\Feature\Trial;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\StubPlainTrialDataSeeder;
use Unified\SsoClient\Tests\Stubs\StubTrialDataSeeder;
use Unified\SsoClient\Trial\AppTrialJobs;
use Unified\SsoClient\Trial\Jobs\SeedTrialAfterMasterDataSync;
use Unified\SsoClient\Trial\TrialContext;
use Unified\SsoClient\Trial\TrialContextRelay;

/**
 * `master_data.context` reaches a seeder implementing ReceivesTrialContext
 * through every path the webhook takes: the mirror sync job, straight to the
 * app's RunTrialSeeder, and through a real (database) queue where the job
 * runs later. The direct call without a RunTrialSeeder job is in
 * TrialContextDirectSeedTest (it needs a process without that class).
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

    private function seededContext(): ?TrialContext
    {
        $this->assertCount(1, StubTrialDataSeeder::$calls, 'the seeder ran once');

        return StubTrialDataSeeder::$calls[0]['context'];
    }

    public function test_context_reaches_the_seeder_after_the_mirror_sync(): void
    {
        $this->company(77);
        $this->fakeSso(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->payload())
            ->assertOk()
            ->assertJson(['master_data' => 'syncing']);

        $context = $this->seededContext();
        $this->assertInstanceOf(TrialContext::class, $context);
        $this->assertSame('Albany', $context->city());
        $this->assertSame(['Medic 1', 'Medic 2', 'Medic 3', 'Engine 1', 'Engine 2', 'Command 1', 'Rescue 1'], $context->vehicleNames());
        $this->assertSame([13, 14], $context->fakeUserIds());
    }

    public function test_the_queued_sync_job_carries_the_raw_master_data_and_builds_the_context_when_it_runs(): void
    {
        Queue::fake([SeedTrialAfterMasterDataSync::class]);
        $this->company(77);
        $this->fakeSso(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->payload())->assertOk();

        $pushed = Queue::pushed(SeedTrialAfterMasterDataSync::class)->first();
        $this->assertSame($this->context(), $pushed->masterData['context']);
        $this->assertSame([], StubTrialDataSeeder::$calls);

        $job = unserialize(serialize($pushed));
        $this->app->call([$job, 'handle']);

        $this->assertSame('36001', $this->seededContext()?->countyFips());
    }

    public function test_app_without_mirrors_hands_the_context_to_its_seeder_job(): void
    {
        config(['sso.master_data.qualifications' => false]);
        Http::fake();
        $this->company(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->payload())
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'action' => 'trial.seed_data']);

        $this->assertSame('Example EMS', $this->seededContext()?->agencyName());
        Http::assertNothingSent();
    }

    public function test_context_survives_a_real_queue_and_is_read_from_the_job_payload(): void
    {
        $this->useDatabaseQueue();
        config(['sso.master_data.qualifications' => false]);
        $this->company(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->payload())->assertOk();

        $this->assertSame([], StubTrialDataSeeder::$calls, 'queued, not run yet');
        $row = DB::table('jobs')->sole();
        $payload = json_decode($row->payload, true);
        $this->assertSame('NY', $payload[TrialContextRelay::PAYLOAD_KEY]['state']);

        $payload[TrialContextRelay::PAYLOAD_KEY]['city'] = 'Troy';
        DB::table('jobs')->where('id', $row->id)->update(['payload' => json_encode($payload)]);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        $this->assertSame('Troy', $this->seededContext()?->city(), 'the worker builds the context from the stored payload');
        $this->assertSame(0, DB::table('jobs')->count());

        $this->app->make('App\\Services\\TrialDataSeeder');
        $this->assertCount(1, StubTrialDataSeeder::$contexts, 'a seeder resolved after the job is not handed the context');
    }

    public function test_only_the_seeder_job_carries_the_context(): void
    {
        $this->useDatabaseQueue();

        app(TrialContextRelay::class)->send(TrialContext::fromArray($this->context()), function (): void {
            dispatch(new SeedTrialAfterMasterDataSync(Company::query()->create(['name' => 'x']), [], 'admin-uuid', []));
        });
        app(AppTrialJobs::class)->seed(Company::query()->first(), [], 'admin-uuid');

        $payloads = DB::table('jobs')->orderBy('id')->pluck('payload')->map(fn (string $payload): array => json_decode($payload, true));
        $this->assertCount(2, $payloads);
        $this->assertArrayNotHasKey(TrialContextRelay::PAYLOAD_KEY, $payloads[0], 'another job dispatched while sending');
        $this->assertArrayNotHasKey(TrialContextRelay::PAYLOAD_KEY, $payloads[1], 'no context, no key');
    }

    public function test_a_seeder_without_the_interface_still_seeds(): void
    {
        $this->app->bind('App\\Services\\TrialDataSeeder', StubPlainTrialDataSeeder::class);
        $this->company(77);
        $this->fakeSso(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->payload())->assertOk();

        $this->assertCount(1, StubPlainTrialDataSeeder::$calls);
        $this->assertSame('admin-uuid', StubPlainTrialDataSeeder::$calls[0]['admin']);
    }

    public function test_master_data_without_a_context_hands_the_seeder_null(): void
    {
        $this->company(77);
        $this->fakeSso(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->payload(withContext: false))->assertOk();

        $this->assertNull($this->seededContext());
        $this->assertSame([null], StubTrialDataSeeder::$contexts);
    }

    public function test_legacy_payload_without_master_data_hands_the_seeder_null(): void
    {
        Http::fake();
        $this->company(77);

        $this->postTrialWebhook('trial.seed_data', 77)->assertOk();

        $this->assertNull($this->seededContext());
        Http::assertNothingSent();
    }

    private function useDatabaseQueue(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        config([
            'queue.default' => 'database',
            'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
            'queue.failed.driver' => 'null',
        ]);
    }
}

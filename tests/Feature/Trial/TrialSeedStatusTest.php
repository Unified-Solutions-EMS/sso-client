<?php

namespace Unified\SsoClient\Tests\Feature\Trial;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\Tests\Stubs\StubRunTrialSeeder;
use Unified\SsoClient\Tests\Stubs\StubTrialDataSeeder;
use Unified\SsoClient\Trial\Jobs\ReportTrialSeedFailure;
use Unified\SsoClient\Trial\Jobs\ReportTrialSeedStatus;
use Unified\SsoClient\Trial\TrialSeedStatusReporter;

/**
 * SSO's trial welcome modal keeps a setup bar until every app has seeded.
 * The webhook answer says what will happen; the chained report says when
 * it did.
 */
class TrialSeedStatusTest extends TrialTestCase
{
    private function noMirrors(): void
    {
        foreach (['divisions', 'locations', 'vehicles', 'qualifications'] as $entity) {
            config(["sso.master_data.{$entity}" => false]);
        }
    }

    private function statusRequest(Request $request): bool
    {
        return str_ends_with($request->url(), '/api/internal/companies/77/trial-seed-status');
    }

    public function test_a_queued_seeder_is_chained_with_the_seeded_report(): void
    {
        $this->noMirrors();
        Bus::fake();
        Http::fake();
        $this->company(77);

        $this->postTrialWebhook('trial.seed_data', 77)
            ->assertOk()
            ->assertJsonPath('seed_status', 'pending');

        Bus::assertChained([
            StubRunTrialSeeder::class,
            new ReportTrialSeedStatus('77', TrialSeedStatusReporter::SEEDED),
        ]);
        Http::assertNothingSent();
    }

    public function test_the_chain_reports_a_failure_through_a_plain_object_not_a_closure(): void
    {
        $this->noMirrors();
        Queue::fake();
        Http::fake();
        $this->company(77);

        $this->postTrialWebhook('trial.seed_data', 77)->assertOk();

        $pushed = Queue::pushed(StubRunTrialSeeder::class)->first();

        $this->assertNotNull($pushed);
        $this->assertCount(1, $pushed->chainCatchCallbacks);
        $this->assertInstanceOf(ReportTrialSeedFailure::class, $pushed->chainCatchCallbacks[0]);
        $this->assertSame('77', (string) $pushed->chainCatchCallbacks[0]->ssoCompanyId);
    }

    public function test_on_a_sync_queue_sso_hears_seeded_once_the_seeder_has_run(): void
    {
        $this->noMirrors();
        Http::fake();
        $this->company(77);

        $this->postTrialWebhook('trial.seed_data', 77)->assertOk();

        $this->assertCount(1, StubTrialDataSeeder::$calls);
        Http::assertSent(fn (Request $request): bool => $this->statusRequest($request)
            && $request->hasHeader('Authorization', 'Bearer core-key')
            && $request['app_slug'] === 'crew-scheduling'
            && $request['status'] === 'seeded');
    }

    public function test_a_seeder_that_throws_reports_failed(): void
    {
        $this->noMirrors();
        Http::fake();
        $this->company(77);
        StubTrialDataSeeder::$onSeed = function (): void {
            throw new \RuntimeException('no sample data today');
        };

        try {
            $this->postTrialWebhook('trial.seed_data', 77);
        } catch (\Throwable) {
            // The sync queue rethrows after running the chain's catch callback.
        }

        Http::assertSent(fn (Request $request): bool => $this->statusRequest($request) && $request['status'] === 'failed');
        Http::assertNotSent(fn (Request $request): bool => $this->statusRequest($request) && $request['status'] === 'seeded');
    }

    public function test_the_reporter_is_silent_when_sso_refuses_or_is_down(): void
    {
        Http::fake(['sso.test/*' => Http::response(['message' => 'nope'], 422)]);

        app(TrialSeedStatusReporter::class)->report(77, TrialSeedStatusReporter::SEEDED);

        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        app(TrialSeedStatusReporter::class)->report(77, TrialSeedStatusReporter::SEEDED);

        $this->assertTrue(true, 'neither answer reached the caller');
    }

    public function test_an_unconfigured_app_sends_nothing(): void
    {
        config(['sso.app_slug' => '']);
        Http::fake();

        app(TrialSeedStatusReporter::class)->report(77, TrialSeedStatusReporter::SEEDED);

        Http::assertNothingSent();
    }
}

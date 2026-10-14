<?php

namespace Unified\SsoClient\Tests\Feature\Trial;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\StubRunTrialSeeder;
use Unified\SsoClient\Tests\Stubs\StubTrialDataSeeder;
use Unified\SsoClient\Trial\Exceptions\TrialMasterDataNotReadyException;
use Unified\SsoClient\Trial\Jobs\SeedTrialAfterMasterDataSync;

class TrialSeedResyncTest extends TrialTestCase
{
    private const COUNTS = ['divisions' => 2, 'locations' => 4, 'vehicles' => 6, 'qualifications' => 4, 'facilities' => 9, 'medications' => 14, 'procedures' => 20];

    private function fakeSso(int $ssoCompanyId, int $vehicles = 6, int $status = 200): void
    {
        $company = ['id' => $ssoCompanyId, 'division_label' => 'division'];

        Http::fake([
            "sso.test/api/internal/companies/{$ssoCompanyId}/divisions" => Http::response(['company' => $company, 'assignments' => [], 'divisions' => [
                ['id' => 11, 'name' => 'North District', 'code' => null, 'is_active' => true, 'sort_order' => 1, 'updated_at' => '2026-10-13T12:00:00Z'],
                ['id' => 12, 'name' => 'South District', 'code' => null, 'is_active' => true, 'sort_order' => 2, 'updated_at' => '2026-10-13T12:00:00Z'],
            ]], $status),
            "sso.test/api/internal/companies/{$ssoCompanyId}/locations" => Http::response(['company' => $company, 'locations' => array_map(
                fn (int $n): array => $this->location(20 + $n, "Station {$n}", $n),
                range(1, 4),
            )], $status),
            "sso.test/api/internal/companies/{$ssoCompanyId}/vehicles" => Http::response(['company' => $company, 'vehicles' => array_map(
                fn (int $n): array => $this->vehicleRecord(30 + $n, "Medic {$n}", ['division_id' => 11, 'home_location_id' => 21]),
                range(1, $vehicles),
            )], $status),
            "sso.test/api/internal/companies/{$ssoCompanyId}/qualifications" => Http::response(['company' => $company, 'assignments' => [], 'qualifications' => array_map(
                fn (int $n): array => ['id' => 40 + $n, 'name' => "Qualification {$n}", 'description' => null, 'applies_to' => [], 'is_active' => true, 'updated_at' => '2026-10-13T12:00:00Z'],
                range(1, 4),
            )], $status),
        ]);
    }

    private function location(int $id, string $name, int $number): array
    {
        return [
            'id' => $id, 'name' => $name, 'number' => (string) $number, 'location_type' => '1301005', 'location_type_name' => 'Station',
            'division_id' => 11, 'division_name' => 'North District', 'address' => null, 'latitude' => null, 'longitude' => null,
            'phones' => [], 'is_active' => true, 'sort_order' => $number, 'updated_at' => '2026-10-13T12:00:00Z',
        ];
    }

    private function masterData(bool $ready = true): array
    {
        return ['master_data' => ['ready' => $ready, 'counts' => self::COUNTS]];
    }

    public function test_mirrors_every_enabled_list_before_the_app_seeder_runs(): void
    {
        $companyId = $this->company(77);
        $this->fakeSso(77);

        $seen = [];
        StubTrialDataSeeder::$onSeed = function () use ($companyId, &$seen): void {
            foreach (['divisions' => 'sso_division_id', 'locations' => 'sso_location_id', 'vehicles' => 'sso_vehicle_id', 'qualifications' => 'sso_qualification_id'] as $table => $column) {
                $seen[$table] = DB::table($table)->where('company_id', $companyId)->whereNotNull($column)->count();
            }
        };

        $this->postTrialWebhook('trial.seed_data', 77, $this->masterData())
            ->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'trial.seed_data', 'master_data' => 'syncing']);

        $this->assertCount(1, StubTrialDataSeeder::$calls);
        $this->assertSame(['divisions' => 2, 'locations' => 4, 'vehicles' => 6, 'qualifications' => 4], $seen);
        $this->assertSame($companyId, DB::table('vehicles')->where('sso_vehicle_id', 31)->value('company_id'));
    }

    public function test_dispatches_the_sync_job_instead_of_the_app_seeder(): void
    {
        Queue::fake();
        $this->company(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->masterData())->assertOk();

        Queue::assertPushed(SeedTrialAfterMasterDataSync::class, fn (SeedTrialAfterMasterDataSync $job): bool => $job->masterData['ready'] === true
            && $job->company->sso_company_id === '77'
            && $job->adminSsoId === 'admin-uuid');
        Queue::assertNotPushed(StubRunTrialSeeder::class);

        $job = new SeedTrialAfterMasterDataSync(Company::first(), [], '', []);
        $this->assertSame(6, $job->tries);
        $this->assertSame([30, 60, 120, 240, 480], $job->backoff);
    }

    public function test_not_ready_throws_so_the_job_retries_and_never_seeds(): void
    {
        $this->company(77);
        $this->fakeSso(77);

        $this->expectRetry(['ready' => false, 'counts' => self::COUNTS], 'not finished seeding');
        Http::assertNothingSent();
    }

    public function test_sso_unreachable_throws_so_the_job_retries(): void
    {
        $this->company(77);
        $this->fakeSso(77, status: 503);

        $this->expectRetry(['ready' => true, 'counts' => self::COUNTS], 'Could not resync');
    }

    public function test_short_list_throws_so_the_job_retries(): void
    {
        $this->company(77);
        $this->fakeSso(77, vehicles: 4);

        $this->expectRetry(['ready' => true, 'counts' => self::COUNTS], 'vehicles 4/6');
    }

    public function test_sync_queue_answers_500_when_not_ready(): void
    {
        $this->company(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->masterData(ready: false))->assertStatus(500);

        $this->assertSame([], StubTrialDataSeeder::$calls);
    }

    public function test_giving_up_reports_once(): void
    {
        $this->company(77);
        $reported = [];
        $this->app->make(ExceptionHandler::class)
            ->reportable(function (\Throwable $e) use (&$reported): void {
                $reported[] = $e->getMessage();
            });

        (new SeedTrialAfterMasterDataSync(Company::first(), [], 'admin-uuid', []))
            ->failed(new TrialMasterDataNotReadyException('still short'));

        $this->assertCount(1, $reported);
        $this->assertStringContainsString('gave up: still short', $reported[0]);
    }

    public function test_legacy_payload_without_master_data_seeds_as_before(): void
    {
        Bus::fake();
        Http::fake();
        $this->company(77);

        $this->postTrialWebhook('trial.seed_data', 77)
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'action' => 'trial.seed_data']);

        Bus::assertDispatched(StubRunTrialSeeder::class, fn (StubRunTrialSeeder $job): bool => $job->adminSsoId === 'admin-uuid');
        Bus::assertNotDispatched(SeedTrialAfterMasterDataSync::class);
        Http::assertNothingSent();
    }

    public function test_app_without_mirrors_ignores_master_data(): void
    {
        foreach (['divisions', 'locations', 'vehicles', 'qualifications'] as $entity) {
            config(["sso.master_data.{$entity}" => false]);
        }
        Bus::fake();
        Http::fake();
        $this->company(77);

        $this->postTrialWebhook('trial.seed_data', 77, $this->masterData(ready: false))
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'action' => 'trial.seed_data']);

        Bus::assertDispatched(StubRunTrialSeeder::class);
        Http::assertNothingSent();
    }

    private function expectRetry(array $masterData, string $message): void
    {
        $job = new SeedTrialAfterMasterDataSync(Company::first(), ['admin-uuid'], 'admin-uuid', $masterData);

        try {
            $this->app->call([$job, 'handle']);
            $this->fail('The job should have thrown.');
        } catch (TrialMasterDataNotReadyException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame([], StubTrialDataSeeder::$calls);
    }
}

<?php

namespace Unified\SsoClient\Tests\Feature\Trial;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Tests\Stubs\StubRunTrialPurger;
use Unified\SsoClient\Tests\Stubs\StubTrialDataPurger;
use Unified\SsoClient\Trial\Contracts\TrialMirrorCleanupPolicy;
use Unified\SsoClient\Trial\DefaultTrialMirrorCleanupPolicy;
use Unified\SsoClient\Trial\Jobs\PurgeTrialThenCleanMirrors;
use Unified\SsoClient\Trial\TrialMirrorCleaner;
use Unified\SsoClient\Trial\TrialMirrorCleanupAction;

class TrialMirrorCleanerTest extends TrialTestCase
{
    private const SEEDED = [
        'divisions' => [11, 12],
        'locations' => [21, 22],
        'vehicles' => [31, 32],
        'qualifications' => [41],
        'facilities' => [51],
        'medications' => [61],
        'procedures' => [71],
    ];

    private int $trial;

    private int $other;

    protected function setUp(): void
    {
        parent::setUp();

        config(['security.token' => 'core-key']);

        $this->trial = $this->company(77);
        $this->other = $this->company(88);

        foreach ([$this->trial, $this->other] as $companyId) {
            $north = $this->linkedRow('divisions', 'sso_division_id', $companyId, 11, 'North District');
            $this->linkedRow('divisions', 'sso_division_id', $companyId, 12, 'South District');
            $station = $this->linkedRow('locations', 'sso_location_id', $companyId, 21, 'Station 1', ['division_id' => $north]);
            $this->linkedRow('locations', 'sso_location_id', $companyId, 22, 'Station 2', ['division_id' => $north]);
            $this->linkedRow('vehicles', 'sso_vehicle_id', $companyId, 31, 'Medic 1', ['home_location_id' => $station, 'division_id' => $north]);
            $this->linkedRow('vehicles', 'sso_vehicle_id', $companyId, 32, 'Medic 2', ['home_location_id' => $station]);
            $this->linkedRow('qualifications', 'sso_qualification_id', $companyId, 41, 'FTO');
        }

        // Rows SSO did not list: the prospect's own linked vehicle and an unlinked local one.
        $this->linkedRow('vehicles', 'sso_vehicle_id', $this->trial, 39, 'Engine 9');
        DB::table('vehicles')->insert(['company_id' => $this->trial, 'unit_number' => 'Local 1', 'created_at' => now(), 'updated_at' => now()]);
        $this->linkedRow('qualifications', 'sso_qualification_id', $this->trial, 49, 'Kept');
    }

    private function fakeTrials(array $trialIds): void
    {
        Http::fake(['sso.test/api/internal/companies/trials' => Http::response([
            'data' => array_map(fn (int $id): array => ['id' => $id, 'status' => 'trial'], $trialIds),
            'purge_pending' => [],
        ])]);
    }

    private function rows(string $table, int $companyId): int
    {
        return DB::table($table)->where('company_id', $companyId)->count();
    }

    public function test_purge_runs_the_app_purger_then_deletes_only_the_listed_rows_of_this_company(): void
    {
        $this->fakeTrials([77]);
        $atPurge = null;
        StubTrialDataPurger::$onPurge = function () use (&$atPurge): void {
            $atPurge = $this->rows('vehicles', $this->trial);
        };

        $this->postTrialWebhook('trial.purge_data', 77, ['master_data' => ['seeded' => self::SEEDED]])
            ->assertOk()
            ->assertJson(['action' => 'trial.purge_data', 'master_data' => 'cleaning']);

        $this->assertCount(1, StubTrialDataPurger::$calls);
        $this->assertSame(4, $atPurge, 'the mirror rows are still there while the app purger runs');

        $this->assertSame(['Engine 9', 'Local 1'], DB::table('vehicles')->where('company_id', $this->trial)->orderBy('unit_number')->pluck('unit_number')->all());
        $this->assertSame(0, $this->rows('locations', $this->trial));
        $this->assertSame(0, $this->rows('divisions', $this->trial));
        $this->assertSame(['Kept'], DB::table('qualifications')->where('company_id', $this->trial)->pluck('name')->all());

        foreach (['divisions' => 2, 'locations' => 2, 'vehicles' => 2, 'qualifications' => 1] as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->where('company_id', $this->other)->where('is_active', true)->count(), "other company's {$table}");
        }
    }

    public function test_reports_per_entity_and_skips_facilities_and_code_lists(): void
    {
        $report = app(TrialMirrorCleaner::class)->clean($this->trial, array_replace(self::SEEDED, ['vehicles' => [31, 32, 999]]));

        $this->assertSame([
            'vehicles' => ['deleted' => 2, 'deactivated' => 0, 'kept' => 0, 'missing' => 1],
            'locations' => ['deleted' => 2, 'deactivated' => 0, 'kept' => 0, 'missing' => 0],
            'divisions' => ['deleted' => 2, 'deactivated' => 0, 'kept' => 0, 'missing' => 0],
            'qualifications' => ['deleted' => 1, 'deactivated' => 0, 'kept' => 0, 'missing' => 0],
        ], $report);
    }

    public function test_entity_this_app_does_not_mirror_is_left_alone(): void
    {
        config(['sso.master_data.qualifications' => false]);

        $report = app(TrialMirrorCleaner::class)->clean($this->trial, self::SEEDED);

        $this->assertSame('not_mirrored', $report['qualifications']);
        $this->assertSame(2, $this->rows('qualifications', $this->trial));
    }

    public function test_division_a_prospect_location_still_uses_is_deactivated_not_deleted(): void
    {
        $north = DB::table('divisions')->where('company_id', $this->trial)->where('sso_division_id', 11)->value('id');
        DB::table('locations')->insert(['company_id' => $this->trial, 'name' => 'Prospect post', 'division_id' => $north, 'created_at' => now(), 'updated_at' => now()]);

        $report = app(TrialMirrorCleaner::class)->clean($this->trial, self::SEEDED);

        $this->assertSame(['deleted' => 1, 'deactivated' => 1, 'kept' => 0, 'missing' => 0], $report['divisions']);
        $this->assertFalse((bool) DB::table('divisions')->where('id', $north)->value('is_active'));
        $this->assertSame(1, $this->rows('locations', $this->trial));
    }

    public function test_foreign_key_failure_deactivates_instead(): void
    {
        Schema::create('charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
        });
        $medic1 = DB::table('vehicles')->where('company_id', $this->trial)->where('sso_vehicle_id', 31)->value('id');
        DB::table('charts')->insert(['vehicle_id' => $medic1]);

        $report = app(TrialMirrorCleaner::class)->clean($this->trial, ['vehicles' => [31, 32]]);

        $this->assertSame(['deleted' => 1, 'deactivated' => 1, 'kept' => 0, 'missing' => 0], $report['vehicles']);
        $this->assertFalse((bool) DB::table('vehicles')->where('id', $medic1)->value('is_active'));
    }

    public function test_app_policy_can_veto_a_delete(): void
    {
        $this->app->bind(TrialMirrorCleanupPolicy::class, fn ($app) => new class($app->make(DefaultTrialMirrorCleanupPolicy::class)) implements TrialMirrorCleanupPolicy
        {
            public array $before = [];

            public function __construct(private DefaultTrialMirrorCleanupPolicy $default) {}

            public function decide(string $entity, int $localCompanyId, object $row): TrialMirrorCleanupAction
            {
                return match ([$entity, (int) $row->sso_vehicle_id]) {
                    ['vehicles', 31] => TrialMirrorCleanupAction::Deactivate,
                    ['vehicles', 32] => TrialMirrorCleanupAction::Keep,
                    default => $this->default->decide($entity, $localCompanyId, $row),
                };
            }

            public function beforeDelete(string $entity, int $localCompanyId, object $row): void {}
        });

        $report = app(TrialMirrorCleaner::class)->clean($this->trial, ['vehicles' => [31, 32]]);

        $this->assertSame(['deleted' => 0, 'deactivated' => 1, 'kept' => 1, 'missing' => 0], $report['vehicles']);
        $this->assertSame([31 => 0, 32 => 1], DB::table('vehicles')->where('company_id', $this->trial)->whereIn('sso_vehicle_id', [31, 32])
            ->pluck('is_active', 'sso_vehicle_id')->map(fn ($active): int => (int) $active)->all());
    }

    public function test_app_purger_failure_leaves_the_mirror_rows(): void
    {
        $this->fakeTrials([77]);
        StubTrialDataPurger::$onPurge = fn () => throw new \RuntimeException('purge broke');

        $this->postTrialWebhook('trial.purge_data', 77, ['master_data' => ['seeded' => self::SEEDED]])->assertStatus(500);

        $this->assertSame(4, $this->rows('vehicles', $this->trial));
        $this->assertSame(2, $this->rows('divisions', $this->trial));
    }

    public function test_legacy_payload_purges_as_before_without_cleaning(): void
    {
        $this->fakeTrials([77]);
        Bus::fake();

        $this->postTrialWebhook('trial.purge_data', 77)->assertOk()->assertExactJson(['status' => 'ok', 'action' => 'trial.purge_data']);

        Bus::assertDispatched(StubRunTrialPurger::class);
        Bus::assertNotDispatched(PurgeTrialThenCleanMirrors::class);
        $this->assertSame(4, $this->rows('vehicles', $this->trial));
    }

    public function test_unverified_purge_cleans_nothing(): void
    {
        $this->fakeTrials([88]);

        $this->postTrialWebhook('trial.purge_data', 77, ['master_data' => ['seeded' => self::SEEDED]])
            ->assertOk()->assertJson(['skipped' => true]);

        $this->assertSame([], StubTrialDataPurger::$calls);
        $this->assertSame(2, $this->rows('divisions', $this->trial));
    }
}

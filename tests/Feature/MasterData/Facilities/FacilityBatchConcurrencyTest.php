<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Facilities;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDOException;
use Unified\SsoClient\MasterData\Facilities\FacilityProjection;
use Unified\SsoClient\MasterData\Support\MirrorApplyLock;
use Unified\SsoClient\Tests\Stubs\DemFacilityProjection;

/**
 * UNI-1668: a 618-facility import made SSO send eight facility.batch_updated
 * events to CloudPCR within seconds. Applied side by side, the phone writes
 * (a delete, then inserts, in one transaction) deadlocked and five deliveries
 * answered 500; CloudPCR held 440 of 618 facilities until a resync.
 *
 * SQLite cannot deadlock, so the projection here throws MySQL's deadlock
 * error from inside its own transaction, the way CloudPCR's phone write
 * failed in production.
 */
class FacilityBatchConcurrencyTest extends FacilitiesTestCase
{
    private int $companyId;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app->singleton(FacilityProjection::class, DeadlockingFacilityProjection::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    public function test_a_deadlock_on_the_phone_write_is_retried_and_the_batch_lands(): void
    {
        $this->projection()->deadlocksLeft = [602 => 1];

        $this->postBatch(70, [
            $this->facility(601, 'Adirondack Medical Center', '+15188914141'),
            $this->facility(602, 'Alice Hyde Medical Center', '+15184812000'),
            $this->facility(603, 'Champlain Valley Hospital', '+15185615000'),
        ])->assertOk()->assertJson(['status' => 'ok', 'counts' => ['created' => 3]]);

        $this->assertSame(0, $this->projection()->deadlocksLeft[602]);
        $this->assertSame(3, DB::table('dem_facility_entries')->count(), 'The rolled-back attempt left no second copy.');
        $this->assertSame(['+15184812000'], $this->phonesOf(602), 'The row that deadlocked has its phone.');
        $this->assertSame(['+15185615000'], $this->phonesOf(603));
    }

    public function test_a_row_that_keeps_deadlocking_is_rolled_back_whole_and_a_resend_finishes_the_batch(): void
    {
        $batch = [
            $this->facility(601, 'Adirondack Medical Center', '+15188914141'),
            $this->facility(602, 'Alice Hyde Medical Center', '+15184812000'),
        ];
        $this->projection()->deadlocksLeft = [602 => 99];

        $this->postBatch(70, $batch)->assertStatus(500)->assertJson(['error' => 'Handler failed']);

        $this->assertSame(['+15188914141'], $this->phonesOf(601), 'Rows before the failure are kept whole.');
        $this->assertNull($this->mirrored(602), 'The failing row is not left inserted without its phones.');

        $this->projection()->deadlocksLeft = [];

        $this->postBatch(70, $batch)->assertOk()->assertJson(['counts' => ['unchanged' => 1, 'created' => 1]]);
        $this->assertSame(['+15184812000'], $this->phonesOf(602));
    }

    public function test_overlapping_batches_and_the_state_codes_wave_give_the_same_rows_as_one_batch(): void
    {
        $first = [
            $this->facility(601, 'Adirondack Medical Center', '+15188914141'),
            $this->facility(602, 'Alice Hyde Medical Center', '+15184812000'),
        ];
        $second = [
            $this->facility(602, 'Alice Hyde Medical Center', '+15184812000'),
            $this->facility(603, 'Champlain Valley Hospital', '+15185615000'),
        ];
        $resolved = array_map(fn (array $facility): array => ['location_code' => 'NY-'.$facility['id'], 'updated_at' => '2026-11-20T22:11:30+00:00'] + $facility, [...$first, $this->facility(603, 'Champlain Valley Hospital', '+15185615000')]);

        $this->postBatch(70, $first)->assertOk();
        $this->postBatch(70, $second)->assertOk();
        $this->postBatch(70, $resolved)->assertOk()->assertJson(['counts' => ['updated' => 3]]);
        $this->postBatch(70, $resolved)->assertOk()->assertJson(['counts' => ['unchanged' => 3]]);

        $afterFourDeliveries = $this->snapshotOf($this->companyId);

        DB::table('dem_facility_entry_phones')->delete();
        DB::table('dem_facility_entries')->delete();
        DB::table('dem_facilities')->delete();
        $this->postBatch(70, $resolved)->assertOk()->assertJson(['counts' => ['created' => 3]]);

        $this->assertCount(3, $afterFourDeliveries);
        $this->assertSame($this->snapshotOf($this->companyId), $afterFourDeliveries);
    }

    public function test_deliveries_for_one_company_and_entity_hold_the_apply_lock_and_release_it(): void
    {
        $key = MirrorApplyLock::key('facilities', $this->companyId);
        $this->projection()->onWritten = fn (): bool => Cache::lock($key, 10)->get();

        $this->postBatch(70, [$this->facility(601, 'Adirondack Medical Center', '+15188914141')])->assertOk();

        $this->assertSame([false], $this->projection()->lockFreeWhileWriting, 'Another delivery could not take the lock while this one was writing.');
        $this->assertTrue(Cache::lock($key, 10)->get(), 'The lock is released after the delivery.');
    }

    public function test_a_lock_still_held_after_the_wait_lets_the_delivery_run(): void
    {
        config(['sso.master_data.apply_lock_wait_seconds' => 1]);
        Cache::lock(MirrorApplyLock::key('facilities', $this->companyId), 60)->get();

        $started = microtime(true);
        $this->postBatch(70, [$this->facility(601, 'Adirondack Medical Center', '+15188914141')])
            ->assertOk()->assertJson(['counts' => ['created' => 1]]);

        $this->assertGreaterThanOrEqual(0.25, microtime(true) - $started, 'It waited for the delivery ahead of it.');
    }

    public function test_an_unreachable_cache_never_blocks_a_delivery(): void
    {
        config(['sso.coordination_store' => 'no-such-store']);

        $this->postBatch(70, [$this->facility(601, 'Adirondack Medical Center', '+15188914141')])
            ->assertOk()->assertJson(['counts' => ['created' => 1]]);
    }

    public function test_another_companys_lock_does_not_hold_this_one_up(): void
    {
        config(['sso.master_data.apply_lock_wait_seconds' => 5]);
        Cache::lock(MirrorApplyLock::key('facilities', $this->company(80)), 60)->get();

        $started = microtime(true);
        $this->postBatch(70, [$this->facility(601, 'Adirondack Medical Center', '+15188914141')])->assertOk();

        $this->assertLessThan(2, microtime(true) - $started);
    }

    private function projection(): DeadlockingFacilityProjection
    {
        return $this->app->make(FacilityProjection::class);
    }

    /**
     * @param  list<array<string, mixed>>  $facilities
     */
    private function postBatch(int $ssoCompanyId, array $facilities): TestResponse
    {
        return $this->postWebhook('facility.batch_updated', [
            'company' => ['id' => $ssoCompanyId],
            'ids' => array_column($facilities, 'id'),
            'facilities' => $facilities,
            'updated_at' => '2026-11-20T22:11:00+00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function facility(int $id, string $name, string $phone): array
    {
        return $this->facilityRecord($id, $name, ['phones' => [['id' => $id, 'number' => $phone, 'type' => '9913009']]]);
    }

    /**
     * @return list<string>
     */
    private function phonesOf(int $ssoId): array
    {
        return DB::table('dem_facility_entry_phones')->where('dem_facility_entry_id', $this->mirrored($ssoId)->id)->orderBy('sort_order')->pluck('phone_number')->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshotOf(int $companyId): array
    {
        return DB::table('dem_facility_entries')
            ->join('dem_facilities', 'dem_facilities.id', '=', 'dem_facility_entries.dem_facility_id')
            ->where('dem_facilities.company_id', $companyId)
            ->orderBy('sso_facility_id')
            ->get(['dem_facility_entries.id as entry_id', 'sso_facility_id', 'dfacility_02', 'dfacility_03', 'dfacility_01', 'is_active', 'sso_updated_at'])
            ->map(fn (object $row): array => [
                'sso_id' => (int) $row->sso_facility_id,
                'name' => $row->dfacility_02,
                'code' => $row->dfacility_03,
                'type' => $row->dfacility_01,
                'active' => (bool) $row->is_active,
                'sso_updated_at' => $row->sso_updated_at,
                'phones' => DB::table('dem_facility_entry_phones')->where('dem_facility_entry_id', $row->entry_id)->orderBy('sort_order')->pluck('phone_number')->all(),
            ])
            ->all();
    }
}

class DeadlockingFacilityProjection extends DemFacilityProjection
{
    /** @var array<int, int> deadlocks still to throw, by SSO facility id */
    public array $deadlocksLeft = [];

    /** @var (\Closure(): bool)|null */
    public ?\Closure $onWritten = null;

    /** @var list<bool> */
    public array $lockFreeWhileWriting = [];

    public function written(int $localCompanyId, int $localId, array $facility): void
    {
        if ($this->onWritten !== null) {
            $this->lockFreeWhileWriting[] = ($this->onWritten)();
        }

        DB::transaction(function () use ($localCompanyId, $localId, $facility): void {
            parent::written($localCompanyId, $localId, $facility);

            $ssoId = (int) $facility['id'];
            if (($this->deadlocksLeft[$ssoId] ?? 0) > 0) {
                $this->deadlocksLeft[$ssoId]--;

                throw new QueryException('testing', 'insert into "dem_facility_entry_phones" ("dem_facility_entry_id", "phone_number") values (?, ?)', [$localId, 'redacted'], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });
    }
}

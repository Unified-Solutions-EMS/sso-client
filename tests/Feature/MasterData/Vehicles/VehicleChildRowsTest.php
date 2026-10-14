<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Vehicles;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * CloudPCR's crew levels (dem_vehicle_cert_levels) and yearly usage
 * (dem_vehicle_years) follow SSO's lists IN PLACE: CloudPCR derives NEMSIS
 * group UUIDs from the local child row id, so an edited child must keep
 * its row, and only a child id SSO dropped may lose it.
 */
class VehicleChildRowsTest extends VehiclesTestCase
{
    protected string $shape = 'cloudpcr';

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    /**
     * @return list<array{int, string, int|null}>
     */
    private function levels(int $vehicleId): array
    {
        return DB::table('dem_vehicle_cert_levels')->where('dem_vehicle_id', $vehicleId)->orderBy('id')->get()
            ->map(fn (object $row): array => [(int) $row->id, $row->dvehicle_05, $row->dvehicle_06 === null ? null : (int) $row->dvehicle_06])
            ->all();
    }

    public function test_children_are_created_then_edited_in_place_and_only_a_dropped_id_is_removed(): void
    {
        Http::fake();
        $this->deliver('vehicle.created', 70, $this->vehicleRecord(901, 'M-1', [
            'vin' => '1FDUF4HT5LDA12345', 'call_sign' => 'Medic 1', 'initial_cost' => 285000, 'export_unit_number' => 'MEDIC1',
            'crew_levels' => [$this->crewLevel(11, '9917005', 2), $this->crewLevel(12, '9917007', 1)],
            'yearly_usage' => [$this->yearlyUsage(21, 2025, 1200, 31000.5)],
        ]))->assertJson(['result' => 'created']);

        $vehicle = $this->mirrored($this->companyId, 901);
        $this->assertSame(['M-1', '1FDUF4HT5LDA12345', 'Medic 1', '1404001', '285000', '2021', 'MEDIC1'], [
            $vehicle->dvehicle_01, $vehicle->dvehicle_02, $vehicle->dvehicle_03, $vehicle->dvehicle_04, $vehicle->dvehicle_09, $vehicle->dvehicle_10, $vehicle->export_vehicle_number,
        ]);
        [[$alsId], [$cctId]] = $this->levels((int) $vehicle->id);
        $yearId = (int) DB::table('dem_vehicle_years')->where('dem_vehicle_id', $vehicle->id)->value('id');

        // SSO edits child 11's level and crew count, drops 12, adds 13 and a year.
        $this->deliver('vehicle.updated', 70, $this->vehicleRecord(901, 'M-1', [
            'vin' => '1FDUF4HT5LDA12345', 'call_sign' => 'Medic 1', 'initial_cost' => 285000, 'export_unit_number' => 'MEDIC1',
            'crew_levels' => [$this->crewLevel(11, '9917003', 3), $this->crewLevel(13, '9917001', 1)],
            'yearly_usage' => [$this->yearlyUsage(21, 2025, 1350, 31000.5), $this->yearlyUsage(22, 2026, 400)],
            'updated_at' => '2026-10-11T13:00:00+00:00',
        ]))->assertJson(['result' => 'updated']);

        $levels = $this->levels((int) $vehicle->id);
        $this->assertSame([$alsId, '9917003', 3], $levels[0], 'the edited child keeps its local row id');
        $this->assertSame(['9917001', 1], array_slice($levels[1], 1));
        $this->assertNotContains($cctId, array_column($levels, 0), 'the child SSO dropped is removed');
        $this->assertSame(2, count($levels));
        $years = DB::table('dem_vehicle_years')->where('dem_vehicle_id', $vehicle->id)->orderBy('id')->get();
        $this->assertSame([$yearId, 1350, 21], [(int) $years[0]->id, (int) $years[0]->dvehicle_12, (int) $years[0]->sso_yearly_usage_id]);
        $this->assertSame(2026, (int) $years[1]->dvehicle_11);
    }

    public function test_a_child_only_change_reports_updated_and_a_repeat_is_unchanged(): void
    {
        Http::fake();
        $record = $this->vehicleRecord(901, 'M-1', ['crew_levels' => [$this->crewLevel(11, '9917005', 2)]]);
        $this->deliver('vehicle.created', 70, $record);
        $childId = $this->levels((int) $this->mirrored($this->companyId, 901)->id)[0][0];

        $record['crew_levels'] = [$this->crewLevel(11, '9917005', 4)];
        $this->deliver('vehicle.updated', 70, $record)->assertJson(['result' => 'updated']);
        $this->assertSame([[$childId, '9917005', 4]], $this->levels((int) $this->mirrored($this->companyId, 901)->id));

        $this->deliver('vehicle.updated', 70, $record)->assertJson(['result' => 'unchanged']);
    }

    public function test_resync_adopts_an_unlinked_local_child_by_level_and_keeps_one_sso_never_knew(): void
    {
        $vehicle = $this->localVehicle($this->companyId, 'M-1', ['sso_vehicle_id' => 901]);
        $als = (int) DB::table('dem_vehicle_cert_levels')->insertGetId(['dem_vehicle_id' => $vehicle, 'dvehicle_05' => '9917005', 'dvehicle_06' => 2]);
        $bls = (int) DB::table('dem_vehicle_cert_levels')->insertGetId(['dem_vehicle_id' => $vehicle, 'dvehicle_05' => '9917001', 'dvehicle_06' => 2]);

        Http::fake(['sso.test/api/internal/companies/70/vehicles' => Http::response($this->snapshot(70, [
            $this->vehicleRecord(901, 'M-1', ['crew_levels' => [$this->crewLevel(11, '9917005', 3)]]),
        ]))]);

        $this->artisan('sso:resync-master-data', ['entity' => 'vehicles'])
            ->expectsOutputToContain('created=0 updated=1 linked=0 unchanged=0 deactivated=0 unlinked_children=1')
            ->assertSuccessful();

        $this->assertSame([[$als, '9917005', 3], [$bls, '9917001', 2]], $this->levels($vehicle));
        $this->assertSame(11, (int) DB::table('dem_vehicle_cert_levels')->find($als)->sso_crew_level_id);
        $this->assertNull(DB::table('dem_vehicle_cert_levels')->find($bls)->sso_crew_level_id, 'a child SSO never knew is kept, not deleted');
    }

    public function test_push_sends_children_with_local_ids_and_links_the_child_mapping(): void
    {
        config(['sso.master_data.vehicles' => false]);
        $vehicle = $this->localVehicle($this->companyId, 'M-1', ['uuid' => '0b9f2b8e-4b7e-4c39-9d43-5d0b8f7f0a11', 'dvehicle_02' => '1FDUF4HT5LDA12345', 'dvehicle_10' => '2021']);
        $als = (int) DB::table('dem_vehicle_cert_levels')->insertGetId(['dem_vehicle_id' => $vehicle, 'dvehicle_05' => '9917005', 'dvehicle_06' => 2]);
        $year = (int) DB::table('dem_vehicle_years')->insertGetId(['dem_vehicle_id' => $vehicle, 'dvehicle_11' => 2025, 'dvehicle_12' => 1200, 'dvehicle_13' => 31000.5]);
        $foreignVehicle = $this->localVehicle($this->company(80), 'M-9');
        $foreignLevel = (int) DB::table('dem_vehicle_cert_levels')->insertGetId(['dem_vehicle_id' => $foreignVehicle, 'dvehicle_05' => '9917005']);

        Http::fake(['sso.test/api/internal/companies/70/vehicles/import' => Http::response([
            'created' => 1, 'matched' => 0, 'conflicts' => [], 'invalid' => [],
            'mapping' => [(string) $vehicle => [
                'sso_id' => 901, 'updated_at' => '2026-10-11T12:00:00+00:00',
                'crew_levels' => [(string) $als => 11, (string) $foreignLevel => 12],
                'yearly_usage' => [(string) $year => 21],
            ]],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'vehicles', '--company' => '70'])
            ->expectsOutputToContain('linked=1 link_collisions=1')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['app_slug'] === 'cloudpcr' && $request['vehicles'] === [[
            'local_id' => $vehicle,
            'unit_number' => 'M-1',
            'is_active' => true,
            'uuid' => '0b9f2b8e-4b7e-4c39-9d43-5d0b8f7f0a11',
            'vin' => '1FDUF4HT5LDA12345',
            'model_year' => 2021,
            'crew_levels' => [['local_id' => $als, 'level' => '9917005', 'on_911_ambulance' => 2]],
            'yearly_usage' => [['local_id' => $year, 'year' => 2025, 'hours' => 1200, 'distance' => 31000.5]],
        ]]);

        $this->assertSame(11, (int) DB::table('dem_vehicle_cert_levels')->find($als)->sso_crew_level_id);
        $this->assertSame(21, (int) DB::table('dem_vehicle_years')->find($year)->sso_yearly_usage_id);
        $this->assertNull(DB::table('dem_vehicle_cert_levels')->find($foreignLevel)->sso_crew_level_id, 'a child of another vehicle is never linked');
    }

    /**
     * An app's push of its vehicles reaches the other apps as one
     * vehicle.batch_updated per 200 rows; each row and its crew levels and
     * yearly usage are applied exactly like a vehicle.created delivery.
     */
    public function test_the_import_batch_writes_each_vehicle_with_its_child_rows(): void
    {
        Http::fake();

        $this->postWebhook('vehicle.batch_updated', [
            'company' => ['id' => 70, 'division_label' => 'division'],
            'ids' => [901, 902],
            'vehicles' => [
                $this->vehicleRecord(901, 'M-1', ['crew_levels' => [$this->crewLevel(11, '9917005', 2)], 'yearly_usage' => [$this->yearlyUsage(21, 2025, 1200, 31000.5)]]),
                $this->vehicleRecord(902, 'M-2', ['crew_levels' => [$this->crewLevel(12, '9917007', 1), $this->crewLevel(13, '9917001', 1)]]),
                ['id' => 903, 'unit_number' => ''],
            ],
            'updated_at' => '2026-10-14T12:00:00+00:00',
        ])->assertOk()->assertJson(['status' => 'ok', 'counts' => ['created' => 2]]);

        $first = $this->mirrored($this->companyId, 901);
        $second = $this->mirrored($this->companyId, 902);
        $this->assertSame([[11, '9917005', 2]], array_map(fn (array $level): array => [(int) DB::table('dem_vehicle_cert_levels')->where('id', $level[0])->value('sso_crew_level_id'), $level[1], $level[2]], $this->levels((int) $first->id)));
        $this->assertSame(['9917007', '9917001'], array_column($this->levels((int) $second->id), 1));
        $this->assertSame(1200, (int) DB::table('dem_vehicle_years')->where('dem_vehicle_id', $first->id)->value('dvehicle_12'));
        $this->assertNull($this->mirrored($this->companyId, 903), 'a row without a unit number is skipped');
        Http::assertNothingSent();
    }
}

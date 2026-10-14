<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Vehicles;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\MasterData\Vehicles\VehicleCatalog;

/**
 * vehicle.created|updated|deactivated on the standard table the migration
 * creates: SSO's row lands on the contract-named columns, and the home
 * location and division SSO ids are translated through the locations and
 * divisions mirrors.
 */
class VehicleWebhookTest extends VehiclesTestCase
{
    private int $companyId;

    private int $station;

    private int $north;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->north = (int) DB::table('divisions')->insertGetId(['company_id' => $this->companyId, 'name' => 'North', 'sso_division_id' => 501]);
        $this->station = (int) DB::table('locations')->insertGetId(['company_id' => $this->companyId, 'name' => 'Station 1', 'sso_location_id' => 801]);
        Http::fake();
    }

    public function test_created_inserts_the_row_with_local_location_and_division(): void
    {
        $this->deliver('vehicle.created', 70, $this->vehicleRecord(901, 'M-1', [
            'call_sign' => 'Medic 1', 'vin' => '1FDUF4HT5LDA12345', 'license_plate' => 'AB1234', 'license_plate_state' => '36',
            'home_location_id' => 801, 'division_id' => 501,
        ]))->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->mirrored($this->companyId, 901);
        $this->assertSame(['M-1', 'Medic 1', '1FDUF4HT5LDA12345', 'AB1234', '36', '1404001', 2021, 'diesel', 'in_service'], [
            $row->unit_number, $row->call_sign, $row->vin, $row->license_plate, $row->license_plate_state, $row->vehicle_type, (int) $row->model_year, $row->fuel_type, $row->status,
        ]);
        $this->assertSame([$this->station, $this->north], [(int) $row->home_location_id, (int) $row->division_id]);
        $this->assertSame([true, 1, '2026-10-11 12:00:00'], [(bool) $row->is_active, (int) $row->sort_order, $row->sso_updated_at]);
        $this->assertSame(['M-1'], VehicleCatalog::usableForCompany($this->companyId)->pluck('unit_number')->all());
        Http::assertNothingSent();
    }

    public function test_updated_overwrites_and_an_identical_delivery_changes_nothing(): void
    {
        $this->deliver('vehicle.created', 70, $this->vehicleRecord(901, 'M-1'));

        $edited = $this->vehicleRecord(901, 'M-1', ['color' => 'Red', 'export_unit_number' => 'MEDIC1', 'updated_at' => '2026-10-11T13:00:00+00:00']);
        $this->deliver('vehicle.updated', 70, $edited)->assertJson(['result' => 'updated']);
        $this->assertSame(['Red', 'MEDIC1'], [$this->mirrored($this->companyId, 901)->color, $this->mirrored($this->companyId, 901)->export_unit_number]);

        $this->deliver('vehicle.updated', 70, $edited)->assertJson(['result' => 'unchanged']);
    }

    public function test_deactivated_turns_the_row_off_and_keeps_it(): void
    {
        $this->deliver('vehicle.created', 70, $this->vehicleRecord(901, 'M-1'));

        $this->deliver('vehicle.deactivated', 70, $this->vehicleRecord(901, 'M-1', ['is_active' => false, 'updated_at' => '2026-10-11T14:00:00+00:00']))
            ->assertJson(['result' => 'updated']);

        $this->assertFalse((bool) $this->mirrored($this->companyId, 901)->is_active);
        $this->assertSame(1, DB::table('vehicles')->count());
        $this->assertSame([], VehicleCatalog::usableForCompany($this->companyId)->all());
    }

    public function test_an_older_delivery_is_stale(): void
    {
        $this->deliver('vehicle.created', 70, $this->vehicleRecord(901, 'M-2', ['updated_at' => '2026-10-11T15:00:00+00:00']));

        $this->deliver('vehicle.updated', 70, $this->vehicleRecord(901, 'M-1', ['updated_at' => '2026-10-11T10:00:00+00:00']))
            ->assertJson(['status' => 'stale']);

        $this->assertSame('M-2', $this->mirrored($this->companyId, 901)->unit_number);
    }

    public function test_location_and_division_follow_sso_but_an_unlinked_id_keeps_the_local_value(): void
    {
        $other = (int) DB::table('locations')->insertGetId(['company_id' => $this->companyId, 'name' => 'Station 2', 'sso_location_id' => 802]);
        $this->deliver('vehicle.created', 70, $this->vehicleRecord(901, 'M-1', ['home_location_id' => 801, 'division_id' => 501]));

        $this->deliver('vehicle.updated', 70, $this->vehicleRecord(901, 'M-1', ['home_location_id' => 999, 'division_id' => 999, 'updated_at' => '2026-10-11T13:00:00+00:00']));
        $row = $this->mirrored($this->companyId, 901);
        $this->assertSame([$this->station, $this->north], [(int) $row->home_location_id, (int) $row->division_id], 'ids this app has not linked leave the local value');

        $this->deliver('vehicle.updated', 70, $this->vehicleRecord(901, 'M-1', ['home_location_id' => 802, 'division_id' => null, 'updated_at' => '2026-10-11T14:00:00+00:00']));
        $row = $this->mirrored($this->companyId, 901);
        $this->assertSame($other, (int) $row->home_location_id);
        $this->assertNull($row->division_id);
    }

    public function test_an_unlinked_row_is_adopted_by_vin_first_as_pending_and_left_alone(): void
    {
        $byVin = $this->localVehicle($this->companyId, 'Ambulance 7', ['vin' => '1fduf4ht5lda12345', 'color' => 'Yellow']);
        $sameUnit = $this->localVehicle($this->companyId, 'M 1');

        $this->deliver('vehicle.created', 70, $this->vehicleRecord(901, 'M-1', ['vin' => '1FDUF4HT5LDA12345']))
            ->assertJson(['result' => 'linked']);

        $row = DB::table('vehicles')->find($byVin);
        $this->assertSame([901, true, 'Ambulance 7', 'Yellow'], [(int) $row->sso_vehicle_id, (bool) $row->sso_link_pending, $row->unit_number, $row->color]);
        $this->assertNull(DB::table('vehicles')->find($sameUnit)->sso_vehicle_id);

        $this->deliver('vehicle.deactivated', 70, $this->vehicleRecord(901, 'M-1', ['is_active' => false]))
            ->assertJson(['result' => 'pending']);
        $this->assertTrue((bool) DB::table('vehicles')->find($byVin)->is_active);
    }

    public function test_a_unit_number_match_with_a_different_vin_is_another_vehicle(): void
    {
        $replaced = $this->localVehicle($this->companyId, 'M1', ['vin' => '1FDUF4HT5LDA99999']);
        $byCallSign = $this->localVehicle($this->companyId, 'Unit 9', ['call_sign' => 'MEDIC 2']);

        $this->deliver('vehicle.created', 70, $this->vehicleRecord(901, 'M-1', ['vin' => '1FDUF4HT5LDA12345']))
            ->assertJson(['result' => 'created']);
        $this->assertNull(DB::table('vehicles')->find($replaced)->sso_vehicle_id);

        $this->deliver('vehicle.created', 70, $this->vehicleRecord(902, 'M-2', ['call_sign' => 'Medic-2']))
            ->assertJson(['result' => 'linked']);
        $this->assertSame(902, (int) DB::table('vehicles')->find($byCallSign)->sso_vehicle_id);
    }

    public function test_flag_off_unknown_company_and_other_agencies(): void
    {
        $theirs = $this->localVehicle($this->company(80), 'M-1');

        $this->deliver('vehicle.created', 999, $this->vehicleRecord(901, 'M-1'))
            ->assertJson(['status' => 'skipped', 'reason' => 'company_not_found']);

        $this->deliver('vehicle.created', 70, $this->vehicleRecord(901, 'M-1'))->assertJson(['result' => 'created']);
        $this->assertNull(DB::table('vehicles')->find($theirs)->sso_vehicle_id, 'another agency\'s row is never adopted');

        config(['sso.master_data.vehicles' => false]);
        $this->deliver('vehicle.updated', 70, $this->vehicleRecord(901, 'M-9', ['updated_at' => '2026-10-12T00:00:00+00:00']))
            ->assertJson(['status' => 'ignored']);
        $this->deliver('vehicle.created', 70, $this->vehicleRecord(903, 'M-3'))
            ->assertJson(['status' => 'ignored']);

        $this->assertSame('M-1', $this->mirrored($this->companyId, 901)->unit_number);
        $this->assertNull($this->mirrored($this->companyId, 903));
    }
}

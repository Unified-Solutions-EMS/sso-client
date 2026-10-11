<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Vehicles;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * sso:resync-master-data vehicles on Fleet-Management's table: the full
 * snapshot wins, vehicles SSO no longer lists are turned off (never
 * deleted), Fleet's own columns (mileage, notes) are never touched, and a
 * rerun changes nothing.
 */
class VehicleResyncTest extends VehiclesTestCase
{
    protected string $shape = 'fleet';

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    public function test_resync_upserts_turns_off_what_sso_dropped_and_is_idempotent(): void
    {
        $dropped = $this->localVehicle($this->companyId, 'OLD-4', ['sso_vehicle_id' => 899, 'mileage' => 210000]);
        $pending = $this->localVehicle($this->companyId, 'm 1', ['sso_vehicle_id' => 901, 'sso_link_pending' => true, 'mileage' => 48211, 'notes' => 'Brakes done 09/26', 'category' => 'Rig']);
        $fleetOnly = $this->localVehicle($this->companyId, 'TRAILER-1', ['category' => 'Trailer']);

        Http::fake(['sso.test/api/internal/companies/70/vehicles' => Http::response($this->snapshot(70, [
            $this->vehicleRecord(901, 'M-1', ['vin' => '1FDUF4HT5LDA12345', 'license_plate' => 'AB1234', 'call_sign' => 'Medic 1']),
            $this->vehicleRecord(902, 'E-2', ['vehicle_type' => '1404005', 'vehicle_type_name' => 'Fire Apparatus', 'fuel_type' => null, 'sort_order' => 2]),
            $this->vehicleRecord(903, 'R-3', ['is_active' => false, 'sort_order' => 3]),
        ]))]);

        $this->artisan('sso:resync-master-data', ['entity' => 'vehicles'])
            ->expectsOutputToContain('created=2 updated=1 linked=0 unchanged=0 deactivated=1')
            ->assertSuccessful();

        $confirmed = DB::table('vehicles')->find($pending);
        $this->assertSame(['M-1', '1FDUF4HT5LDA12345', 'AB1234', 'Ambulance', 'diesel', 'in_service', false], [
            $confirmed->unit_number, $confirmed->vin, $confirmed->license_plate, $confirmed->category, $confirmed->fuel_type, $confirmed->status, (bool) $confirmed->sso_link_pending,
        ]);
        $this->assertSame([48211, 'Brakes done 09/26'], [(int) $confirmed->mileage, $confirmed->notes], 'Fleet\'s own columns are never touched');

        $off = DB::table('vehicles')->find($dropped);
        $this->assertSame([false, 210000], [(bool) $off->is_active, (int) $off->mileage]);
        $this->assertSame('Fire Apparatus', $this->mirrored($this->companyId, 902)->category);
        $this->assertFalse((bool) $this->mirrored($this->companyId, 903)->is_active);
        $this->assertNull(DB::table('vehicles')->find($fleetOnly)->sso_vehicle_id, 'an unlinked local row is left alone');
        $this->assertSame(5, DB::table('vehicles')->count());

        $this->artisan('sso:resync-master-data', ['entity' => 'vehicles'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=3 deactivated=0')
            ->assertSuccessful();
    }

    public function test_link_by_name_links_by_vin_first_then_unit_number(): void
    {
        $byVin = $this->localVehicle($this->companyId, 'Ambulance Seven', ['vin' => '1FDUF4HT5LDA12345']);
        $byUnit = $this->localVehicle($this->companyId, 'e 2');
        $this->localVehicle($this->companyId, 'TRAILER-1');

        Http::fake(['sso.test/*' => Http::response($this->snapshot(70, [
            $this->vehicleRecord(901, 'M-1', ['vin' => '1FDUF4HT5LDA12345']),
            $this->vehicleRecord(902, 'E-2'),
            $this->vehicleRecord(903, 'SSO-ONLY'),
        ]))]);

        $this->artisan('sso:resync-master-data', ['entity' => 'vehicles', '--link-by-name' => true])
            ->expectsOutputToContain('linked 2, ambiguous 0, local only 1, SSO only 1')
            ->assertSuccessful();

        $this->assertSame(901, (int) DB::table('vehicles')->find($byVin)->sso_vehicle_id);
        $this->assertSame('Ambulance Seven', DB::table('vehicles')->find($byVin)->unit_number, 'linking changes no field');
        $this->assertSame(902, (int) DB::table('vehicles')->find($byUnit)->sso_vehicle_id);
        $this->assertSame(3, DB::table('vehicles')->count());
    }

    public function test_resync_is_refused_while_disabled(): void
    {
        config(['sso.master_data.vehicles' => false]);
        Http::fake();

        $this->artisan('sso:resync-master-data', ['entity' => 'vehicles'])
            ->expectsOutputToContain('sso.master_data.vehicles is disabled')
            ->assertFailed();

        Http::assertNothingSent();
    }
}

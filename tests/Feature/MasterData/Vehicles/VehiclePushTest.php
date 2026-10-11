<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Vehicles;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * sso:push-master-data vehicles: the one-time upward seed, run with the
 * entity still disabled. A home location or division goes up by SSO id once
 * this app's locations / divisions push linked it, otherwise by local id.
 */
class VehiclePushTest extends VehiclesTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sso.master_data.vehicles' => false]);
        $this->companyId = $this->company(70);
    }

    public function test_it_sends_the_vehicles_with_references_and_links_the_mapping(): void
    {
        $north = (int) DB::table('divisions')->insertGetId(['company_id' => $this->companyId, 'name' => 'North', 'sso_division_id' => 501]);
        $unpushedDivision = (int) DB::table('divisions')->insertGetId(['company_id' => $this->companyId, 'name' => 'South']);
        $station = (int) DB::table('locations')->insertGetId(['company_id' => $this->companyId, 'name' => 'Station 1', 'sso_location_id' => 801]);
        $unpushedLocation = (int) DB::table('locations')->insertGetId(['company_id' => $this->companyId, 'name' => 'Post 9']);

        $one = $this->localVehicle($this->companyId, 'M-1', [
            'call_sign' => 'Medic 1', 'vin' => '1FDUF4HT5LDA12345', 'model_year' => 2021, 'make' => 'Ford', 'fuel_type' => 'diesel',
            'home_location_id' => $station, 'division_id' => $north,
        ]);
        $two = $this->localVehicle($this->companyId, str_repeat('LONG', 8), [
            'vin' => '1FDUF4HT5LDA1234I', 'license_plate_state' => 'Nowhere',
            'home_location_id' => $unpushedLocation, 'division_id' => $unpushedDivision,
        ]);
        DB::table('vehicles')->where('id', $two)->update(['is_active' => false]);
        $foreign = $this->localVehicle($this->company(80), 'M-1');

        Http::fake(['sso.test/api/internal/companies/70/vehicles/import' => Http::response([
            'created' => 1, 'matched' => 1,
            'filled' => [['local_id' => (string) $one, 'name' => 'M-1', 'fields' => ['vin']]],
            'conflicts' => [['local_id' => (string) $one, 'name' => 'M-1', 'reason' => 'call_sign_differs']],
            'unresolved_locations' => [['local_id' => (string) $two, 'name' => 'LONGLONG', 'home_location_sso_id' => null, 'home_location_local_id' => (string) $unpushedLocation, 'reason' => 'unknown_location']],
            'unresolved_divisions' => [['local_id' => (string) $two, 'name' => 'LONGLONG', 'division_sso_id' => null, 'division_local_id' => (string) $unpushedDivision, 'reason' => 'unknown_division']],
            'partial' => [['local_id' => (string) $two, 'name' => 'LONGLONG', 'fields' => ['vin' => 'A VIN never uses I, O or Q.'], 'kept' => ['vin' => '1FDUF4HT5LDA1234I']]],
            'refused' => [['local_id' => (string) $two, 'name' => 'LONGLONG', 'fields' => ['license_plate_state' => 'Pick a state.']]],
            'invalid' => [],
            'mapping' => [
                (string) $one => ['sso_id' => 901, 'updated_at' => '2026-10-11T12:00:00+00:00', 'crew_levels' => [], 'yearly_usage' => []],
                (string) $two => ['sso_id' => 902, 'updated_at' => '2026-10-11T12:00:00+00:00', 'crew_levels' => [], 'yearly_usage' => []],
            ],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'vehicles', '--company' => '70'])
            ->expectsOutputToContain("name of local row {$two} is longer than SSO allows and is sent cut to its first 25 characters")
            ->expectsOutputToContain('created=1 matched=1 assignments_added=0 conflicts=1 truncated_descriptions=0 unknown_users=0 skipped_local_users_without_sso_id=0 linked=2 link_collisions=0 invalid=0 filled=1 partial=1 unresolved_locations=1 unresolved_divisions=1 refused=1')
            ->expectsTable(['Conflict', 'Reason'], [['M-1', 'call_sign_differs']])
            ->expectsTable(['Local id', 'Name', 'Local location', 'Reason'], [[(string) $two, 'LONGLONG', (string) $unpushedLocation, 'unknown_location']])
            ->expectsTable(['Local id', 'Name', 'Local division', 'Reason'], [[(string) $two, 'LONGLONG', (string) $unpushedDivision, 'unknown_division']])
            ->expectsTable(['Local id', 'Name', 'Field', 'Why', 'Kept as'], [[(string) $two, 'LONGLONG', 'vin', 'A VIN never uses I, O or Q.', '1FDUF4HT5LDA1234I']])
            ->expectsTable(['Local id', 'Name', 'Not saved in SSO', 'Because'], [[(string) $two, 'LONGLONG', 'license_plate_state', 'Pick a state.']])
            ->assertSuccessful();

        Http::assertSent(function (Request $request) use ($one, $two, $unpushedLocation, $unpushedDivision): bool {
            return $request->url() === 'https://sso.test/api/internal/companies/70/vehicles/import'
                && $request['app_slug'] === 'crew-scheduling'
                && $request['vehicles'] === [
                    [
                        'local_id' => $one, 'unit_number' => 'M-1', 'is_active' => true, 'home_location_sso_id' => 801, 'division_sso_id' => 501,
                        'call_sign' => 'Medic 1', 'vin' => '1FDUF4HT5LDA12345', 'model_year' => 2021, 'make' => 'Ford', 'fuel_type' => 'diesel',
                    ],
                    [
                        'local_id' => $two, 'unit_number' => mb_substr(str_repeat('LONG', 8), 0, 25), 'is_active' => false,
                        'home_location_local_id' => $unpushedLocation, 'division_local_id' => $unpushedDivision,
                        'vin' => '1FDUF4HT5LDA1234I', 'license_plate_state' => 'Nowhere',
                    ],
                ];
        });

        $linked = DB::table('vehicles')->find($one);
        $this->assertSame([901, '2026-10-11 12:00:00', false], [(int) $linked->sso_vehicle_id, $linked->sso_updated_at, (bool) $linked->sso_link_pending]);
        $this->assertSame(902, (int) DB::table('vehicles')->find($two)->sso_vehicle_id);
        $this->assertSame(str_repeat('LONG', 8), DB::table('vehicles')->find($two)->unit_number, 'the local unit number is unchanged');
        $this->assertNull(DB::table('vehicles')->find($foreign)->sso_vehicle_id);
    }

    public function test_a_rerun_reapplies_the_same_mapping_and_refuses_a_conflicting_one(): void
    {
        $one = $this->localVehicle($this->companyId, 'M-1', ['sso_vehicle_id' => 901]);
        $two = $this->localVehicle($this->companyId, 'M 1');

        Http::fake(['sso.test/api/internal/companies/70/vehicles/import' => Http::response([
            'created' => 0, 'matched' => 2, 'conflicts' => [], 'invalid' => [],
            'mapping' => [(string) $one => ['sso_id' => 901], (string) $two => ['sso_id' => 901]],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'vehicles'])
            ->expectsOutputToContain('linked=1 link_collisions=1')
            ->expectsTable(['Local id', 'SSO id', 'Not linked because'], [[(string) $two, '901', 'sso_id_held_by_another_local_row']])
            ->assertSuccessful();

        $this->assertNull(DB::table('vehicles')->find($two)->sso_vehicle_id);
    }

    public function test_dry_run_sends_nothing(): void
    {
        $one = $this->localVehicle($this->companyId, 'M-1');
        Http::fake();

        $this->artisan('sso:push-master-data', ['entity' => 'vehicles', '--dry-run' => true])
            ->expectsOutputToContain('would send 1 vehicles')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull(DB::table('vehicles')->find($one)->sso_vehicle_id);
    }
}

<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Vehicles;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\MasterData\Vehicles\VehicleProjection;
use Unified\SsoClient\Tests\Stubs\DemVehicleProjection;
use Unified\SsoClient\Tests\Stubs\FleetVehicleProjection;

/**
 * Fleet-Management cuts over first and creates the agency's vehicles in
 * SSO. CloudPCR cuts over second with an overlapping list in its own shape:
 * its "Medic 1" has Fleet's M-1 VIN (SSO matches by VIN and reports the
 * unit number difference), its "E2" has no VIN (SSO matches unit number
 * E-2), and R-3 is CloudPCR's alone. Both apps must end linked to the same
 * SSO ids, CloudPCR's vehicle and crew-level row ids unchanged (PCRs and
 * NEMSIS exports hold them), nothing duplicated or deleted.
 *
 * Both apps' tables share one test database; the bound projection and
 * app slug are switched to act as one app or the other.
 */
class VehicleSecondAppCutoverTest extends VehiclesTestCase
{
    protected string $shape = 'fleet';

    protected bool $runMirrorMigration = false;

    private int $companyId;

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createCloudPcrTables();

        $this->actAs('fleet');
        $this->runPublishedMirrorMigration();
        $this->actAs('cloudpcr');
        $this->runPublishedMirrorMigration();
        $this->actAs('fleet');
    }

    private function actAs(string $app): void
    {
        $this->app->bind(VehicleProjection::class, $app === 'fleet' ? FleetVehicleProjection::class : DemVehicleProjection::class);
        config(['sso.app_slug' => $app === 'fleet' ? 'fleet-management' : 'cloudpcr']);
    }

    /**
     * SSO after both pushes: Fleet's rows as Fleet created them (a match is
     * never overwritten), CloudPCR's crew level and year filled into M-1
     * (SSO had no children), R-3 created from CloudPCR.
     *
     * @return array<string, mixed>
     */
    private function ssoAfterBothPushes(): array
    {
        return $this->snapshot(70, [
            $this->vehicleRecord(901, 'M-1', ['vin' => '1FDUF4HT5LDA12345', 'license_plate' => 'AB1234',
                'crew_levels' => [$this->crewLevel(11, '9917005', 2)], 'yearly_usage' => [$this->yearlyUsage(21, 2025, 1200)]]),
            $this->vehicleRecord(902, 'E-2', ['vehicle_type' => '1404005', 'vehicle_type_name' => 'Fire Apparatus', 'sort_order' => 2]),
            $this->vehicleRecord(903, 'R-3', ['call_sign' => 'Rescue 3', 'sort_order' => 3]),
        ]);
    }

    public function test_fleet_then_cloudpcr_end_linked_to_the_same_sso_vehicles(): void
    {
        $this->companyId = $this->company(70);
        config(['sso.master_data.vehicles' => false]);

        $fleetM1 = $this->localVehicle($this->companyId, 'M-1', ['vin' => '1FDUF4HT5LDA12345', 'license_plate' => 'AB1234', 'category' => 'Ambulance', 'mileage' => 48211]);
        $fleetE2 = $this->localVehicle($this->companyId, 'E-2', ['category' => 'Engine', 'notes' => 'Pump test due']);

        $demMedic = (int) DB::table('dem_vehicles')->insertGetId(['company_id' => $this->companyId, 'dvehicle_01' => 'Medic 1', 'dvehicle_02' => '1FDUF4HT5LDA12345', 'dvehicle_04' => '1404001']);
        $demLevel = (int) DB::table('dem_vehicle_cert_levels')->insertGetId(['dem_vehicle_id' => $demMedic, 'dvehicle_05' => '9917005', 'dvehicle_06' => 2]);
        $demYear = (int) DB::table('dem_vehicle_years')->insertGetId(['dem_vehicle_id' => $demMedic, 'dvehicle_11' => 2025, 'dvehicle_12' => 1200]);
        $demE2 = (int) DB::table('dem_vehicles')->insertGetId(['company_id' => $this->companyId, 'dvehicle_01' => 'E2', 'dvehicle_04' => '1404005']);
        $demR3 = (int) DB::table('dem_vehicles')->insertGetId(['company_id' => $this->companyId, 'dvehicle_01' => 'R-3', 'dvehicle_03' => 'Rescue 3']);

        Http::fake(function (Request $request) use ($fleetM1, $fleetE2, $demMedic, $demLevel, $demYear, $demE2, $demR3) {
            if (! str_ends_with($request->url(), '/import')) {
                return Http::response($this->ssoAfterBothPushes());
            }

            return Http::response($request['app_slug'] === 'fleet-management'
                ? ['created' => 2, 'matched' => 0, 'conflicts' => [], 'invalid' => [], 'mapping' => [
                    (string) $fleetM1 => ['sso_id' => 901, 'updated_at' => '2026-10-11T12:00:00+00:00', 'crew_levels' => [], 'yearly_usage' => []],
                    (string) $fleetE2 => ['sso_id' => 902, 'updated_at' => '2026-10-11T12:00:00+00:00', 'crew_levels' => [], 'yearly_usage' => []],
                ]]
                : ['created' => 1, 'matched' => 2, 'invalid' => [],
                    'conflicts' => [['local_id' => (string) $demMedic, 'name' => 'Medic 1', 'reason' => 'unit_number_differs']],
                    'filled' => [['local_id' => (string) $demMedic, 'name' => 'Medic 1', 'fields' => ['crew_levels', 'yearly_usage']]],
                    'mapping' => [
                        (string) $demMedic => ['sso_id' => 901, 'updated_at' => '2026-10-11T12:00:00+00:00', 'crew_levels' => [(string) $demLevel => 11], 'yearly_usage' => [(string) $demYear => 21]],
                        (string) $demE2 => ['sso_id' => 902, 'updated_at' => '2026-10-11T12:00:00+00:00', 'crew_levels' => [], 'yearly_usage' => []],
                        (string) $demR3 => ['sso_id' => 903, 'updated_at' => '2026-10-11T12:00:00+00:00', 'crew_levels' => [], 'yearly_usage' => []],
                    ]]);
        });

        // Flag off: deliveries are ignored, the mirror changes nothing.
        $this->deliver('vehicle.created', 70, $this->vehicleRecord(901, 'M-1', ['vin' => '1FDUF4HT5LDA12345']))->assertJson(['status' => 'ignored']);
        $this->assertNull(DB::table('vehicles')->find($fleetM1)->sso_vehicle_id);

        // Fleet first.
        $this->artisan('sso:push-master-data', ['entity' => 'vehicles'])
            ->expectsOutputToContain('created=2 matched=0')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['app_slug'] === 'fleet-management' && $request['vehicles'][0] === [
            'local_id' => $fleetM1, 'unit_number' => 'M-1', 'is_active' => true,
            'vin' => '1FDUF4HT5LDA12345', 'license_plate' => 'AB1234', 'category' => 'Ambulance',
        ]);

        // CloudPCR second, against what Fleet put in SSO.
        $this->actAs('cloudpcr');
        $this->artisan('sso:push-master-data', ['entity' => 'vehicles'])
            ->expectsOutputToContain('created=1 matched=2 assignments_added=0 conflicts=1')
            ->expectsTable(['Conflict', 'Reason'], [['Medic 1', 'unit_number_differs']])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['app_slug'] === 'cloudpcr' && $request['vehicles'][0] === [
            'local_id' => $demMedic, 'unit_number' => 'Medic 1', 'is_active' => true, 'vin' => '1FDUF4HT5LDA12345', 'vehicle_type' => '1404001',
            'crew_levels' => [['local_id' => $demLevel, 'level' => '9917005', 'on_911_ambulance' => 2]],
            'yearly_usage' => [['local_id' => $demYear, 'year' => 2025, 'hours' => 1200]],
        ]);

        config(['sso.master_data.vehicles' => true]);

        $this->artisan('sso:resync-master-data', ['entity' => 'vehicles'])
            ->expectsOutputToContain('created=0 updated=3 linked=0 unchanged=0 deactivated=0 unlinked_children=0')
            ->assertSuccessful();

        $this->assertSame([$demMedic, $demE2, $demR3], DB::table('dem_vehicles')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([901, 902, 903], DB::table('dem_vehicles')->orderBy('id')->pluck('sso_vehicle_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('M-1', DB::table('dem_vehicles')->find($demMedic)->dvehicle_01, 'SSO kept Fleet\'s unit number; the push conflict is the cue to set it in SSO first');
        $this->assertSame([$demLevel], DB::table('dem_vehicle_cert_levels')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(11, (int) DB::table('dem_vehicle_cert_levels')->find($demLevel)->sso_crew_level_id);
        $this->assertSame([$demYear, 21], [(int) DB::table('dem_vehicle_years')->value('id'), (int) DB::table('dem_vehicle_years')->value('sso_yearly_usage_id')]);

        $this->artisan('sso:resync-master-data', ['entity' => 'vehicles'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=3 deactivated=0 unlinked_children=0')
            ->assertSuccessful();

        // Fleet picks up CloudPCR's R-3 and keeps its own columns.
        $this->actAs('fleet');
        $this->artisan('sso:resync-master-data', ['entity' => 'vehicles'])
            ->expectsOutputToContain('created=1 updated=2 linked=0 unchanged=0 deactivated=0')
            ->assertSuccessful();

        $this->assertSame([901, 902, 903], DB::table('vehicles')->orderBy('id')->pluck('sso_vehicle_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([$fleetM1, $fleetE2], DB::table('vehicles')->whereIn('sso_vehicle_id', [901, 902])->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([48211, 'Pump test due'], [(int) DB::table('vehicles')->find($fleetM1)->mileage, DB::table('vehicles')->find($fleetE2)->notes]);
    }
}

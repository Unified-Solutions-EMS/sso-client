<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Vehicles;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * A duplicate local unit (same VIN or unit number twice) that SSO matched to
 * the same vehicle: the first local row and its crew levels / yearly usage
 * link, the duplicate and its children are reported and left alone, and a
 * rerun changes nothing and never throws.
 */
class VehiclePushCollisionTest extends VehiclesTestCase
{
    protected string $shape = 'cloudpcr';

    private const HEADERS = ['Local id', 'Name', 'SSO id', 'Linked to local id', 'Not linked because'];

    public function test_a_duplicate_vehicle_and_its_children_are_skipped_and_the_rerun_is_idempotent(): void
    {
        config(['sso.master_data.vehicles' => false]);
        $companyId = $this->company(70);
        $first = $this->localVehicle($companyId, 'M-1', ['dvehicle_02' => '1FDUF4HT5LDA12345']);
        $copy = $this->localVehicle($companyId, 'M-1', ['dvehicle_02' => '1FDUF4HT5LDA12345']);
        $firstLevel = (int) DB::table('dem_vehicle_cert_levels')->insertGetId(['dem_vehicle_id' => $first, 'dvehicle_05' => '9917005']);
        $copyLevel = (int) DB::table('dem_vehicle_cert_levels')->insertGetId(['dem_vehicle_id' => $copy, 'dvehicle_05' => '9917005']);
        $firstYear = (int) DB::table('dem_vehicle_years')->insertGetId(['dem_vehicle_id' => $first, 'dvehicle_11' => 2025]);
        $copyYear = (int) DB::table('dem_vehicle_years')->insertGetId(['dem_vehicle_id' => $copy, 'dvehicle_11' => 2025]);
        $secondYear = (int) DB::table('dem_vehicle_years')->insertGetId(['dem_vehicle_id' => $first, 'dvehicle_11' => 2026]);

        Http::fake(['sso.test/api/internal/companies/70/vehicles/import' => Http::response([
            'created' => 1, 'matched' => 1, 'conflicts' => [], 'invalid' => [],
            'mapping' => [
                (string) $first => [
                    'sso_id' => 901, 'updated_at' => '2026-10-14T12:00:00+00:00',
                    'crew_levels' => [(string) $firstLevel => 11],
                    // SSO folded two yearly rows of this vehicle into one.
                    'yearly_usage' => [(string) $firstYear => 21, (string) $secondYear => 21],
                ],
                (string) $copy => [
                    'sso_id' => 901, 'updated_at' => '2026-10-14T12:00:00+00:00',
                    'crew_levels' => [(string) $copyLevel => 11],
                    'yearly_usage' => [(string) $copyYear => 21],
                ],
            ],
        ])]);

        foreach (['first run', 'rerun'] as $run) {
            $this->artisan('sso:push-master-data', ['entity' => 'vehicles', '--company' => '70'])
                ->expectsOutputToContain('linked=1 link_collisions=2')
                ->expectsTable(self::HEADERS, [
                    [(string) $copy, 'M-1', '901', (string) $first, 'sso_id_held_by_another_local_row'],
                    [(string) $secondYear, '', '21', (string) $firstYear, "yearly_usage of vehicle {$first}: sso_id_held_by_another_local_row"],
                ])
                ->assertSuccessful();

            $this->assertSame([901, null], [
                (int) DB::table('dem_vehicles')->find($first)->sso_vehicle_id,
                DB::table('dem_vehicles')->find($copy)->sso_vehicle_id,
            ], $run);
            $this->assertSame(11, (int) DB::table('dem_vehicle_cert_levels')->find($firstLevel)->sso_crew_level_id, $run);
            $this->assertNull(DB::table('dem_vehicle_cert_levels')->find($copyLevel)->sso_crew_level_id, $run);
            $this->assertSame(21, (int) DB::table('dem_vehicle_years')->find($firstYear)->sso_yearly_usage_id, $run);
            $this->assertNull(DB::table('dem_vehicle_years')->find($secondYear)->sso_yearly_usage_id, $run);
            $this->assertNull(DB::table('dem_vehicle_years')->find($copyYear)->sso_yearly_usage_id, $run);
        }

        $this->assertSame(2, DB::table('dem_vehicles')->count(), 'the duplicate vehicle is never deleted');
    }
}

<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Vehicles;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * With CloudPCR's projection bound, the same migration extends
 * `dem_vehicles` and both child tables and creates no `vehicles`; existing
 * rows keep their ids and start active.
 */
class VehicleMigrationExistingTablesTest extends VehiclesTestCase
{
    protected string $shape = 'cloudpcr';

    protected bool $runMirrorMigration = false;

    public function test_it_extends_dem_vehicles_and_the_child_tables_through_the_bound_projection(): void
    {
        $vehicle = $this->localVehicle($this->company(70), 'M-1');
        $level = (int) DB::table('dem_vehicle_cert_levels')->insertGetId(['dem_vehicle_id' => $vehicle, 'dvehicle_05' => '9917005']);

        $this->runPublishedMirrorMigration();
        $this->runPublishedMirrorMigration();

        $this->assertTrue(Schema::hasColumns('dem_vehicles', ['sso_vehicle_id', 'is_active', 'sort_order', 'sso_updated_at', 'sso_link_pending']));
        $this->assertTrue(Schema::hasColumn('dem_vehicle_cert_levels', 'sso_crew_level_id'));
        $this->assertTrue(Schema::hasColumn('dem_vehicle_years', 'sso_yearly_usage_id'));
        $this->assertFalse(Schema::hasTable('vehicles'));
        $this->assertTrue((bool) DB::table('dem_vehicles')->find($vehicle)->is_active);
        $this->assertSame($level, (int) DB::table('dem_vehicle_cert_levels')->value('id'));

        (require __DIR__.'/../../../../database/master-data/2026_10_11_000000_create_or_extend_vehicles_mirror.php')->down();

        $this->assertFalse(Schema::hasColumn('dem_vehicle_cert_levels', 'sso_crew_level_id'));
        $this->assertFalse(Schema::hasColumn('dem_vehicles', 'sso_vehicle_id'));
        $this->assertSame(1, DB::table('dem_vehicle_cert_levels')->count());
    }
}

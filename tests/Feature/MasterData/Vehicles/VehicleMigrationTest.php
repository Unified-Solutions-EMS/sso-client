<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Vehicles;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * The published migration creates the standard `vehicles` table for an
 * app with none, adds only the link and list columns, and is safe to rerun.
 */
class VehicleMigrationTest extends VehiclesTestCase
{
    protected bool $runMirrorMigration = false;

    private function migration(): object
    {
        return require __DIR__.'/../../../../database/master-data/2026_10_11_000000_create_or_extend_vehicles_mirror.php';
    }

    public function test_it_creates_the_standard_table_and_reruns_cleanly(): void
    {
        $this->assertFalse(Schema::hasTable('vehicles'));

        $this->migration()->up();
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumns('vehicles', [
            'company_id', 'unit_number', 'call_sign', 'export_unit_number', 'export_call_sign', 'vin', 'vehicle_type', 'model_year', 'initial_cost',
            'make', 'model', 'color', 'license_plate', 'license_plate_state', 'fuel_type', 'status', 'home_location_id', 'division_id',
            'sso_vehicle_id', 'is_active', 'sort_order', 'sso_updated_at', 'sso_link_pending',
        ]));
        $this->assertTrue(app(MasterDataRegistry::class)->mirror('vehicles')->isInstalled());

        $this->localVehicle($this->company(70), 'M-1');
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('vehicles', 'sso_vehicle_id'));
        $this->assertTrue(Schema::hasColumns('vehicles', ['is_active', 'sort_order']), 'list columns stay');
        $this->assertSame(1, DB::table('vehicles')->count());
    }

    public function test_mirror_is_not_installed_until_the_migration_runs(): void
    {
        $this->assertFalse(app(MasterDataRegistry::class)->mirror('vehicles')->isInstalled());

        $this->deliver('vehicle.created', 70, $this->vehicleRecord(901, 'M-1'))
            ->assertJson(['status' => 'skipped', 'reason' => 'mirror_not_installed']);
    }
}

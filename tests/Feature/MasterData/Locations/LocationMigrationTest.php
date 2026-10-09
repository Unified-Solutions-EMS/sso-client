<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Locations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * The published migration extends whichever table the bound projection
 * names (Crew's `locations` here, CloudPCR's `dem_locations` in
 * LocationMigrationCloudPcrTest), keeps every row, and is safe to rerun.
 */
class LocationMigrationTest extends LocationsTestCase
{
    protected bool $runMirrorMigration = false;

    private function migration(): object
    {
        return require __DIR__.'/../../../../database/master-data/2026_10_09_100000_create_or_extend_locations_mirror.php';
    }

    public function test_it_extends_crews_table_keeps_rows_active_and_reruns_cleanly(): void
    {
        $companyId = $this->company(70);
        $existing = $this->localLocation($companyId, 'Station 1', ['address' => 'Somewhere']);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumns('locations', ['sso_location_id', 'is_active', 'sort_order', 'sso_updated_at', 'sso_link_pending']));
        $row = DB::table('locations')->find($existing);
        $this->assertSame(['Station 1', 'Somewhere', true, false], [$row->name, $row->address, (bool) $row->is_active, (bool) $row->sso_link_pending]);

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('locations', 'sso_location_id'));
        $this->assertTrue(Schema::hasColumns('locations', ['is_active', 'sort_order']), 'list columns stay');
        $this->assertSame(1, DB::table('locations')->count());
    }

    public function test_mirror_is_not_installed_until_the_migration_runs(): void
    {
        $this->assertFalse(app(MasterDataRegistry::class)->mirror('locations')->isInstalled());

        $this->postWebhook('location.created', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1')])
            ->assertJson(['status' => 'skipped', 'reason' => 'mirror_not_installed']);
    }
}

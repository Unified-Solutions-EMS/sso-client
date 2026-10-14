<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Locations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * With CloudPCR's projection bound, the same migration extends
 * `dem_locations` (its own sort_order is kept) and creates no `locations`.
 */
class LocationMigrationCloudPcrTest extends LocationsTestCase
{
    protected bool $cloudPcrShape = true;

    protected bool $runMirrorMigration = false;

    public function test_it_extends_dem_locations_through_the_bound_projection(): void
    {
        $existing = $this->localLocation($this->company(70), 'Station 1', ['sort_order' => 4]);

        $this->runPublishedMirrorMigration();

        $this->assertTrue(Schema::hasColumns('dem_locations', ['sso_location_id', 'is_active', 'sso_updated_at', 'sso_link_pending']));
        $this->assertFalse(Schema::hasTable('locations'));
        $row = DB::table('dem_locations')->find($existing);
        $this->assertSame([4, true], [(int) $row->sort_order, (bool) $row->is_active]);
    }
}

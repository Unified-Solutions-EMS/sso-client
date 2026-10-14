<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Locations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * sso:resync-master-data locations: the full snapshot wins, rows SSO no
 * longer lists are turned off (never deleted), and a rerun changes nothing.
 */
class LocationResyncTest extends LocationsTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->localDivision($this->companyId, 'North District', ssoId: 501);
    }

    public function test_resync_upserts_turns_off_what_sso_dropped_and_is_idempotent(): void
    {
        $dropped = $this->localLocation($this->companyId, 'Old Post', ['sso_location_id' => 799]);
        DB::table('resources')->insert(['location_id' => $dropped, 'name' => 'Spare unit']);
        $pending = $this->localLocation($this->companyId, 'headquarters', ['sso_location_id' => 802, 'sso_link_pending' => true]);
        $unlinked = $this->localLocation($this->companyId, 'Crew Only Post');

        Http::fake(['sso.test/api/internal/companies/70/locations' => Http::response($this->snapshot(70, [
            $this->locationRecord(801, 'Station 1', ['division_id' => 501]),
            $this->locationRecord(802, 'Headquarters', ['location_type' => '1301001', 'sort_order' => 2]),
            $this->locationRecord(803, 'Turned Off Post', ['is_active' => false, 'sort_order' => 3]),
        ]))]);

        $this->artisan('sso:resync-master-data', ['entity' => 'locations'])
            ->expectsOutputToContain('created=2 updated=1 linked=0 unchanged=0 deactivated=1')
            ->assertSuccessful();

        $this->assertFalse((bool) DB::table('locations')->find($dropped)->is_active);
        $this->assertSame(1, DB::table('resources')->count(), 'nothing cascaded');
        $confirmed = DB::table('locations')->find($pending);
        $this->assertSame(['Headquarters', false], [$confirmed->name, (bool) $confirmed->sso_link_pending]);
        $this->assertNull(DB::table('locations')->find($unlinked)->sso_location_id, 'an unlinked local row is left alone');
        $this->assertFalse((bool) $this->mirrored($this->companyId, 803)->is_active);
        $this->assertSame(5, DB::table('locations')->count());

        $this->artisan('sso:resync-master-data', ['entity' => 'locations'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=3 deactivated=0')
            ->assertSuccessful();
    }

    public function test_link_by_name_links_without_creating_or_deleting(): void
    {
        $local = $this->localLocation($this->companyId, 'Station 1');
        $this->localLocation($this->companyId, 'Crew Only Post');

        Http::fake(['sso.test/*' => Http::response($this->snapshot(70, [
            $this->locationRecord(801, 'station 1'),
            $this->locationRecord(802, 'SSO Only Post'),
        ]))]);

        $this->artisan('sso:resync-master-data', ['entity' => 'locations', '--link-by-name' => true])
            ->expectsOutputToContain('linked 1, ambiguous 0, local only 1, SSO only 1')
            ->assertSuccessful();

        $this->assertSame(801, (int) DB::table('locations')->find($local)->sso_location_id);
        $this->assertSame('Station 1', DB::table('locations')->find($local)->name);
        $this->assertSame(2, DB::table('locations')->count());
    }

    public function test_resync_is_refused_while_disabled_and_before_the_migration(): void
    {
        config(['sso.master_data.locations' => false]);

        $this->artisan('sso:resync-master-data', ['entity' => 'locations'])
            ->expectsOutputToContain('sso.master_data.locations is disabled')
            ->assertFailed();
    }
}

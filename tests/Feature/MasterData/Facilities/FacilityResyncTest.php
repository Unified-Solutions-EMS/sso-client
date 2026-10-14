<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Facilities;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * sso:resync-master-data facilities: SSO's whole list wins, rows it no
 * longer lists are turned off, and a second run changes nothing.
 */
class FacilityResyncTest extends FacilitiesTestCase
{
    public function test_resync_writes_the_list_confirms_links_turns_off_the_rest_and_is_idempotent(): void
    {
        $companyId = $this->company(70);
        $pending = $this->localFacility($companyId, 'AMC', ['dfacility_03' => 'AMC-01', 'sso_facility_id' => 601, 'sso_link_pending' => true]);
        $dropped = $this->localFacility($companyId, 'Closed Hospital', ['sso_facility_id' => 699]);
        $unlinked = $this->localFacility($companyId, 'Never pushed');

        Http::fake(['sso.test/api/internal/companies/70/facilities' => Http::response([
            'company' => ['id' => 70],
            'facilities' => [
                $this->facilityRecord(601, 'Adirondack Medical Center', ['location_code' => 'AMC-01', 'phones' => [['id' => 1, 'number' => '+15188914141', 'type' => '9913009']]]),
                $this->facilityRecord(602, 'Alice Hyde Medical Center', ['type' => '1701009']),
            ],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'facilities'])
            ->expectsOutputToContain('created=1 updated=1 linked=0 unchanged=0 deactivated=1')
            ->assertSuccessful();

        $this->assertSame('Adirondack Medical Center', DB::table('dem_facility_entries')->where('id', $pending)->value('dfacility_02'));
        $this->assertFalse((bool) DB::table('dem_facility_entries')->where('id', $pending)->value('sso_link_pending'));
        $this->assertSame(1, DB::table('dem_facility_entry_phones')->where('dem_facility_entry_id', $pending)->count());
        $this->assertFalse((bool) DB::table('dem_facility_entries')->where('id', $dropped)->value('is_active'));
        $this->assertTrue((bool) DB::table('dem_facility_entries')->where('id', $unlinked)->value('is_active'), 'An unlinked row is not SSO\'s to turn off.');
        $this->assertSame(4, DB::table('dem_facility_entries')->count());

        $this->artisan('sso:resync-master-data', ['entity' => 'facilities'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=2 deactivated=0')
            ->assertSuccessful();
    }

    public function test_link_by_name_links_by_code_first(): void
    {
        $companyId = $this->company(70);
        $byCode = $this->localFacility($companyId, 'AMC', ['dfacility_03' => 'amc-01']);
        $byName = $this->localFacility($companyId, 'Alice Hyde Medical Center');

        Http::fake(['sso.test/api/internal/companies/70/facilities' => Http::response([
            'company' => ['id' => 70],
            'facilities' => [
                $this->facilityRecord(601, 'Adirondack Medical Center', ['location_code' => 'AMC-01']),
                $this->facilityRecord(602, 'Alice Hyde Medical Center'),
            ],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'facilities', '--link-by-name' => true])
            ->expectsOutputToContain('linked 2, ambiguous 0, local only 0, SSO only 0')
            ->assertSuccessful();

        $this->assertSame(601, (int) DB::table('dem_facility_entries')->where('id', $byCode)->value('sso_facility_id'));
        $this->assertSame(602, (int) DB::table('dem_facility_entries')->where('id', $byName)->value('sso_facility_id'));
    }
}

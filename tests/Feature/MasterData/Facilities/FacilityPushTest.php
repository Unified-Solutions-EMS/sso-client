<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Facilities;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * sso:push-master-data facilities from CloudPCR's shape, run with the entity
 * still off: the entries go up with their group's type, phones and fax, and
 * SSO's mapping links them in place.
 */
class FacilityPushTest extends FacilitiesTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sso.master_data.facilities' => false]);
        $this->companyId = $this->company(70);
    }

    public function test_it_sends_the_entries_and_links_the_mapping(): void
    {
        $amc = $this->localFacility($this->companyId, 'Adirondack Medical Center', [
            'dfacility_03' => 'AMC-01', 'dfacility_04' => json_encode(['9908007']), 'dfacility_05' => json_encode(['1234567893']),
            'dfacility_07' => '2233 W Dunlap Ave', 'dfacility_08' => '978016', 'mailing_city' => 'Saranac Lake', 'dfacility_09' => '36',
            'dfacility_10' => '12983', 'dfacility_11' => '36033', 'dfacility_12' => 'US', 'dfacility_13' => '44.3247,-74.1313',
        ]);
        DB::table('dem_facility_entry_phones')->insert([
            ['dem_facility_entry_id' => $amc, 'phone_number' => '518-891-4141', 'phone_type' => '9913009', 'sort_order' => 0],
            ['dem_facility_entry_id' => $amc, 'phone_number' => '518-891-0000', 'phone_type' => '9913001', 'sort_order' => 1],
        ]);
        $home = $this->localFacility($this->companyId, str_repeat('Long name ', 12), ['type' => '1701007']);
        DB::table('dem_facility_entries')->where('id', $home)->update(['is_active' => false]);
        $this->localFacility($this->company(80), 'Not ours');

        Http::fake(['sso.test/api/internal/companies/70/facilities/import' => Http::response([
            'created' => 1, 'matched' => 1,
            'conflicts' => [['local_id' => (string) $amc, 'name' => 'Adirondack Medical Center', 'reason' => 'code_differs']],
            'refused' => [['local_id' => (string) $home, 'name' => 'Long name', 'fields' => ['address' => 'Not saved yet: add the street.']]],
            'invalid' => [],
            'mapping' => [
                (string) $amc => ['sso_id' => 601, 'updated_at' => '2026-10-11T12:00:00+00:00'],
                (string) $home => ['sso_id' => 602, 'updated_at' => '2026-10-11T12:00:00+00:00'],
            ],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'facilities', '--company' => '70'])
            ->expectsOutputToContain("name of local row {$home} is longer than SSO allows and is sent cut to its first 100 characters")
            ->expectsOutputToContain('created=1 matched=1 assignments_added=0 conflicts=1 truncated_descriptions=0 unknown_users=0 skipped_local_users_without_sso_id=0 linked=2 link_collisions=0 invalid=0 refused=1')
            ->expectsTable(['Conflict', 'Reason'], [['Adirondack Medical Center', 'code_differs']])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['app_slug'] === 'cloudpcr' && $request['facilities'] === [
            [
                'local_id' => $amc, 'name' => 'Adirondack Medical Center', 'is_active' => true,
                'type' => '1701005', 'location_code' => 'AMC-01', 'designations' => ['9908007'], 'npis' => ['1234567893'], 'fax' => '518-891-0000',
                'address' => ['street' => '2233 W Dunlap Ave', 'city_gnis' => '978016', 'city_name' => 'Saranac Lake', 'state' => '36', 'zip' => '12983', 'county' => '36033', 'country' => 'US'],
                'latitude' => 44.3247, 'longitude' => -74.1313,
                'phones' => [['number' => '518-891-4141', 'type' => '9913009']],
            ],
            ['local_id' => $home, 'name' => mb_substr(str_repeat('Long name ', 12), 0, 100), 'is_active' => false, 'type' => '1701007'],
        ]);

        $this->assertSame(601, (int) DB::table('dem_facility_entries')->where('id', $amc)->value('sso_facility_id'));
        $this->assertSame('2026-10-11 12:00:00', DB::table('dem_facility_entries')->where('id', $amc)->value('sso_updated_at'));
        $this->assertSame(602, (int) DB::table('dem_facility_entries')->where('id', $home)->value('sso_facility_id'));
        $this->assertSame('Adirondack Medical Center', DB::table('dem_facility_entries')->where('id', $amc)->value('dfacility_02'), 'The push changes no local field.');
    }
}

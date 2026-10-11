<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Facilities;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Values this app pushed that SSO's rules refused come back in every row as
 * `pending` until the agency fixes or dismisses them. The mirror writes the
 * app's own value over SSO's empty one, so a resync or a delivery never
 * blanks what the app sent; another app's kept values are never applied.
 */
class FacilityPendingTest extends FacilitiesTestCase
{
    private int $companyId;

    private int $entryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->entryId = $this->localFacility($this->companyId, 'Mercy Clinic', [
            'sso_facility_id' => 601,
            'dfacility_05' => json_encode(['1234567890']),
            'dfacility_07' => 'Behind the airport hangars',
            'dfacility_09' => '36',
        ]);
        DB::table('dem_facility_entry_phones')->insert(['dem_facility_entry_id' => $this->entryId, 'phone_number' => 'ext 4', 'phone_type' => null, 'sort_order' => 0]);
    }

    /**
     * SSO's row for the facility after this app's push: NPIs, address and
     * the phone were refused, so SSO's own fields are empty.
     *
     * @param  array<string, mixed>  $pending
     * @return array<string, mixed>
     */
    private function ssoRow(array $pending): array
    {
        return $this->facilityRecord(601, 'Mercy Clinic', [
            'address' => array_fill_keys(['suite', 'street', 'street2', 'city_gnis', 'city_name', 'state', 'state_name', 'zip', 'county', 'county_name'], null) + ['country' => 'US'],
            'latitude' => null,
            'longitude' => null,
            'pending' => $pending,
            'updated_at' => '2026-10-12T12:00:00+00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function keptForThisApp(): array
    {
        return [
            'npis' => ['cloudpcr' => ['1234567890']],
            'address' => ['cloudpcr' => ['street' => 'Behind the airport hangars', 'state' => '36']],
            'phones' => ['cloudpcr' => [['number' => 'ext 4']]],
            'fax' => ['billing' => '34'],
        ];
    }

    private function resync(array $row): void
    {
        Http::fake(['sso.test/api/internal/companies/70/facilities' => Http::response(['company' => ['id' => 70], 'facilities' => [$row]])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'facilities'])->assertSuccessful();
    }

    /**
     * @return list<string>
     */
    private function phones(): array
    {
        return DB::table('dem_facility_entry_phones')->where('dem_facility_entry_id', $this->entryId)->orderBy('sort_order')->pluck('phone_number')->all();
    }

    public function test_a_resync_keeps_what_this_app_sent_where_sso_kept_it_as_pending(): void
    {
        $this->resync($this->ssoRow(self::keptForThisApp()));

        $entry = DB::table('dem_facility_entries')->find($this->entryId);
        $this->assertSame(['1234567890'], json_decode((string) $entry->dfacility_05, true));
        $this->assertSame('Behind the airport hangars', $entry->dfacility_07);
        $this->assertSame('36', $entry->dfacility_09);
        $this->assertSame(['ext 4'], $this->phones(), "Billing's kept fax is not this app's and is not applied.");
        $this->assertSame('2026-10-12 12:00:00', $entry->sso_updated_at);
    }

    public function test_a_delivery_keeps_it_too(): void
    {
        $this->deliver('facility.updated', 70, $this->ssoRow(self::keptForThisApp()))->assertOk();

        $entry = DB::table('dem_facility_entries')->find($this->entryId);
        $this->assertSame(['1234567890'], json_decode((string) $entry->dfacility_05, true));
        $this->assertSame('Behind the airport hangars', $entry->dfacility_07);
        $this->assertSame(['ext 4'], $this->phones());
    }

    public function test_once_the_agency_fixes_or_dismisses_it_ssos_value_wins(): void
    {
        $this->resync($this->facilityRecord(601, 'Mercy Clinic', ['npis' => ['1234567893'], 'phones' => [['id' => 9, 'number' => '+15185550122', 'type' => null]], 'pending' => (object) []]));

        $entry = DB::table('dem_facility_entries')->find($this->entryId);
        $this->assertSame(['1234567893'], json_decode((string) $entry->dfacility_05, true));
        $this->assertSame('2233 W Dunlap Ave', $entry->dfacility_07);
        $this->assertSame(['+15185550122'], $this->phones());
    }
}

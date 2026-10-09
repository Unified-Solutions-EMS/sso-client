<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Locations;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Crew-Scheduling cut over first, so SSO already has "Station 1 Saranac
 * Lake" (801) and "Headquarters" (802) from Crew's list: no numbers, Crew's
 * phone. CloudPCR cuts over second with an overlapping list in its own
 * shape (dem_locations, NEMSIS columns, phones in a child table, bound
 * through a projection) plus one location only it has. CloudPCR must end
 * with every dem_locations id unchanged (CAD units, scenes and exports hold
 * them), no duplicates, nothing deleted.
 */
class LocationSecondAppCutoverTest extends LocationsTestCase
{
    protected bool $cloudPcrShape = true;

    private int $companyId;

    private int $station;

    private int $hq;

    private int $tupper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->station = $this->localLocation($this->companyId, 'Station 1 Saranac Lake', [
            'dlocation_01' => '1301005', 'dlocation_03' => '1', 'dlocation_04' => '44.3247,-74.1313',
            'dlocation_06' => '120 Lake Flower Ave', 'dlocation_07' => '978016', 'mailing_city' => 'Saranac Lake',
            'dlocation_08' => '36', 'dlocation_09' => '12983', 'dlocation_10' => '36033', 'dlocation_11' => 'US', 'sort_order' => 1,
        ]);
        DB::table('dem_location_phones')->insert(['dem_location_id' => $this->station, 'phone_number' => '518-555-0142', 'phone_type' => '9913009']);
        $this->hq = $this->localLocation($this->companyId, 'Headquarters', ['dlocation_01' => '1301001', 'dlocation_03' => 'HQ']);
        $this->tupper = $this->localLocation($this->companyId, 'Tupper Lake Post', ['dlocation_01' => '1301003', 'dlocation_03' => '5']);
    }

    /**
     * SSO after both pushes: Crew's rows with CloudPCR's empty fields filled
     * in (number, address, GPS; Crew's phone was there first), and Tupper
     * Lake Post created from CloudPCR.
     *
     * @return array<string, mixed>
     */
    private function ssoAfterBothPushes(): array
    {
        return $this->snapshot(70, [
            $this->locationRecord(801, 'Station 1 Saranac Lake', ['number' => '1', 'phones' => [['id' => 9, 'number' => '+15185550199', 'type' => null]], 'updated_at' => '2026-10-09T12:00:00+00:00']),
            $this->locationRecord(802, 'Headquarters', ['number' => 'HQ', 'location_type' => '1301001', 'address' => ['street' => null, 'city_gnis' => null, 'city_name' => null, 'zip' => null, 'county' => null], 'latitude' => null, 'longitude' => null, 'sort_order' => 2]),
            $this->locationRecord(803, 'Tupper Lake Post', ['number' => '5', 'location_type' => '1301003', 'address' => ['street' => null, 'city_gnis' => null, 'city_name' => null, 'zip' => null, 'county' => null], 'latitude' => null, 'longitude' => null, 'sort_order' => 3]),
        ]);
    }

    public function test_documented_order_push_before_enable_keeps_every_id_and_duplicates_nothing(): void
    {
        config(['sso.master_data.locations' => false]);

        $this->postWebhook('location.created', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1 Saranac Lake')])
            ->assertJson(['status' => 'ignored']);

        Http::fake([
            'sso.test/api/internal/companies/70/locations/import' => Http::response([
                'created' => 1, 'matched' => 2, 'conflicts' => [], 'unresolved_divisions' => [], 'refused' => [], 'invalid' => [],
                'filled' => [['local_id' => (string) $this->station, 'name' => 'Station 1 Saranac Lake', 'fields' => ['number', 'address', 'gps']]],
                'mapping' => [
                    (string) $this->station => ['sso_id' => 801, 'updated_at' => '2026-10-09T12:00:00+00:00'],
                    (string) $this->hq => ['sso_id' => 802, 'updated_at' => '2026-10-09T12:00:00+00:00'],
                    (string) $this->tupper => ['sso_id' => 803, 'updated_at' => '2026-10-09T12:00:00+00:00'],
                ],
            ]),
            'sso.test/api/internal/companies/70/locations' => Http::response($this->ssoAfterBothPushes()),
        ]);

        $this->artisan('sso:push-master-data', ['entity' => 'locations'])
            ->expectsOutputToContain('created=1 matched=2')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/import')
            && $request['app_slug'] === 'cloudpcr'
            && $request['locations'][0] === [
                'local_id' => $this->station,
                'name' => 'Station 1 Saranac Lake',
                'is_active' => true,
                'number' => '1',
                'location_type' => '1301005',
                'address' => [
                    'street' => '120 Lake Flower Ave', 'street2' => null, 'city_gnis' => '978016', 'city_name' => 'Saranac Lake',
                    'state' => '36', 'zip' => '12983', 'county' => '36033', 'country' => 'US',
                ],
                'latitude' => 44.3247,
                'longitude' => -74.1313,
                'phones' => [['number' => '518-555-0142', 'type' => '9913009']],
            ]);

        config(['sso.master_data.locations' => true]);
        $this->artisan('sso:resync-master-data', ['entity' => 'locations'])->assertSuccessful();

        $this->assertSame([$this->station, $this->hq, $this->tupper], DB::table('dem_locations')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $station = DB::table('dem_locations')->find($this->station);
        $this->assertSame(['1', '120 Lake Flower Ave', '978016', '44.3247,-74.1313', 801], [$station->dlocation_03, $station->dlocation_06, $station->dlocation_07, $station->dlocation_04, (int) $station->sso_location_id]);
        $this->assertSame(['+15185550199'], DB::table('dem_location_phones')->where('dem_location_id', $this->station)->pluck('phone_number')->all(), 'SSO\'s phone list is the one that counts now');
        $this->assertSame('1301003', DB::table('dem_locations')->find($this->tupper)->dlocation_01);

        $this->artisan('sso:resync-master-data', ['entity' => 'locations'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=3 deactivated=0')
            ->assertSuccessful();
    }

    public function test_enabling_before_the_push_adopts_by_number_then_name_and_changes_nothing_else(): void
    {
        Http::fake();

        // Crew's spelling, but CloudPCR's number 1 already matches: adopted by number.
        $this->postWebhook('location.created', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Saranac Lake Station', ['number' => '1', 'address' => ['street' => '1 Somewhere Else']])])
            ->assertJson(['result' => 'linked']);
        // No number in SSO yet: adopted by name.
        $this->postWebhook('location.created', ['company' => ['id' => 70], 'location' => $this->locationRecord(802, 'HEADQUARTERS')])
            ->assertJson(['result' => 'linked']);

        $station = DB::table('dem_locations')->find($this->station);
        $this->assertSame([801, true, 'Station 1 Saranac Lake', '120 Lake Flower Ave'], [(int) $station->sso_location_id, (bool) $station->sso_link_pending, $station->dlocation_02, $station->dlocation_06]);
        $this->assertSame(802, (int) DB::table('dem_locations')->find($this->hq)->sso_location_id);
        $this->assertSame(['518-555-0142'], DB::table('dem_location_phones')->where('dem_location_id', $this->station)->pluck('phone_number')->all());

        $this->postWebhook('location.deactivated', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Saranac Lake Station', ['is_active' => false])])
            ->assertJson(['result' => 'pending']);
        $this->assertTrue((bool) DB::table('dem_locations')->find($this->station)->is_active);

        $this->assertSame(3, DB::table('dem_locations')->count());
        Http::assertNothingSent();
    }

    public function test_link_by_name_links_by_number_first(): void
    {
        Http::fake(['sso.test/*' => Http::response($this->snapshot(70, [
            $this->locationRecord(801, 'Saranac Lake Station', ['number' => '1']),
            $this->locationRecord(803, 'tupper lake post'),
        ]))]);

        $this->artisan('sso:resync-master-data', ['entity' => 'locations', '--link-by-name' => true])
            ->expectsOutputToContain('linked 2, ambiguous 0, local only 1, SSO only 0')
            ->assertSuccessful();

        $this->assertSame(801, (int) DB::table('dem_locations')->find($this->station)->sso_location_id);
        $this->assertSame(803, (int) DB::table('dem_locations')->find($this->tupper)->sso_location_id);
        $this->assertNull(DB::table('dem_locations')->find($this->hq)->sso_location_id);
    }
}

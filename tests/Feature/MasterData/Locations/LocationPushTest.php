<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Locations;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * sso:push-master-data locations from Crew-Scheduling: the one-time upward
 * seed, run with the entity still disabled. Crew's one-line address is split
 * into SSO's fields; each division goes up by local id, and by SSO id once
 * the divisions push linked it.
 */
class LocationPushTest extends LocationsTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sso.master_data.locations' => false]);
        $this->companyId = $this->company(70);
    }

    public function test_it_sends_crews_locations_and_links_the_mapping(): void
    {
        $north = $this->localDivision($this->companyId, 'North', ssoId: 501);
        $unpushed = $this->localDivision($this->companyId, 'Not Pushed');
        $one = $this->localLocation($this->companyId, 'Station 1', ['division_id' => $north, 'phone_number' => '518-555-0142', 'address' => '120 Lake Flower Ave, Saranac Lake, NY 12983, USA']);
        $two = $this->localLocation($this->companyId, str_repeat('Long name ', 15), ['division_id' => $unpushed, 'address' => 'Behind the firehouse']);
        DB::table('locations')->where('id', $two)->update(['is_active' => false]);
        $foreign = $this->localLocation($this->company(80), 'Elsewhere');

        Http::fake(['sso.test/api/internal/companies/70/locations/import' => Http::response([
            'created' => 1, 'matched' => 1, 'filled' => [['local_id' => (string) $one, 'name' => 'Station 1', 'fields' => ['division_id']]], 'conflicts' => [],
            'unresolved_divisions' => [['local_id' => (string) $two, 'name' => 'Long name', 'division_sso_id' => null, 'division_local_id' => (string) $unpushed, 'reason' => 'unknown_division']],
            'partial' => [['local_id' => (string) $two, 'name' => 'Long name', 'fields' => ['address' => 'Not saved yet: add the city, state and ZIP code.']]],
            'refused' => [['local_id' => (string) $two, 'name' => 'Long name', 'fields' => ['phones.new#0' => 'Enter a 10 digit phone number.']]],
            'invalid' => [],
            'mapping' => [
                (string) $one => ['sso_id' => 801, 'updated_at' => '2026-10-09T12:00:00+00:00'],
                (string) $two => ['sso_id' => 802, 'updated_at' => '2026-10-09T12:00:00+00:00'],
            ],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'locations', '--company' => '70'])
            ->expectsOutputToContain("name of local row {$two} is longer than SSO allows and is sent cut to its first 100 characters")
            ->expectsOutputToContain('created=1 matched=1 assignments_added=0 conflicts=0 truncated_descriptions=0 unknown_users=0 skipped_local_users_without_sso_id=0 linked=2 link_collisions=0 invalid=0 filled=1 partial=1 unresolved_divisions=1 refused=1')
            ->expectsTable(['Local id', 'Name', 'Local division', 'Reason'], [[(string) $two, 'Long name', (string) $unpushed, 'unknown_division']])
            ->expectsTable(['Local id', 'Name', 'Why', 'Cut from'], [[(string) $two, 'Long name', 'Not saved yet: add the city, state and ZIP code.', '']])
            ->expectsTable(['Local id', 'Name', 'Not saved in SSO', 'Because'], [[(string) $two, 'Long name', 'phones.new#0', 'Enter a 10 digit phone number.']])
            ->assertSuccessful();

        Http::assertSent(function (Request $request) use ($one, $two, $north, $unpushed): bool {
            return $request->url() === 'https://sso.test/api/internal/companies/70/locations/import'
                && $request['app_slug'] === 'crew-scheduling'
                && $request['locations'] === [
                    [
                        'local_id' => $one, 'name' => 'Station 1', 'is_active' => true, 'division_local_id' => $north, 'division_sso_id' => 501,
                        'address' => ['street' => '120 Lake Flower Ave', 'street2' => null, 'city_name' => 'Saranac Lake', 'state' => '36', 'zip' => '12983', 'country' => 'US'],
                        'phones' => [['number' => '518-555-0142']],
                    ],
                    [
                        'local_id' => $two, 'name' => mb_substr(str_repeat('Long name ', 15), 0, 100), 'is_active' => false, 'division_local_id' => $unpushed,
                        'address' => ['street' => 'Behind the firehouse'],
                    ],
                ];
        });

        $this->assertSame([801, '2026-10-09 12:00:00', false], [(int) DB::table('locations')->find($one)->sso_location_id, DB::table('locations')->find($one)->sso_updated_at, (bool) DB::table('locations')->find($one)->sso_link_pending]);
        $this->assertSame(str_repeat('Long name ', 15), DB::table('locations')->find($two)->name, 'the local name is unchanged');
        $this->assertNull(DB::table('locations')->find($foreign)->sso_location_id);
    }

    public function test_dry_run_sends_nothing(): void
    {
        $one = $this->localLocation($this->companyId, 'Station 1');
        Http::fake();

        $this->artisan('sso:push-master-data', ['entity' => 'locations', '--dry-run' => true])
            ->expectsOutputToContain('would send 1 locations')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull(DB::table('locations')->find($one)->sso_location_id);
    }
}

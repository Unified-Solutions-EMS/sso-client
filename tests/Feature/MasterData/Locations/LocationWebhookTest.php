<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Locations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\MasterData\Locations\LocationCatalog;

/**
 * location.created|updated|deactivated on Crew-Scheduling's shape: SSO's
 * structured record lands as Crew's name / phone_number / one-line address,
 * and SSO's division id is translated through the divisions mirror.
 */
class LocationWebhookTest extends LocationsTestCase
{
    private int $companyId;

    private int $north;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->north = $this->localDivision($this->companyId, 'North District', ssoId: 501);
        Http::fake();
    }

    public function test_created_inserts_crews_columns_with_the_local_division(): void
    {
        $this->postWebhook('location.created', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1 Saranac Lake', ['division_id' => 501])])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->mirrored($this->companyId, 801);
        $this->assertSame('Station 1 Saranac Lake', $row->name);
        $this->assertSame($this->north, (int) $row->division_id);
        $this->assertSame('(518) 555-0142', $row->phone_number);
        $this->assertSame('120 Lake Flower Ave, Saranac Lake, NY 12983', $row->address);
        $this->assertTrue((bool) $row->is_active);
        $this->assertSame(1, (int) $row->sort_order);
        $this->assertSame('2026-10-09 12:00:00', $row->sso_updated_at);
        Http::assertNothingSent();
    }

    public function test_updated_overwrites_and_an_identical_delivery_changes_nothing(): void
    {
        $this->postWebhook('location.created', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1')]);

        $this->postWebhook('location.updated', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1 Saranac Lake', [
            'address' => ['street' => '9 Main St'],
            'updated_at' => '2026-10-09T13:00:00+00:00',
        ])])->assertJson(['result' => 'updated']);

        $row = $this->mirrored($this->companyId, 801);
        $this->assertSame('Station 1 Saranac Lake', $row->name);
        $this->assertSame('9 Main St, Saranac Lake, NY 12983', $row->address);

        $this->postWebhook('location.updated', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1 Saranac Lake', [
            'address' => ['street' => '9 Main St'],
            'updated_at' => '2026-10-09T13:00:00+00:00',
        ])])->assertJson(['result' => 'unchanged']);
    }

    public function test_deactivated_turns_the_row_off_and_keeps_it_and_its_children(): void
    {
        $this->postWebhook('location.created', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1')]);
        $local = (int) $this->mirrored($this->companyId, 801)->id;
        DB::table('resources')->insert(['location_id' => $local, 'name' => 'Medic 1']);

        $this->postWebhook('location.deactivated', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1', [
            'is_active' => false,
            'updated_at' => '2026-10-09T14:00:00+00:00',
        ])])->assertJson(['result' => 'updated']);

        $this->assertFalse((bool) DB::table('locations')->find($local)->is_active);
        $this->assertSame(1, DB::table('resources')->count());
        $this->assertSame([], LocationCatalog::usableForCompany($this->companyId)->all());
    }

    public function test_an_older_delivery_is_stale(): void
    {
        $this->postWebhook('location.created', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'New Name', ['updated_at' => '2026-10-09T15:00:00+00:00'])]);

        $this->postWebhook('location.updated', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Old Name', ['updated_at' => '2026-10-09T10:00:00+00:00'])])
            ->assertJson(['status' => 'stale']);

        $this->assertSame('New Name', $this->mirrored($this->companyId, 801)->name);
    }

    public function test_the_division_follows_sso_but_an_unlinked_one_keeps_the_local_value(): void
    {
        $south = $this->localDivision($this->companyId, 'South District', ssoId: 502);
        $this->postWebhook('location.created', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1', ['division_id' => 501])]);

        // A division this app has not mirrored yet: the local value stays.
        $this->postWebhook('location.updated', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1', ['division_id' => 999, 'updated_at' => '2026-10-09T13:00:00+00:00'])]);
        $this->assertSame($this->north, (int) $this->mirrored($this->companyId, 801)->division_id);

        $this->postWebhook('location.updated', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1', ['division_id' => 502, 'updated_at' => '2026-10-09T14:00:00+00:00'])]);
        $this->assertSame($south, (int) $this->mirrored($this->companyId, 801)->division_id);

        $this->postWebhook('location.updated', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1', ['division_id' => null, 'updated_at' => '2026-10-09T15:00:00+00:00'])]);
        $this->assertNull($this->mirrored($this->companyId, 801)->division_id);
    }

    public function test_an_unlinked_same_name_row_is_adopted_as_pending_and_left_alone(): void
    {
        $local = $this->localLocation($this->companyId, 'station 1', ['division_id' => $this->north, 'phone_number' => '518-555-0000', 'address' => 'Crew typed this']);

        $this->postWebhook('location.created', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1', ['division_id' => null])])
            ->assertJson(['result' => 'linked']);

        $row = DB::table('locations')->find($local);
        $this->assertSame(801, (int) $row->sso_location_id);
        $this->assertTrue((bool) $row->sso_link_pending);
        $this->assertSame(['station 1', '518-555-0000', 'Crew typed this', $this->north], [$row->name, $row->phone_number, $row->address, (int) $row->division_id]);

        $this->postWebhook('location.deactivated', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1', ['is_active' => false])])
            ->assertJson(['result' => 'pending']);
        $this->assertTrue((bool) DB::table('locations')->find($local)->is_active);
        $this->assertSame(1, DB::table('locations')->count());
    }

    public function test_disabled_unknown_company_and_other_agencies(): void
    {
        $other = $this->company(80);
        $theirs = $this->localLocation($other, 'Station 1');

        $this->postWebhook('location.created', ['company' => ['id' => 999], 'location' => $this->locationRecord(801, 'Station 1')])
            ->assertJson(['status' => 'skipped', 'reason' => 'company_not_found']);

        $this->postWebhook('location.created', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Station 1')])
            ->assertJson(['result' => 'created']);
        $this->assertNull(DB::table('locations')->find($theirs)->sso_location_id, 'another agency\'s row is never adopted');

        config(['sso.master_data.locations' => false]);
        $this->postWebhook('location.updated', ['company' => ['id' => 70], 'location' => $this->locationRecord(801, 'Renamed', ['updated_at' => '2026-10-10T00:00:00+00:00'])])
            ->assertJson(['status' => 'ignored']);
        $this->assertSame('Station 1', $this->mirrored($this->companyId, 801)->name);
    }
}

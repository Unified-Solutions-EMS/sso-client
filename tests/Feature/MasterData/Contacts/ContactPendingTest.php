<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Contacts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * A delivery that adopts a local contact before this app pushed only links
 * it (pending): the agency's fields stay until the push mapping or a full
 * resync confirms the link, and only then do deliveries apply.
 */
class ContactPendingTest extends ContactsTestCase
{
    private int $companyId;

    private int $localId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->localId = $this->localContact($this->companyId, 'director', 'Sam', 'Ortiz', ['title' => 'Chief', 'website' => 'https://local.example']);
        $this->deliver('agency_contact.created', 70, $this->contactRecord(901, 'director', 'Sam', 'Ortiz'))->assertJson(['result' => 'linked']);
    }

    public function test_the_push_mapping_confirms_the_link_and_the_next_delivery_applies(): void
    {
        config(['sso.master_data.agency_contacts' => false]);
        Http::fake(['sso.test/api/internal/companies/70/agency_contacts/import' => Http::response([
            'created' => 0, 'matched' => 1, 'mapping' => [(string) $this->localId => ['sso_id' => 901, 'updated_at' => '2026-10-14T12:30:00+00:00']],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'agency_contacts', '--company' => '70'])->assertSuccessful();
        config(['sso.master_data.agency_contacts' => true]);

        $this->assertFalse((bool) DB::table('sso_agency_contacts')->where('id', $this->localId)->value('sso_link_pending'));

        $this->deliver('agency_contact.updated', 70, $this->contactRecord(901, 'director', 'Sam', 'Ortiz', ['title' => 'Fire Chief', 'updated_at' => '2026-10-14T13:00:00+00:00']))
            ->assertJson(['result' => 'updated']);

        $this->assertSame('Fire Chief', DB::table('sso_agency_contacts')->where('id', $this->localId)->value('title'));
    }

    public function test_until_then_deliveries_leave_the_row_alone(): void
    {
        $this->deliver('agency_contact.updated', 70, $this->contactRecord(901, 'director', 'Sam', 'Ortiz', ['title' => 'Fire Chief', 'updated_at' => '2026-10-14T13:00:00+00:00']))
            ->assertJson(['result' => 'pending']);
        $this->deliver('agency_contact.deactivated', 70, $this->contactRecord(901, 'director', 'Sam', 'Ortiz', ['is_active' => false, 'updated_at' => '2026-10-14T14:00:00+00:00']))
            ->assertJson(['result' => 'pending']);

        $row = DB::table('sso_agency_contacts')->find($this->localId);
        $this->assertSame('Chief', $row->title);
        $this->assertSame('https://local.example', $row->website);
        $this->assertTrue((bool) $row->is_active);
    }
}

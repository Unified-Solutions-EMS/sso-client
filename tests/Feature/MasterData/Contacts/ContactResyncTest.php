<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Contacts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * sso:resync-master-data agency_contacts: SSO's whole list wins, rows it no
 * longer lists are turned off, and a second run changes nothing.
 */
class ContactResyncTest extends ContactsTestCase
{
    public function test_resync_writes_the_list_confirms_links_turns_off_the_rest_and_is_idempotent(): void
    {
        $companyId = $this->company(70);
        $pending = $this->localContact($companyId, 'director', 'Sam', 'Ortiz', ['title' => 'Chief', 'sso_agency_contact_id' => 901, 'sso_link_pending' => true]);
        $dropped = $this->localContact($companyId, 'billing', 'Old', 'Contact', ['sso_agency_contact_id' => 999]);
        $unlinked = $this->localContact($companyId, 'other', 'Never', 'Pushed');

        Http::fake(['sso.test/api/internal/companies/70/agency_contacts' => Http::response([
            'company' => ['id' => 70],
            'agency_contacts' => [
                $this->contactRecord(901, 'director', 'Sam', 'Ortiz', ['title' => 'Fire Chief']),
                $this->contactRecord(902, 'medical_director', 'Dana', 'Reyes'),
            ],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'agency_contacts'])
            ->expectsOutputToContain('created=1 updated=1 linked=0 unchanged=0 deactivated=1')
            ->assertSuccessful();

        $this->assertSame('Fire Chief', DB::table('sso_agency_contacts')->where('id', $pending)->value('title'));
        $this->assertFalse((bool) DB::table('sso_agency_contacts')->where('id', $pending)->value('sso_link_pending'));
        $this->assertFalse((bool) DB::table('sso_agency_contacts')->where('id', $dropped)->value('is_active'));
        $this->assertTrue((bool) DB::table('sso_agency_contacts')->where('id', $unlinked)->value('is_active'), "An unlinked row is not SSO's to turn off.");
        $this->assertSame(4, DB::table('sso_agency_contacts')->count());

        $this->artisan('sso:resync-master-data', ['entity' => 'agency_contacts'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=2 deactivated=0')
            ->assertSuccessful();
    }

    public function test_link_by_name_matches_type_and_both_names_only(): void
    {
        $companyId = $this->company(70);
        $director = $this->localContact($companyId, 'director', 'sam', 'ORTIZ');
        $wrongType = $this->localContact($companyId, 'billing', 'Lee', 'Park');

        Http::fake(['sso.test/api/internal/companies/70/agency_contacts' => Http::response([
            'company' => ['id' => 70],
            'agency_contacts' => [
                $this->contactRecord(901, 'director', 'Sam', 'Ortiz'),
                $this->contactRecord(902, 'other', 'Lee', 'Park'),
            ],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'agency_contacts', '--link-by-name' => true])
            ->expectsOutputToContain('linked 1, ambiguous 0, local only 1, SSO only 1')
            ->assertSuccessful();

        $this->assertSame(901, (int) DB::table('sso_agency_contacts')->where('id', $director)->value('sso_agency_contact_id'));
        $this->assertNull(DB::table('sso_agency_contacts')->where('id', $wrongType)->value('sso_agency_contact_id'));
    }
}

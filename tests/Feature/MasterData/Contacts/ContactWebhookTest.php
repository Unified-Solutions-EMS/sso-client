<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Contacts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Contacts\ContactCatalog;
use Unified\SsoClient\Tests\Stubs\Models\ProfiledCompany;

/**
 * agency_contact.* webhooks into the package's sso_agency_contacts table.
 */
class ContactWebhookTest extends ContactsTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    public function test_the_migration_creates_the_table_and_is_safe_to_run_twice(): void
    {
        $this->runPublishedMirrorMigration();

        $this->assertTrue(Schema::hasColumns('sso_agency_contacts', ['company_id', 'name', 'type', 'is_primary', 'last_name', 'first_name', 'phones', 'emails', 'address_zip', 'md_board_certifications', 'sso_user_id', 'sso_agency_contact_id', 'is_active', 'sort_order', 'sso_updated_at', 'sso_link_pending']));
        $this->assertTrue(Schema::hasIndex('sso_agency_contacts', 'sso_agency_contacts_sso_agency_contact_unique'));
    }

    public function test_a_new_contact_lands_with_every_field(): void
    {
        $this->deliver('agency_contact.created', 70, $this->contactRecord(901, 'medical_director', 'Dana', 'Reyes', [
            'is_primary' => true,
            'middle_name' => 'Q',
            'title' => 'Medical Director',
            'phones' => [['type' => 'work', 'number' => '+15185550123']],
            'emails' => [['type' => 'work', 'address' => 'dreyes@agency.example']],
            'address' => ['street' => '12 Main St', 'city_name' => 'Saranac Lake', 'state' => '36', 'zip' => '12983'],
            'website' => 'https://agency.example',
            'md_degree' => '1101025',
            'md_board_certifications' => ['1101031'],
            'md_compensation' => '1101037',
            'md_board_certified' => true,
            'sso_user_id' => 5001,
        ]))->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->mirrored(901);
        $this->assertSame($this->companyId, (int) $row->company_id);
        $this->assertSame('Dana Reyes', $row->name, 'A record without a name gets "First Last".');
        $this->assertSame('medical_director', $row->type);
        $this->assertTrue((bool) $row->is_primary);
        $this->assertSame('Q', $row->middle_name);
        $this->assertSame([['type' => 'work', 'number' => '+15185550123']], json_decode($row->phones, true));
        $this->assertSame([['type' => 'work', 'address' => 'dreyes@agency.example']], json_decode($row->emails, true));
        $this->assertSame('12983', $row->address_zip);
        $this->assertSame(['1101031'], json_decode($row->md_board_certifications, true));
        $this->assertTrue((bool) $row->md_board_certified);
        $this->assertSame('5001', $row->sso_user_id);
        $this->assertSame('2026-10-14 12:00:00', $row->sso_updated_at);

        $this->deliver('agency_contact.updated', 70, $this->contactRecord(901, 'medical_director', 'Dana', 'Reyes', [
            'is_primary' => true,
            'middle_name' => 'Q',
            'title' => 'Medical Director',
            'phones' => [['type' => 'work', 'number' => '+15185550123']],
            'emails' => [['type' => 'work', 'address' => 'dreyes@agency.example']],
            'address' => ['street' => '12 Main St', 'city_name' => 'Saranac Lake', 'state' => '36', 'zip' => '12983'],
            'website' => 'https://agency.example',
            'md_degree' => '1101025',
            'md_board_certifications' => ['1101031'],
            'md_compensation' => '1101037',
            'md_board_certified' => true,
            'sso_user_id' => 5001,
        ]))->assertJson(['result' => 'unchanged']);
    }

    public function test_an_update_keeps_the_id_turning_off_never_deletes_and_an_older_delivery_is_ignored(): void
    {
        $this->deliver('agency_contact.created', 70, $this->contactRecord(901, 'director', 'Sam', 'Ortiz', ['updated_at' => '2026-10-14T13:00:00+00:00']));
        $id = (int) $this->mirrored(901)->id;

        $this->deliver('agency_contact.updated', 70, $this->contactRecord(901, 'director', 'Samuel', 'Ortiz', ['updated_at' => '2026-10-14T12:00:00+00:00']))
            ->assertJson(['status' => 'stale']);
        $this->assertSame('Sam', $this->mirrored(901)->first_name);

        $this->deliver('agency_contact.updated', 70, $this->contactRecord(901, 'director', 'Samuel', 'Ortiz', ['name' => 'Chief Samuel Ortiz', 'updated_at' => '2026-10-14T14:00:00+00:00']))
            ->assertJson(['result' => 'updated']);
        $this->assertSame($id, (int) $this->mirrored(901)->id);
        $this->assertSame('Chief Samuel Ortiz', $this->mirrored(901)->name, "SSO's own name wins when it sends one.");

        $this->deliver('agency_contact.deactivated', 70, $this->contactRecord(901, 'director', 'Samuel', 'Ortiz', ['is_active' => false, 'updated_at' => '2026-10-14T15:00:00+00:00']))
            ->assertJson(['result' => 'updated']);

        $this->assertFalse((bool) $this->mirrored(901)->is_active);
        $this->assertSame(1, DB::table('sso_agency_contacts')->count());
        $this->assertCount(0, ContactCatalog::activeForCompany($this->companyId));
    }

    public function test_adoption_needs_the_same_type_and_both_names_and_is_pending(): void
    {
        $director = $this->localContact($this->companyId, 'director', 'Sam', 'Ortiz', ['title' => 'Chief']);
        $billing = $this->localContact($this->companyId, 'billing', 'Lee', 'Park');

        $this->deliver('agency_contact.created', 70, $this->contactRecord(901, 'director', 'SAM', 'ortiz'))->assertJson(['result' => 'linked']);
        $this->deliver('agency_contact.created', 70, $this->contactRecord(902, 'other', 'Lee', 'Park'))->assertJson(['result' => 'created']);

        $this->assertSame($director, (int) $this->mirrored(901)->id);
        $this->assertSame('Chief', $this->mirrored(901)->title, "A pending adoption keeps the agency's fields.");
        $this->assertTrue((bool) $this->mirrored(901)->sso_link_pending);
        $this->assertNotSame($billing, (int) $this->mirrored(902)->id, 'The same person under another type is another contact.');

        $this->deliver('agency_contact.updated', 70, $this->contactRecord(901, 'director', 'Sam', 'Ortiz', ['updated_at' => '2026-10-14T13:00:00+00:00']))
            ->assertJson(['result' => 'pending']);
    }

    public function test_two_local_candidates_adopt_neither(): void
    {
        $this->localContact($this->companyId, 'training', 'Pat', 'Kim');
        $this->localContact($this->companyId, 'training', 'Pat', 'Kim');

        $this->deliver('agency_contact.created', 70, $this->contactRecord(901, 'training', 'Pat', 'Kim'))->assertJson(['result' => 'created']);

        $this->assertSame(3, DB::table('sso_agency_contacts')->count());
    }

    public function test_the_batch_upserts_every_row(): void
    {
        $this->postWebhook('agency_contact.batch_updated', [
            'company' => ['id' => 70],
            'ids' => [901, 902],
            'agency_contacts' => [
                $this->contactRecord(901, 'director', 'Sam', 'Ortiz', ['sort_order' => 2]),
                $this->contactRecord(902, 'billing', 'Lee', 'Park', ['sort_order' => 1, 'is_primary' => true]),
            ],
            'updated_at' => '2026-10-14T12:00:00+00:00',
        ])->assertOk()->assertJson(['status' => 'ok', 'counts' => ['created' => 2]]);

        $this->assertSame(['Lee Park', 'Sam Ortiz'], ContactCatalog::activeForCompany($this->companyId)->pluck('name')->all(), 'SSO order first.');
        $this->assertSame('Lee Park', ContactCatalog::primaryForCompany($this->companyId)?->name);
        $this->assertSame(['Sam Ortiz'], ContactCatalog::ofType($this->companyId, 'director')->pluck('name')->all());

        $company = ProfiledCompany::query()->findOrFail($this->companyId);
        $this->assertCount(2, $company->agencyContacts());
        $this->assertSame('Lee Park', $company->primaryAgencyContact()?->name);
        $this->assertCount(1, $company->agencyContactsOfType('billing'));
    }

    public function test_another_companys_contacts_are_never_touched(): void
    {
        $other = $this->company(80);
        $theirs = $this->localContact($other, 'director', 'Sam', 'Ortiz');

        $this->deliver('agency_contact.created', 70, $this->contactRecord(901, 'director', 'Sam', 'Ortiz'))->assertJson(['result' => 'created']);

        $this->assertNull(DB::table('sso_agency_contacts')->where('id', $theirs)->value('sso_agency_contact_id'));
        $this->assertCount(1, ContactCatalog::activeForCompany($other));
        $this->assertCount(1, ContactCatalog::activeForCompany($this->companyId));
    }

    public function test_a_disabled_entity_ignores_contact_events(): void
    {
        config(['sso.master_data.agency_contacts' => false]);

        $this->deliver('agency_contact.created', 70, $this->contactRecord(901, 'director', 'Sam', 'Ortiz'))
            ->assertJson(['status' => 'ignored', 'reason' => 'master_data.agency_contacts disabled']);
        $this->assertSame(0, DB::table('sso_agency_contacts')->count());
    }
}

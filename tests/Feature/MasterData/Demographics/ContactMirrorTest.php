<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Demographics;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\Trial\TrialMirrorCleaner;

/**
 * contacts on the standard `sso_agency_contacts` read model: contact.*
 * webhooks, adoption by last name + first name + type, the resync, the
 * push and the trial purge.
 */
class ContactMirrorTest extends DemographicsTestCase
{
    protected string $entity = 'contacts';

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    /**
     * SSO's AgencyContact::toContractArray().
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function contact(int $id, string $first, string $last, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'type' => 'medical_director',
            'type_name' => 'Medical director',
            'nemsis_type' => '1101003',
            'name' => "{$first} {$last}",
            'is_primary' => true,
            'first_name' => $first,
            'middle_name' => null,
            'last_name' => $last,
            'title' => 'Dr.',
            'phones' => [['number' => '+15188914141', 'type' => '9913009']],
            'emails' => [['address' => 'md@example.com', 'type' => '9904001']],
            'address' => ['street' => '1 Main St', 'city_name' => 'Saranac Lake', 'state' => '36', 'zip' => '12983'],
            'website' => null,
            'medical_director' => ['degree' => '1101001', 'board_certifications' => [], 'compensation' => null, 'board_certified' => true],
            'fellowship_status' => null,
            'sso_user_id' => null,
            'is_active' => true,
            'sort_order' => 1,
            'updated_at' => '2026-10-14T12:00:00+00:00',
        ], $overrides);
    }

    private function mirrored(int $ssoId): ?object
    {
        return DB::table('sso_agency_contacts')->where('sso_contact_id', $ssoId)->first();
    }

    private function localContact(int $companyId, string $first, string $last, string $type = 'medical_director', array $attributes = []): int
    {
        return (int) DB::table('sso_agency_contacts')->insertGetId([
            'company_id' => $companyId,
            'first_name' => $first,
            'last_name' => $last,
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ] + $attributes);
    }

    public function test_a_webhook_writes_the_contact_updates_it_ignores_an_older_delivery_and_turns_off_without_deleting(): void
    {
        $this->deliver('contact.created', 70, $this->contact(501, 'Jane', 'Smith', ['dcontact_nv' => null, 'fellowship_status_nv' => '7701003']))
            ->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->mirrored(501);
        $this->assertSame($this->companyId, (int) $row->company_id);
        $this->assertSame('Smith', $row->last_name);
        $this->assertSame('1101003', $row->nemsis_type);
        $this->assertSame([['number' => '+15188914141', 'type' => '9913009']], json_decode($row->phones, true));
        $this->assertSame(['fellowship_status_nv' => '7701003'], json_decode($row->not_values, true));
        $this->assertSame('2026-10-14 12:00:00', $row->sso_updated_at);

        $this->deliver('contact.updated', 70, $this->contact(501, 'Jane', 'Smith-Jones', ['updated_at' => '2026-10-14T13:00:00+00:00']))
            ->assertJson(['result' => 'updated']);
        $this->deliver('contact.updated', 70, $this->contact(501, 'Jane', 'Old', ['updated_at' => '2026-10-14T12:30:00+00:00']))
            ->assertJson(['status' => 'stale']);
        $this->assertSame('Smith-Jones', $this->mirrored(501)->last_name);

        $this->deliver('contact.deactivated', 70, $this->contact(501, 'Jane', 'Smith-Jones', ['is_active' => false, 'updated_at' => '2026-10-14T14:00:00+00:00']))
            ->assertJson(['result' => 'updated']);
        $this->assertFalse((bool) $this->mirrored(501)->is_active);
        $this->assertSame(1, DB::table('sso_agency_contacts')->count());
    }

    public function test_a_delivery_adopts_by_last_first_and_type_ignoring_case_and_leaves_it_pending(): void
    {
        $local = $this->localContact($this->companyId, 'JANE', 'smith', 'medical_director', ['title' => 'Local title']);
        $otherType = $this->localContact($this->companyId, 'Jane', 'Smith', 'administrator');

        $this->deliver('contact.created', 70, $this->contact(501, 'Jane', 'Smith'))->assertJson(['result' => 'linked']);

        $row = $this->mirrored(501);
        $this->assertSame($local, (int) $row->id);
        $this->assertTrue((bool) $row->sso_link_pending);
        $this->assertSame('Local title', $row->title, 'A pending adoption keeps the agency\'s fields.');
        $this->assertNull(DB::table('sso_agency_contacts')->where('id', $otherType)->value('sso_contact_id'));

        $this->deliver('contact.updated', 70, $this->contact(501, 'Jane', 'Smith', ['updated_at' => '2026-10-14T13:00:00+00:00']))
            ->assertJson(['result' => 'pending']);
    }

    public function test_kept_values_of_this_app_are_written_over_ssos_and_other_apps_are_not(): void
    {
        $this->deliver('contact.created', 70, $this->contact(501, 'Jane', 'Smith', [
            'website' => null,
            'pending' => [
                'website' => ['cloudpcr' => 'not a url', 'hr' => 'hr value'],
                'phones' => ['cloudpcr' => [['number' => '555-0100', 'type' => null]]],
                'title' => ['hr' => 'HR title'],
            ],
        ]))->assertJson(['result' => 'created']);

        $row = $this->mirrored(501);
        $this->assertSame('not a url', $row->website);
        $this->assertSame('Dr.', $row->title);
        $this->assertSame(['+15188914141', '555-0100'], array_column(json_decode($row->phones, true), 'number'));
    }

    public function test_the_batch_upserts_every_row_and_another_companys_rows_are_never_touched(): void
    {
        $other = $this->company(80);
        $theirs = $this->localContact($other, 'Jane', 'Smith');

        $this->postWebhook('contact.batch_updated', [
            'company' => ['id' => 70],
            'ids' => [501, 502],
            'contacts' => [$this->contact(501, 'Jane', 'Smith'), $this->contact(502, 'Ann', 'Lee', ['type' => 'administrator'])],
            'updated_at' => '2026-10-14T12:00:00+00:00',
        ])->assertOk()->assertJson(['status' => 'ok', 'counts' => ['created' => 2]]);

        $this->assertSame(2, DB::table('sso_agency_contacts')->where('company_id', $this->companyId)->count());
        $this->assertNull(DB::table('sso_agency_contacts')->where('id', $theirs)->value('sso_contact_id'));
    }

    public function test_a_disabled_entity_ignores_contact_events(): void
    {
        config(['sso.master_data.contacts' => false]);

        $this->deliver('contact.created', 70, $this->contact(501, 'Jane', 'Smith'))
            ->assertOk()->assertJson(['status' => 'ignored', 'reason' => 'master_data.contacts disabled']);
        $this->assertSame(0, DB::table('sso_agency_contacts')->count());
    }

    public function test_resync_writes_the_list_confirms_pending_links_turns_off_dropped_linked_rows_and_is_idempotent(): void
    {
        $pending = $this->localContact($this->companyId, 'Jane', 'Smith', 'medical_director', ['sso_contact_id' => 501, 'sso_link_pending' => true]);
        $dropped = $this->localContact($this->companyId, 'Old', 'Contact', 'administrator', ['sso_contact_id' => 599]);
        $unlinked = $this->localContact($this->companyId, 'Never', 'Pushed', 'administrator');

        Http::fake(['sso.test/api/internal/companies/70/contacts' => Http::response([
            'company' => ['id' => 70],
            'contacts' => [$this->contact(501, 'Jane', 'Smith'), $this->contact(502, 'Ann', 'Lee', ['type' => 'administrator'])],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'contacts'])
            ->expectsOutputToContain('created=1 updated=1 linked=0 unchanged=0 deactivated=1')
            ->assertSuccessful();

        $this->assertFalse((bool) DB::table('sso_agency_contacts')->where('id', $pending)->value('sso_link_pending'));
        $this->assertSame('Dr.', DB::table('sso_agency_contacts')->where('id', $pending)->value('title'));
        $this->assertFalse((bool) DB::table('sso_agency_contacts')->where('id', $dropped)->value('is_active'));
        $this->assertTrue((bool) DB::table('sso_agency_contacts')->where('id', $unlinked)->value('is_active'), 'An unlinked row is not SSO\'s to turn off.');
        $this->assertSame(4, DB::table('sso_agency_contacts')->count());

        $this->artisan('sso:resync-master-data', ['entity' => 'contacts'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=2 deactivated=0')
            ->assertSuccessful();
    }

    public function test_link_by_name_links_by_last_first_and_type(): void
    {
        $jane = $this->localContact($this->companyId, 'jane', 'SMITH');
        $this->localContact($this->companyId, 'Ann', 'Lee');
        $this->localContact($this->companyId, 'Ann', 'Lee');

        Http::fake(['sso.test/api/internal/companies/70/contacts' => Http::response([
            'company' => ['id' => 70],
            'contacts' => [$this->contact(501, 'Jane', 'Smith'), $this->contact(502, 'Ann', 'Lee'), $this->contact(503, 'Bo', 'Diaz')],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'contacts', '--link-by-name' => true])
            ->expectsOutputToContain('linked 1, ambiguous 1, local only 0, SSO only 1')
            ->assertSuccessful();

        $this->assertSame(501, (int) DB::table('sso_agency_contacts')->where('id', $jane)->value('sso_contact_id'));
    }

    public function test_the_push_sends_the_contacts_links_the_mapping_and_reports_duplicates(): void
    {
        config(['sso.master_data.contacts' => false]);

        $jane = $this->localContact($this->companyId, 'Jane', 'Smith', 'medical_director', [
            'phones' => json_encode([['number' => '518-891-4141', 'type' => '9913009']]),
            'not_values' => json_encode(['fellowship_status_nv' => '7701003']),
        ]);
        $copy = $this->localContact($this->companyId, 'JANE', 'smith');
        DB::table('sso_agency_contacts')->where('id', $copy)->update(['is_active' => false]);
        $this->localContact($this->company(80), 'Not', 'Ours');

        Http::fake(['sso.test/api/internal/companies/70/contacts/import' => Http::response([
            'created' => 1, 'matched' => 1, 'filled' => [],
            'conflicts' => [['local_id' => $jane, 'name' => 'Jane Smith', 'field' => 'title', 'local_value' => null, 'sso_value' => 'Dr.']],
            'invalid' => [],
            'mapping' => [
                (string) $jane => ['sso_id' => 501, 'updated_at' => '2026-10-14T12:00:00+00:00'],
                (string) $copy => ['sso_id' => 501, 'updated_at' => '2026-10-14T12:00:00+00:00'],
            ],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'contacts', '--company' => '70'])
            ->expectsOutputToContain('created=1 matched=1 assignments_added=0 conflicts=1 truncated_descriptions=0 unknown_users=0 skipped_local_users_without_sso_id=0 linked=1 link_collisions=1 invalid=0 filled=0')
            ->expectsTable(['Local id', 'Name', 'Field', 'This app had', 'SSO kept'], [[(string) $jane, 'Jane Smith', 'title', '', 'Dr.']])
            ->expectsTable(['Local id', 'Name', 'SSO id', 'Linked to local id', 'Not linked because'], [[(string) $copy, 'smith', '501', (string) $jane, 'sso_id_held_by_another_local_row']])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['app_slug'] === 'cloudpcr' && $request['contacts'] === [
            ['local_id' => $jane, 'is_active' => true, 'type' => 'medical_director', 'is_primary' => false, 'first_name' => 'Jane', 'last_name' => 'Smith', 'phones' => [['number' => '518-891-4141', 'type' => '9913009']], 'fellowship_status_nv' => '7701003'],
            ['local_id' => $copy, 'is_active' => false, 'type' => 'medical_director', 'is_primary' => false, 'first_name' => 'JANE', 'last_name' => 'smith'],
        ]);

        $this->assertSame(501, (int) DB::table('sso_agency_contacts')->where('id', $jane)->value('sso_contact_id'));
        $this->assertSame('2026-10-14 12:00:00', DB::table('sso_agency_contacts')->where('id', $jane)->value('sso_updated_at'));
        $this->assertNull(DB::table('sso_agency_contacts')->where('id', $copy)->value('sso_contact_id'));
    }

    public function test_the_trial_purge_removes_only_the_listed_contacts_of_this_company(): void
    {
        $sample = $this->localContact($this->companyId, 'Sample', 'Director', 'medical_director', ['sso_contact_id' => 501]);
        $prospect = $this->localContact($this->companyId, 'Real', 'Person', 'administrator', ['sso_contact_id' => 502]);
        $other = $this->localContact($this->company(80), 'Sample', 'Director', 'medical_director', ['sso_contact_id' => 501]);

        $report = app(TrialMirrorCleaner::class)->clean($this->companyId, ['contacts' => [501]]);

        $this->assertSame(['contacts' => ['deleted' => 1, 'deactivated' => 0, 'kept' => 0, 'missing' => 0]], $report);
        $this->assertNull(DB::table('sso_agency_contacts')->where('id', $sample)->first());
        $this->assertNotNull(DB::table('sso_agency_contacts')->where('id', $prospect)->first());
        $this->assertNotNull(DB::table('sso_agency_contacts')->where('id', $other)->first());
    }
}

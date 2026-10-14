<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Support\Facades\DB;

/**
 * `person.updated` / `person.deactivated` into CloudPCR's dem_personnel:
 * row ids never change (PCR crew rows hold them), children are updated in
 * place by SSO's child ids, out-of-order deliveries are ignored, a row
 * adopted before the push stays the agency's until confirmed, and a person
 * who leaves is turned off, never deleted.
 */
class PersonnelWebhookTest extends PersonnelTestCase
{
    public function test_a_disabled_entity_acks_and_writes_nothing(): void
    {
        config(['sso.master_data.personnel' => false]);
        $this->company(70);
        $this->user(9001);

        $this->deliver($this->person(70, 9001))
            ->assertOk()
            ->assertJson(['status' => 'ignored', 'action' => 'person.updated', 'reason' => 'master_data.personnel disabled']);

        $this->assertSame(0, DB::table('dem_personnel')->count());
    }

    public function test_person_updated_creates_the_row_and_its_children(): void
    {
        $companyId = $this->company(70);
        $userId = $this->user(9001);

        $this->deliver($this->person(70, 9001))->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->demRow($companyId, 9001);
        $this->assertNotNull($row);
        $this->assertSame($userId, (int) $row->user_id);
        $this->assertSame('Reyes', $row->dpersonnel_01);
        $this->assertSame('Jordan', $row->dpersonnel_02);
        $this->assertNull($row->dpersonnel_03);
        $this->assertSame('7701003', $row->dpersonnel_03_nv, 'A blank nillable recommended element exports as Not Recorded');
        $this->assertNull($row->dpersonnel_02_nv);
        $this->assertSame('user9001@example.com', $row->dpersonnel_10);
        $this->assertSame('1988-04-12', $row->dpersonnel_11);
        $this->assertSame('E1234567', $row->dpersonnel_28);
        $this->assertSame('A-17', $row->dpersonnel_21);
        $this->assertSame('2026-10-13 12:00:00', $row->sso_updated_at);
        $this->assertTrue((bool) $row->is_active);
        $this->assertFalse((bool) $row->sso_link_pending);

        $license = DB::table('dem_personnel_certifications')->where('dem_personnel_id', $row->id)->sole();
        $this->assertSame(['36', '123456', '9925007', 61], [$license->dpersonnel_22, $license->dpersonnel_23, $license->dpersonnel_24, (int) $license->sso_license_id]);
        $this->assertSame('9925007', DB::table('dem_personnel_cert_levels')->where('dem_personnel_id', $row->id)->value('dpersonnel_38'));
        $this->assertSame('+15185550199', DB::table('dem_personnel_phones')->where('dem_personnel_id', $row->id)->value('phone_number'));
        $this->assertSame('jordan.home@example.com', DB::table('dem_personnel_emails')->where('dem_personnel_id', $row->id)->value('email_address'));
        $this->assertSame('2024', DB::table('dem_personnel_immunizations')->where('dem_personnel_id', $row->id)->value('dpersonnel_19'));

        $this->deliver($this->person(70, 9001))->assertOk()->assertJson(['result' => 'unchanged']);
    }

    public function test_children_are_updated_in_place_by_sso_child_id(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        $this->deliver($this->person(70, 9001))->assertOk();
        $row = $this->demRow($companyId, 9001);
        $licenseId = (int) DB::table('dem_personnel_certifications')->where('sso_license_id', 61)->value('id');

        $this->deliver($this->person(70, 9001, [
            'licenses' => [
                ['id' => 61, 'state' => '36', 'number' => '123456', 'level' => '9925005', 'current_since' => '2026-01-01', 'first_issued_on' => '2015-06-01', 'expires_on' => '2029-01-01'],
                ['id' => 62, 'state' => '50', 'number' => 'VT-88', 'level' => '9925007', 'current_since' => null, 'first_issued_on' => null, 'expires_on' => '2027-06-30'],
            ],
            'phones' => [],
            'updated_at' => '2026-10-13T12:05:00+00:00',
        ]))->assertOk()->assertJson(['result' => 'updated']);

        $licenses = DB::table('dem_personnel_certifications')->where('dem_personnel_id', $row->id)->orderBy('id')->get();
        $this->assertCount(2, $licenses);
        $this->assertSame($licenseId, (int) $licenses[0]->id, 'An edited licence keeps its row id');
        $this->assertSame('9925005', $licenses[0]->dpersonnel_24);
        $this->assertSame('2029-01-01', $licenses[0]->dpersonnel_27);
        $this->assertSame('VT-88', $licenses[1]->dpersonnel_23);
        $this->assertSame(0, DB::table('dem_personnel_phones')->where('dem_personnel_id', $row->id)->count(), 'A child SSO removed is removed');
        $this->assertSame((int) $row->id, (int) $this->demRow($companyId, 9001)->id);
    }

    public function test_an_older_delivery_is_acked_stale_and_changes_nothing(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        $this->deliver($this->person(70, 9001, ['updated_at' => '2026-10-13T12:10:00+00:00']))->assertOk();

        $this->deliver($this->person(70, 9001, ['user' => ['last_name' => 'Older'], 'updated_at' => '2026-10-13T12:00:00+00:00']))
            ->assertOk()
            ->assertJson(['status' => 'stale']);

        $this->assertSame('Reyes', $this->demRow($companyId, 9001)->dpersonnel_01);
    }

    public function test_a_person_not_provisioned_here_yet_is_skipped(): void
    {
        $this->company(70);

        $this->deliver($this->person(70, 9001))
            ->assertOk()
            ->assertJson(['status' => 'skipped', 'reason' => 'user_not_found']);

        $this->assertSame(0, DB::table('dem_personnel')->count());
    }

    public function test_an_unknown_company_is_skipped(): void
    {
        $this->user(9001);

        $this->deliver($this->person(71, 9001))->assertOk()->assertJson(['status' => 'skipped', 'reason' => 'company_not_found']);
    }

    public function test_person_deactivated_turns_the_row_off_and_keeps_it(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        $this->deliver($this->person(70, 9001))->assertOk();
        $rowId = (int) $this->demRow($companyId, 9001)->id;

        $this->postWebhook('person.deactivated', [
            'company' => ['id' => 70],
            'user' => ['id' => 9001],
            'updated_at' => '2026-10-13T11:00:00+00:00',
        ])->assertOk()->assertJson(['status' => 'stale']);
        $this->assertTrue((bool) $this->demRow($companyId, 9001)->is_active);

        $this->postWebhook('person.deactivated', [
            'company' => ['id' => 70],
            'user' => ['id' => 9001],
            'updated_at' => '2026-10-13T13:00:00+00:00',
        ])->assertOk()->assertJson(['status' => 'ok', 'result' => 'deactivated']);

        $row = $this->demRow($companyId, 9001);
        $this->assertSame($rowId, (int) $row->id);
        $this->assertFalse((bool) $row->is_active);
        $this->assertSame('Reyes', $row->dpersonnel_01);
        $this->assertSame(1, DB::table('dem_personnel_certifications')->where('dem_personnel_id', $rowId)->count());

        $this->deliver($this->person(70, 9001, ['updated_at' => '2026-10-13T14:00:00+00:00']))->assertOk()->assertJson(['result' => 'updated']);
        $this->assertTrue((bool) $this->demRow($companyId, 9001)->is_active, 'Rejoining turns the same row back on');
    }

    public function test_a_snapshot_with_an_inactive_membership_turns_the_row_off(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        $this->deliver($this->person(70, 9001))->assertOk();

        $this->deliver($this->person(70, 9001, ['membership' => ['is_active' => false], 'updated_at' => '2026-10-13T12:30:00+00:00']))->assertOk();

        $this->assertFalse((bool) $this->demRow($companyId, 9001)->is_active);
    }

    public function test_a_delivery_adopts_the_users_existing_row_without_touching_it(): void
    {
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $localId = (int) DB::table('dem_personnel')->insertGetId([
            'company_id' => $companyId, 'user_id' => $userId, 'dpersonnel_01' => 'Reyes-Local', 'dpersonnel_02' => 'J', 'dpersonnel_21' => 'OLD-1',
        ]);
        $localLicense = (int) DB::table('dem_personnel_certifications')->insertGetId(['dem_personnel_id' => $localId, 'dpersonnel_22' => '36', 'dpersonnel_23' => 'LOCAL']);

        $this->deliver($this->person(70, 9001))->assertOk()->assertJson(['result' => 'linked']);

        $row = DB::table('dem_personnel')->find($localId);
        $this->assertSame(9001, (int) $row->sso_user_id);
        $this->assertTrue((bool) $row->sso_link_pending);
        $this->assertSame('Reyes-Local', $row->dpersonnel_01, 'Adoption by a delivery never overwrites');
        $this->assertSame('OLD-1', $row->dpersonnel_21);
        $this->assertSame('LOCAL', DB::table('dem_personnel_certifications')->find($localLicense)->dpersonnel_23);
        $this->assertSame(1, DB::table('dem_personnel')->count());

        $this->deliver($this->person(70, 9001, ['updated_at' => '2026-10-13T13:00:00+00:00']))->assertOk()->assertJson(['result' => 'pending']);
        $this->postWebhook('person.deactivated', ['company' => ['id' => 70], 'user' => ['id' => 9001], 'updated_at' => '2026-10-13T14:00:00+00:00'])
            ->assertOk()->assertJson(['result' => 'pending']);
        $this->assertTrue((bool) DB::table('dem_personnel')->find($localId)->is_active);
    }

    public function test_a_delivery_adopts_by_email_when_the_row_has_no_user(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        $localId = (int) DB::table('dem_personnel')->insertGetId(['company_id' => $companyId, 'dpersonnel_10' => 'USER9001@example.com', 'dpersonnel_01' => 'Reyes']);

        $this->deliver($this->person(70, 9001))->assertOk()->assertJson(['result' => 'linked']);

        $this->assertSame(9001, (int) DB::table('dem_personnel')->find($localId)->sso_user_id);
        $this->assertSame(1, DB::table('dem_personnel')->count());
    }

    public function test_two_rows_with_the_same_email_are_never_guessed_between(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        DB::table('dem_personnel')->insert([
            ['company_id' => $companyId, 'dpersonnel_10' => 'user9001@example.com', 'dpersonnel_01' => 'Reyes'],
            ['company_id' => $companyId, 'dpersonnel_10' => 'user9001@example.com', 'dpersonnel_01' => 'Reyes'],
        ]);

        $this->deliver($this->person(70, 9001))->assertOk()->assertJson(['result' => 'ambiguous']);

        $this->assertSame(2, DB::table('dem_personnel')->count());
        $this->assertSame(0, DB::table('dem_personnel')->whereNotNull('sso_user_id')->count());
    }

    public function test_a_person_is_never_adopted_by_name(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        $stubId = (int) DB::table('dem_personnel')->insertGetId(['company_id' => $companyId, 'dpersonnel_01' => 'Reyes', 'dpersonnel_02' => 'Jordan']);

        $this->deliver($this->person(70, 9001))->assertOk()->assertJson(['result' => 'created']);

        $this->assertNull(DB::table('dem_personnel')->find($stubId)->sso_user_id);
        $this->assertSame(2, DB::table('dem_personnel')->count());
    }

    public function test_this_apps_kept_value_fills_ssos_empty_one_and_never_another_apps(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);

        $this->deliver($this->person(70, 9001, [
            'profile' => ['date_of_birth' => null, 'address' => ['zip' => null]],
            'membership' => ['agency_personnel_id' => 'SSO-1'],
            'pending' => [
                'profile.date_of_birth' => ['cloudpcr' => '1988-02-30', 'hr' => '1970-01-01'],
                'profile.address.zip' => ['hr' => '99999'],
                'membership.agency_personnel_id' => ['cloudpcr' => 'X'],
                'membership.is_active' => ['cloudpcr' => false],
            ],
        ]))->assertOk();

        $row = $this->demRow($companyId, 9001);
        $this->assertSame('1988-02-30', $row->dpersonnel_11, 'The value SSO refused stays as this app sent it');
        $this->assertNull($row->dpersonnel_07, "Another app's kept value is never applied");
        $this->assertSame('SSO-1', $row->dpersonnel_21, "SSO's own value wins once it has one");
        $this->assertTrue((bool) $row->is_active);
    }
}

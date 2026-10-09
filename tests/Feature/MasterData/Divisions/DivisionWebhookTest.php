<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Divisions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DivisionWebhookTest extends DivisionsTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    public function test_created_and_updated_upsert_the_full_row(): void
    {
        $this->postWebhook('division.created', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'North', ['code' => 'N', 'sort_order' => 3])])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = $this->mirrored($this->companyId, 501);
        $this->assertSame('North', $row->name);
        $this->assertSame('N', $row->code);
        $this->assertSame(3, (int) $row->sort_order);
        $this->assertSame('2026-10-09 12:00:00', $row->sso_updated_at);

        $this->postWebhook('division.updated', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'North District', ['code' => 'N', 'sort_order' => 3, 'updated_at' => '2026-10-09T13:00:00+00:00'])])
            ->assertJson(['result' => 'updated']);

        $this->assertSame('North District', $this->mirrored($this->companyId, 501)->name);
        $this->assertSame(1, DB::table('divisions')->count());
    }

    public function test_an_older_delivery_is_ignored_as_stale(): void
    {
        $this->postWebhook('division.updated', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'New name', ['updated_at' => '2026-10-09T13:00:00+00:00'])]);

        $this->postWebhook('division.updated', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'Old name', ['updated_at' => '2026-10-09T12:00:00+00:00'])])
            ->assertJson(['status' => 'stale']);

        $this->assertSame('New name', $this->mirrored($this->companyId, 501)->name);
    }

    public function test_deactivated_turns_the_row_off_and_never_deletes_it_or_its_children(): void
    {
        $local = $this->localDivision($this->companyId, 'North', 501);
        DB::table('locations')->insert(['division_id' => $local, 'name' => 'Station 1']);
        $userId = $this->user(9001, $this->companyId, $local);

        $this->postWebhook('division.deactivated', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'North', ['is_active' => false])])
            ->assertJson(['result' => 'updated']);

        $this->assertFalse((bool) DB::table('divisions')->find($local)->is_active);
        $this->assertSame(1, DB::table('locations')->count());
        $this->assertSame($local, $this->divisionOf($userId, $this->companyId), 'the person stays recorded in it');
    }

    public function test_a_delivery_adopts_a_same_name_local_row_as_pending_without_touching_it(): void
    {
        $local = $this->localDivision($this->companyId, 'Alpha Shift');

        $this->postWebhook('division.created', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'ALPHA SHIFT', ['code' => 'A'])])
            ->assertJson(['result' => 'linked']);

        $row = DB::table('divisions')->find($local);
        $this->assertSame(501, (int) $row->sso_division_id);
        $this->assertTrue((bool) $row->sso_link_pending);
        $this->assertSame('Alpha Shift', $row->name);
        $this->assertNull($row->code);

        $this->postWebhook('division.deactivated', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'Alpha Shift', ['is_active' => false])])
            ->assertJson(['result' => 'pending']);
        $this->assertTrue((bool) DB::table('divisions')->find($local)->is_active);
    }

    public function test_disabled_entity_unknown_company_and_unknown_user_are_acknowledged(): void
    {
        $this->postWebhook('division.created', ['company' => ['id' => 99], 'division' => $this->divisionRecord(501, 'North')])
            ->assertOk()->assertJson(['status' => 'skipped', 'reason' => 'company_not_found']);

        $this->postWebhook('user.division_changed', ['company' => ['id' => 70], 'user' => ['id' => 404], 'division_id' => 501])
            ->assertOk()->assertJson(['status' => 'skipped', 'reason' => 'user_not_found']);

        config(['sso.master_data.divisions' => false]);
        $this->postWebhook('division.created', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'North')])
            ->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(0, DB::table('divisions')->count());
    }

    public function test_user_division_changed_sets_and_clears_the_persons_division(): void
    {
        $north = $this->localDivision($this->companyId, 'North', 501);
        $userId = $this->user(9001, $this->companyId);

        $this->postWebhook('user.division_changed', ['company' => ['id' => 70], 'user' => ['id' => 9001], 'division_id' => 501])
            ->assertJson(['status' => 'ok', 'result' => 'set']);
        $this->assertSame($north, $this->divisionOf($userId, $this->companyId));

        $this->postWebhook('user.division_changed', ['company' => ['id' => 70], 'user' => ['id' => 9001], 'division_id' => null])
            ->assertJson(['result' => 'cleared']);
        $this->assertNull($this->divisionOf($userId, $this->companyId));
    }

    public function test_an_assignment_that_arrives_before_its_division_pulls_the_catalog_once(): void
    {
        $userId = $this->user(9001, $this->companyId);
        Http::fake(['sso.test/api/internal/companies/70/divisions' => Http::response($this->snapshot(70, [$this->divisionRecord(502, 'South')]))]);

        $this->postWebhook('user.division_changed', ['company' => ['id' => 70], 'user' => ['id' => 9001], 'division_id' => 502])
            ->assertJson(['result' => 'set']);

        $this->assertSame((int) $this->mirrored($this->companyId, 502)->id, $this->divisionOf($userId, $this->companyId));
        Http::assertSentCount(1);
    }

    public function test_an_unconfirmed_local_division_is_never_replaced_or_cleared(): void
    {
        $unlinked = $this->localDivision($this->companyId, 'Bravo Shift');
        $pending = $this->localDivision($this->companyId, 'Charlie Shift', 503, pending: true);
        $this->localDivision($this->companyId, 'North', 501);
        $bob = $this->user(9002, $this->companyId, $unlinked);
        $cara = $this->user(9003, $this->companyId, $pending);

        $this->postWebhook('user.division_changed', ['company' => ['id' => 70], 'user' => ['id' => 9002], 'division_id' => 501])
            ->assertJson(['result' => 'kept_unconfirmed']);
        $this->postWebhook('user.division_changed', ['company' => ['id' => 70], 'user' => ['id' => 9003], 'division_id' => null])
            ->assertJson(['result' => 'kept_unconfirmed']);

        $this->assertSame($unlinked, $this->divisionOf($bob, $this->companyId));
        $this->assertSame($pending, $this->divisionOf($cara, $this->companyId));
    }

    public function test_a_person_who_is_not_a_member_here_is_left_alone(): void
    {
        $this->localDivision($this->companyId, 'North', 501);
        $this->user(9001);

        $this->postWebhook('user.division_changed', ['company' => ['id' => 70], 'user' => ['id' => 9001], 'division_id' => 501])
            ->assertJson(['result' => 'not_member']);
    }

    public function test_writes_stay_inside_the_resolved_company(): void
    {
        $otherCompany = $this->company(80);
        $theirs = $this->localDivision($otherCompany, 'North', 501);
        $userId = $this->user(9001, $this->companyId);
        $this->member($userId, $otherCompany, $theirs);

        $this->postWebhook('division.updated', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'Renamed')])
            ->assertJson(['result' => 'created']);
        $this->postWebhook('user.division_changed', ['company' => ['id' => 70], 'user' => ['id' => 9001], 'division_id' => null])
            ->assertJson(['result' => 'unchanged']);

        $this->assertSame('North', DB::table('divisions')->find($theirs)->name);
        $this->assertSame($theirs, $this->divisionOf($userId, $otherCompany));
    }
}

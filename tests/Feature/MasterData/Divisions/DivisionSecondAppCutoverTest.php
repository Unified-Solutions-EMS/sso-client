<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Divisions;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;

/**
 * Crew-Scheduling cut over first, so SSO already has "Alpha Shift" (501) with
 * only Crew's people in it (Alice, 9001). HR cuts over second (built on HR's
 * shape: the migration creates its table and pivot column; HR then seeds the
 * table from its slot names). HR's list overlaps Crew's ("alpha shift") and
 * adds "North District"; Bob (9002) is in HR's North, Cara (9003) in HR's
 * alpha. HR must end with no duplicate rows and no one losing a division.
 */
class DivisionSecondAppCutoverTest extends DivisionsTestCase
{
    protected bool $crewShape = false;

    private int $companyId;

    private int $alice;

    private int $bob;

    private int $cara;

    private int $alpha;

    private int $north;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->alpha = $this->localDivision($this->companyId, 'alpha shift');
        $this->north = $this->localDivision($this->companyId, 'North District');
        $this->alice = $this->user(9001, $this->companyId);
        $this->bob = $this->user(9002, $this->companyId, $this->north);
        $this->cara = $this->user(9003, $this->companyId, $this->alpha);
    }

    /**
     * What SSO holds after Crew's push and HR's push (SSO matched HR's
     * "alpha shift" to Crew's 501, created North as 502, and filled Bob and
     * Cara; Alice was already in 501 from Crew).
     */
    private function ssoAfterBothPushes(): array
    {
        return $this->snapshot(70, [
            $this->divisionRecord(501, 'Alpha Shift', ['updated_at' => '2026-10-09T10:00:00+00:00']),
            $this->divisionRecord(502, 'North District', ['updated_at' => '2026-10-09T12:00:00+00:00']),
        ], [
            ['user_id' => 9001, 'division_id' => 501],
            ['user_id' => 9002, 'division_id' => 502],
            ['user_id' => 9003, 'division_id' => 501],
        ]);
    }

    public function test_documented_order_push_before_enable_keeps_everyone_and_duplicates_nothing(): void
    {
        config(['sso.master_data.divisions' => false]);

        // Crew-era deliveries while HR is still disabled are acknowledged and ignored.
        $this->postWebhook('division.created', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'Alpha Shift')])
            ->assertJson(['status' => 'ignored']);

        Http::fake([
            'sso.test/api/internal/companies/70/divisions/import' => Http::response([
                'created' => 1, 'matched' => 1, 'assignments_added' => 2, 'conflicts' => [], 'assignment_conflicts' => [], 'unknown_users' => [], 'invalid' => [],
                'mapping' => [
                    (string) $this->alpha => ['sso_id' => 501, 'updated_at' => '2026-10-09T10:00:00+00:00'],
                    (string) $this->north => ['sso_id' => 502, 'updated_at' => '2026-10-09T12:00:00+00:00'],
                ],
            ]),
            'sso.test/api/internal/companies/70/divisions' => Http::response($this->ssoAfterBothPushes()),
        ]);

        $this->artisan('sso:push-master-data', ['entity' => 'divisions'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/import')
            && $request['app_slug'] === 'hr'
            && in_array(['user_sso_id' => '9002', 'local_division_id' => $this->north], $request['assignments'], true));

        config(['sso.master_data.divisions' => true]);
        $this->artisan('sso:resync-master-data', ['entity' => 'divisions'])->assertSuccessful();

        $this->assertSame(2, DB::table('divisions')->where('company_id', $this->companyId)->count());
        $this->assertSame('Alpha Shift', DB::table('divisions')->find($this->alpha)->name, 'the resync takes SSO\'s spelling');
        $this->assertSame($this->alpha, $this->divisionOf($this->alice, $this->companyId));
        $this->assertSame($this->north, $this->divisionOf($this->bob, $this->companyId));
        $this->assertSame($this->alpha, $this->divisionOf($this->cara, $this->companyId));
    }

    public function test_enabling_before_the_push_links_by_name_without_losing_anyones_division(): void
    {
        Http::fake(['sso.test/*' => Http::response($this->snapshot(70, [$this->divisionRecord(501, 'Alpha Shift')]))]);

        // SSO (Crew's list) announces Alpha Shift: adopted as pending, nothing else changes.
        $this->postWebhook('division.created', ['company' => ['id' => 70], 'division' => $this->divisionRecord(501, 'Alpha Shift', ['code' => 'A'])])
            ->assertJson(['result' => 'linked']);
        $row = DB::table('divisions')->find($this->alpha);
        $this->assertSame('alpha shift', $row->name);
        $this->assertTrue((bool) $row->sso_link_pending);

        // SSO has Cara in no division yet (only Crew's people exist there).
        $this->postWebhook('user.division_changed', ['company' => ['id' => 70], 'user' => ['id' => 9003], 'division_id' => null])
            ->assertJson(['result' => 'kept_unconfirmed']);

        // Bob logs in: SSO has no division for him either.
        (new StubSynchronizer)->synchronize([
            'user' => ['id' => 9002, 'email' => 'user9002@example.com'],
            'companies' => [['id' => 70, 'name' => 'Agency 70', 'division' => null]],
            'selectedCompany' => ['id' => 70],
        ]);

        // Alice is put in Alpha by SSO: adding to a pending row is fine.
        $this->postWebhook('user.division_changed', ['company' => ['id' => 70], 'user' => ['id' => 9001], 'division_id' => 501])
            ->assertJson(['result' => 'set']);

        $this->assertSame($this->north, $this->divisionOf($this->bob, $this->companyId));
        $this->assertSame($this->alpha, $this->divisionOf($this->cara, $this->companyId));
        $this->assertSame($this->alpha, $this->divisionOf($this->alice, $this->companyId));
        $this->assertSame(2, DB::table('divisions')->count());
        Http::assertNothingSent();
    }
}

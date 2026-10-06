<?php

namespace Unified\SsoClient\Tests\Feature\MasterData;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ResyncMasterDataCommandTest extends MasterDataTestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $snapshots  SSO company id => response body
     */
    private function fakeSso(array $snapshots): void
    {
        $fakes = [];
        foreach ($snapshots as $ssoCompanyId => $body) {
            $fakes["sso.test/api/internal/companies/{$ssoCompanyId}/qualifications"] = Http::response($body);
        }

        Http::fake($fakes);
    }

    /**
     * @param  list<array<string, mixed>>  $qualifications
     * @param  list<array{user_id: int, qualification_ids: list<int>}>  $assignments
     * @return array<string, mixed>
     */
    private function snapshot(int $ssoCompanyId, array $qualifications, array $assignments = []): array
    {
        return ['company' => ['id' => $ssoCompanyId], 'qualifications' => $qualifications, 'assignments' => $assignments];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function state(): array
    {
        return [
            DB::table('qualifications')->orderBy('id')->get(['id', 'company_id', 'sso_qualification_id', 'name', 'description', 'applies_to', 'is_active'])->map(fn ($row): array => (array) $row)->all(),
            DB::table('company_user_qualifications')->orderBy('id')->get(['user_id', 'company_id', 'qualification_id'])->map(fn ($row): array => (array) $row)->all(),
        ];
    }

    public function test_resync_mirrors_catalog_and_assignments_and_is_idempotent(): void
    {
        $companyId = $this->company(70);
        $alice = $this->user(9001);
        $bob = $this->user(9002);

        $this->fakeSso([70 => $this->snapshot(70, [
            $this->qualificationRecord(501, 'Paramedic', ['applies_to' => ['cloudpcr']]),
            $this->qualificationRecord(502, 'Driver'),
        ], [
            ['user_id' => 9001, 'qualification_ids' => [501, 502]],
            ['user_id' => 9002, 'qualification_ids' => [502]],
            ['user_id' => 9999, 'qualification_ids' => [501]],
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('created=2 updated=0 linked=0 unchanged=0 deactivated=0 assignments_added=3 assignments_removed=0 unknown_users=1')
            ->assertSuccessful();

        $medic = (int) $this->mirrored($companyId, 501)->id;
        $driver = (int) $this->mirrored($companyId, 502)->id;
        $this->assertSame([$medic, $driver], $this->assignedIds($alice, $companyId));
        $this->assertSame([$driver], $this->assignedIds($bob, $companyId));

        $first = $this->state();

        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=2 deactivated=0 assignments_added=0 assignments_removed=0')
            ->assertSuccessful();

        $this->assertSame($first, $this->state());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer core-key'));
    }

    public function test_rows_that_vanished_from_sso_are_deactivated_not_deleted(): void
    {
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $gone = $this->localQualification($companyId, 'Retired Cert', 499);
        $this->assign($userId, $companyId, $gone);

        $this->fakeSso([70 => $this->snapshot(70, [$this->qualificationRecord(501, 'Paramedic')])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('deactivated=1')
            ->assertSuccessful();

        $row = $this->mirrored($companyId, 499);
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->is_active);
        $this->assertSame([], $this->assignedIds($userId, $companyId), 'a user absent from the snapshot holds nothing from SSO');
    }

    public function test_resync_leaves_unlinked_local_rows_and_their_assignments_alone(): void
    {
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $legacy = $this->localQualification($companyId, 'Hazmat Tech');
        $this->assign($userId, $companyId, $legacy);

        $this->fakeSso([70 => $this->snapshot(70, [$this->qualificationRecord(501, 'Paramedic')])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications'])->assertSuccessful();

        $this->assertNull(DB::table('qualifications')->where('id', $legacy)->value('sso_qualification_id'));
        $this->assertTrue((bool) DB::table('qualifications')->where('id', $legacy)->value('is_active'));
        $this->assertSame([$legacy], $this->assignedIds($userId, $companyId));
    }

    public function test_company_option_only_reconciles_that_company(): void
    {
        $companyA = $this->company(70, 'Alpha');
        $companyB = $this->company(80, 'Bravo');
        $userId = $this->user(9001);
        $bRow = $this->localQualification($companyB, 'Paramedic', 777);
        $this->assign($userId, $companyB, $bRow);

        $this->fakeSso([
            70 => $this->snapshot(70, [$this->qualificationRecord(501, 'Paramedic')], [['user_id' => 9001, 'qualification_ids' => [501]]]),
            80 => $this->snapshot(80, []),
        ]);

        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications', '--company' => '70'])->assertSuccessful();

        $this->assertNotNull($this->mirrored($companyA, 501));
        $this->assertNull($this->mirrored($companyB, 501));
        $this->assertTrue((bool) $this->mirrored($companyB, 777)->is_active);
        $this->assertSame([$bRow], $this->assignedIds($userId, $companyB));
        Http::assertSentCount(1);
    }

    public function test_link_by_name_links_matches_and_reports_the_rest_without_deleting(): void
    {
        $companyId = $this->company(70);
        $medic = $this->localQualification($companyId, 'paramedic');
        $hazmat = $this->localQualification($companyId, 'Hazmat Tech');
        $driverOne = $this->localQualification($companyId, 'Driver');
        $driverTwo = $this->localQualification($companyId, 'DRIVER ');
        $otherCompany = $this->company(80, 'Bravo');
        $otherMedic = $this->localQualification($otherCompany, 'Paramedic');

        $this->fakeSso([70 => $this->snapshot(70, [
            $this->qualificationRecord(501, 'Paramedic'),
            $this->qualificationRecord(502, 'Driver'),
            $this->qualificationRecord(503, 'Critical Care'),
        ])]);

        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications', '--company' => '70', '--link-by-name' => true])
            ->expectsOutputToContain('linked 1, ambiguous 1, local only 1, SSO only 1')
            ->expectsOutputToContain('Hazmat Tech')
            ->expectsOutputToContain('Critical Care')
            ->assertSuccessful();

        $this->assertSame(501, (int) DB::table('qualifications')->where('id', $medic)->value('sso_qualification_id'));
        foreach ([$hazmat, $driverOne, $driverTwo, $otherMedic] as $id) {
            $this->assertNull(DB::table('qualifications')->where('id', $id)->value('sso_qualification_id'));
        }
        $this->assertSame(5, DB::table('qualifications')->count(), 'link-by-name never creates or deletes rows');
        $this->assertSame('paramedic', DB::table('qualifications')->where('id', $medic)->value('name'));

        // Linking is idempotent: a second pass finds nothing new to link.
        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications', '--company' => '70', '--link-by-name' => true])
            ->expectsOutputToContain('linked 0, ambiguous 1, local only 1, SSO only 1')
            ->assertSuccessful();
    }

    public function test_disabled_entity_refuses_to_run(): void
    {
        config(['sso.master_data.qualifications' => false]);
        Http::fake();

        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('disabled')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_unknown_entity_refuses_to_run(): void
    {
        $this->artisan('sso:resync-master-data', ['entity' => 'vehicles'])->assertFailed();
    }

    public function test_an_sso_error_fails_the_company_and_writes_nothing(): void
    {
        $this->company(70);
        Http::fake(['sso.test/*' => Http::response(['message' => 'nope'], 500)]);

        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('HTTP 500')
            ->assertFailed();

        $this->assertSame(0, DB::table('qualifications')->count());
    }

    public function test_unknown_company_option_fails(): void
    {
        Http::fake();

        $this->artisan('sso:resync-master-data', ['entity' => 'qualifications', '--company' => '12345'])
            ->assertFailed();

        Http::assertNothingSent();
    }
}

<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Personnel\PersonnelMirror;
use Unified\SsoClient\MasterData\Personnel\PersonnelProjection;
use Unified\SsoClient\MasterData\Support\ChildRemoval;
use Unified\SsoClient\MasterData\Support\ChildRowPolicy;
use Unified\SsoClient\Tests\Stubs\HrPersonnelProjection;
use Unified\SsoClient\Tests\Stubs\MappedPersonnelChildTable;

/**
 * A licence SSO removes from a person must not take HR's row with it: the
 * row carries HR's approval history and card images. HR declares `unlink`,
 * so the row stays, loses its SSO link, is stamped `sso_removed_at` and is
 * left out of the push (an unapproved licence HR still shows as pending is
 * not sent back up as new, removed again, sent again).
 */
class PersonnelChildRemovalTest extends PersonnelTestCase
{
    protected string $shape = 'hr';

    private int $companyId;

    private int $profileId;

    private int $licenceId;

    private int $driversLicenceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->user(9001);
        $this->deliver($this->person(70, 9001))->assertOk()->assertJson(['result' => 'created']);

        $this->profileId = (int) DB::table('personnel_profiles')->where('company_id', $this->companyId)->value('id');
        $this->licenceId = (int) DB::table('personnel_licenses')->where('sso_license_id', 61)->value('id');
        DB::table('personnel_licenses')->where('id', $this->licenceId)->update(['status' => 'approved', 'approved_by' => 5, 'card_front_path' => 'cards/61-front.jpg']);
        $this->driversLicenceId = (int) DB::table('personnel_licenses')->insertGetId([
            'personnel_profile_id' => $this->profileId, 'license_type' => 'DL', 'number' => 'D123', 'state' => 'NY',
        ]);
    }

    private function withoutLicence(string $updatedAt = '2026-10-13T13:00:00+00:00'): array
    {
        return $this->person(70, 9001, ['licenses' => [], 'updated_at' => $updatedAt]);
    }

    public function test_a_licence_sso_removed_is_unlinked_and_keeps_hrs_history(): void
    {
        $this->deliver($this->withoutLicence())->assertOk()->assertJson(['result' => 'updated']);

        $row = DB::table('personnel_licenses')->find($this->licenceId);
        $this->assertNotNull($row, 'the row HR approved is never deleted');
        $this->assertNull($row->sso_license_id);
        $this->assertNotNull($row->sso_removed_at);
        $this->assertSame(['approved', 5, 'cards/61-front.jpg', '123456'], [$row->status, (int) $row->approved_by, $row->card_front_path, $row->number]);
        $this->assertNotNull(DB::table('personnel_licenses')->find($this->driversLicenceId), "HR's driver's licence is untouched");
    }

    public function test_repeating_the_removal_is_idempotent(): void
    {
        $this->deliver($this->withoutLicence())->assertOk();
        $stamped = DB::table('personnel_licenses')->find($this->licenceId);

        $this->travel(5)->minutes();
        $this->deliver($this->withoutLicence())->assertOk()->assertJson(['result' => 'unchanged']);

        $this->assertEquals($stamped, DB::table('personnel_licenses')->find($this->licenceId));
    }

    public function test_the_resync_keeps_the_removed_row_and_does_not_count_it_as_unknown(): void
    {
        $this->deliver($this->withoutLicence())->assertOk();
        Http::fake(fn () => Http::response(['company' => ['id' => 70], 'data' => [$this->withoutLicence()], 'next_cursor' => null]));

        foreach ([1, 2] as $run) {
            $this->assertSame(0, Artisan::call('sso:resync-master-data', ['entity' => 'personnel']));
            $output = Artisan::output();
            $this->assertStringContainsString('unchanged=1', $output);
            $this->assertStringContainsString('unlinked_children=1', $output, "only HR's driver's licence counts as a row SSO never knew");
        }

        $this->assertSame(2, DB::table('personnel_licenses')->where('personnel_profile_id', $this->profileId)->count());
        $this->assertSame('approved', DB::table('personnel_licenses')->find($this->licenceId)->status);
    }

    public function test_a_removed_licence_still_pending_in_hr_is_not_pushed_again(): void
    {
        $this->deliver($this->withoutLicence())->assertOk();
        DB::table('personnel_licenses')->where('id', $this->licenceId)->update(['status' => 'pending']);
        $newLicence = (int) DB::table('personnel_licenses')->insertGetId([
            'personnel_profile_id' => $this->profileId, 'license_type' => 'EMS', 'number' => '777', 'state' => '50', 'level' => '9925005',
        ]);
        config(['sso.master_data.personnel' => false]);
        Http::fake(['sso.test/api/internal/companies/70/personnel/import' => Http::response(['matched' => 1, 'mapping' => []])]);

        $this->artisan('sso:push-master-data', ['entity' => 'personnel', '--company' => '70'])->assertSuccessful();

        Http::assertSent(function (Request $request) use ($newLicence): bool {
            $licences = $request['personnel'][0]['licenses'] ?? [];

            return array_column($licences, 'local_id') === [$newLicence];
        });
    }

    public function test_sso_listing_the_licence_again_adopts_the_kept_row(): void
    {
        $this->deliver($this->withoutLicence())->assertOk();

        $this->deliver($this->person(70, 9001, ['updated_at' => '2026-10-13T14:00:00+00:00', 'licenses' => [
            ['id' => 62, 'state' => '36', 'number' => '123456', 'level' => '9925007', 'current_since' => '2024-01-01', 'first_issued_on' => '2015-06-01', 'expires_on' => '2028-01-01'],
        ]]))->assertOk()->assertJson(['result' => 'updated']);

        $row = DB::table('personnel_licenses')->find($this->licenceId);
        $this->assertSame([62, null, 'approved', '2028-01-01'], [(int) $row->sso_license_id, $row->sso_removed_at, $row->status, $row->expiration_date]);
        $this->assertSame(2, DB::table('personnel_licenses')->where('personnel_profile_id', $this->profileId)->count(), 'adopted, not duplicated');
    }

    public function test_a_deactivate_policy_switches_the_row_off_and_back_on(): void
    {
        $this->app->bind(PersonnelProjection::class, fn () => new class extends HrPersonnelProjection
        {
            public function childTables(): array
            {
                return ['licenses' => new MappedPersonnelChildTable('personnel_licenses', 'personnel_profile_id', [
                    'state' => 'state', 'number' => 'number', 'expires_on' => 'expiration_date', 'level' => 'level',
                ], ['license_type', 'state'], ['license_type' => 'EMS'], ChildRowPolicy::deactivate('status', 'removed', 'pending'))] + parent::childTables();
            }
        });

        $this->deliver($this->withoutLicence())->assertOk()->assertJson(['result' => 'updated']);
        $row = DB::table('personnel_licenses')->find($this->licenceId);
        $this->assertSame([61, 'removed', 'cards/61-front.jpg'], [(int) $row->sso_license_id, $row->status, $row->card_front_path]);
        $this->assertNotNull($row->sso_removed_at);

        $mirror = app(MasterDataRegistry::class)->mirror('personnel');
        assert($mirror instanceof PersonnelMirror);
        $this->assertArrayNotHasKey('licenses', $mirror->buildImportPayload($this->companyId)['payload']['personnel'][0], 'a deactivated licence is not pushed');

        $this->deliver($this->person(70, 9001, ['updated_at' => '2026-10-13T14:00:00+00:00']))->assertOk()->assertJson(['result' => 'updated']);
        $row = DB::table('personnel_licenses')->find($this->licenceId);
        $this->assertSame(['pending', null], [$row->status, $row->sso_removed_at]);
    }

    public function test_a_child_table_that_declares_nothing_is_unlinked(): void
    {
        $this->assertSame(ChildRemoval::Unlink, ChildRowPolicy::for(new \stdClass)->removal);
    }
}

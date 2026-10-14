<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\MasterData\Personnel\PersonnelProjection;
use Unified\SsoClient\Tests\Stubs\DemPersonnelProjection;
use Unified\SsoClient\Tests\Stubs\HrPersonnelProjection;

/**
 * Design §3d cutover order: CloudPCR pushes first (fills SSO), HR pushes
 * second with an overlapping roster (fills only what SSO lacks; a differing
 * value is a conflict SSO wins; a value SSO refuses is kept as HR's pending
 * value). Then both turn the mirror on and resync.
 *
 * Both apps must end linked to the same SSO people, with their own row and
 * child row ids unchanged (PCR crew rows, NEMSIS group ids), HR's own
 * fields (rank, notes) and driver's licences untouched, HR's refused ZIP
 * still there, nobody SSO could not match touched, nothing duplicated or
 * deleted.
 *
 * Both apps' tables share one test database; the bound projection and app
 * slug are switched to act as one app or the other.
 */
class PersonnelSecondAppCutoverTest extends PersonnelTestCase
{
    protected string $shape = 'cloudpcr';

    protected bool $runMirrorMigration = false;

    /** @var list<array<string, mixed>> */
    private array $imports = [];

    /** @var array<string, array<string, mixed>> */
    private array $importResponses = [];

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createHrTables();

        $this->actAs('cloudpcr');
        $this->runPublishedMirrorMigration();
        $this->actAs('hr');
        $this->runPublishedMirrorMigration();
        $this->actAs('cloudpcr');
    }

    private function actAs(string $app): void
    {
        $this->app->bind(PersonnelProjection::class, $app === 'hr' ? HrPersonnelProjection::class : DemPersonnelProjection::class);
        config(['sso.app_slug' => $app]);
    }

    public function test_cloudpcr_then_hr_end_linked_to_the_same_sso_people_without_losing_anything(): void
    {
        config(['sso.master_data.personnel' => false]);
        $companyId = $this->company(70);
        $jordanUser = $this->user(9001);
        $this->user(9002, 'sam@example.com');
        $alexUser = $this->user(9003);

        // CloudPCR: Jordan (linked user), Sam (email only), a volunteer stub.
        $demJordan = (int) DB::table('dem_personnel')->insertGetId(['company_id' => $companyId, 'user_id' => $jordanUser, 'dpersonnel_01' => 'Reyes', 'dpersonnel_02' => 'Jordan', 'dpersonnel_10' => 'user9001@example.com', 'dpersonnel_11' => '1988-04-12', 'dpersonnel_21' => 'A-17']);
        $demJordanLicense = (int) DB::table('dem_personnel_certifications')->insertGetId(['dem_personnel_id' => $demJordan, 'dpersonnel_22' => '36', 'dpersonnel_23' => '123456', 'dpersonnel_24' => '9925007']);
        $demJordanLevel = (int) DB::table('dem_personnel_cert_levels')->insertGetId(['dem_personnel_id' => $demJordan, 'dpersonnel_38' => '9925007', 'dpersonnel_39' => '2020-02-01']);
        $demSam = (int) DB::table('dem_personnel')->insertGetId(['company_id' => $companyId, 'dpersonnel_01' => 'Okafor', 'dpersonnel_02' => 'Sam', 'dpersonnel_10' => 'Sam@Example.com']);
        $demVolunteer = (int) DB::table('dem_personnel')->insertGetId(['company_id' => $companyId, 'dpersonnel_01' => 'Volunteer', 'dpersonnel_02' => 'Pat', 'is_ems_personnel' => false]);

        // HR: Jordan (a different birthdate, a ZIP SSO refuses, an EMS licence
        // for the same state and a driver's licence), Alex (HR only).
        $hrJordan = (int) DB::table('personnel_profiles')->insertGetId(['company_id' => $companyId, 'user_id' => $jordanUser, 'employee_number' => 'A-17', 'rank' => 'Captain', 'notes' => 'Prefers nights', 'date_of_birth' => '1988-04-21', 'address_line1' => '12 Main St', 'postal_code' => '1298', 'gender' => 'male']);
        $hrJordanEms = (int) DB::table('personnel_licenses')->insertGetId(['personnel_profile_id' => $hrJordan, 'license_type' => 'EMS', 'number' => '123456', 'state' => '36', 'level' => '9925007']);
        $hrJordanDl = (int) DB::table('personnel_licenses')->insertGetId(['personnel_profile_id' => $hrJordan, 'license_type' => 'DL', 'number' => 'D1234', 'state' => '36']);
        $hrJordanPhone = (int) DB::table('personnel_phones')->insertGetId(['personnel_profile_id' => $hrJordan, 'phone_number' => '518-555-0177', 'phone_type' => 'home']);
        $hrAlex = (int) DB::table('personnel_profiles')->insertGetId(['company_id' => $companyId, 'user_id' => $alexUser, 'employee_number' => 'B-2', 'rank' => 'EMT']);

        $this->fakeSso();

        // 1. CloudPCR pushes (flag off).
        $this->importResponses['cloudpcr'] = [
            'created' => 0, 'matched' => 2,
            'unmatched' => [['local_id' => $demVolunteer, 'name' => 'Pat Volunteer', 'reason' => 'no_user']],
            'mapping' => [
                $demJordan => ['sso_id' => 9001, 'updated_at' => '2026-10-13T12:00:00+00:00', 'licenses' => [$demJordanLicense => 61], 'practice_levels' => [$demJordanLevel => 71]],
                $demSam => ['sso_id' => 9002, 'updated_at' => '2026-10-13T12:00:00+00:00'],
            ],
        ];

        $this->artisan('sso:push-master-data', ['entity' => 'personnel'])
            ->expectsOutputToContain('matched=2 assignments_added=0 conflicts=0')
            ->expectsOutputToContain('Pat Volunteer')
            ->assertSuccessful();

        $sent = $this->imports[0];
        $this->assertSame('cloudpcr', $sent['app_slug']);
        $people = collect($sent['personnel'])->keyBy('local_id');
        $this->assertSame(9001, $people[$demJordan]['user_sso_id']);
        $this->assertArrayNotHasKey('user_sso_id', $people[$demSam]);
        $this->assertSame('Sam@Example.com', $people[$demSam]['user']['email']);
        $this->assertSame([['local_id' => $demJordanLicense, 'state' => '36', 'number' => '123456', 'level' => '9925007', 'current_since' => null, 'first_issued_on' => null, 'expires_on' => null]], $people[$demJordan]['licenses']);
        $this->assertSame($demJordanLevel, $people[$demJordan]['membership']['practice_levels'][0]['local_id']);
        $this->assertArrayHasKey($demVolunteer, $people->all(), 'Rows SSO cannot match are sent for review, not dropped');

        // 2. HR pushes (flag off). Its driver's licence never goes up.
        $this->actAs('hr');
        $this->importResponses['hr'] = [
            'created' => 0, 'matched' => 2,
            'filled' => [['local_id' => $hrJordan, 'name' => 'Jordan Reyes', 'fields' => ['profile.address.street']]],
            'conflicts' => [['local_id' => $hrJordan, 'name' => 'Jordan Reyes', 'field' => 'profile.date_of_birth', 'local_value' => '1988-04-21', 'sso_value' => '1988-04-12']],
            'partial' => [['local_id' => $hrJordan, 'name' => 'Jordan Reyes', 'fields' => ['profile.address.zip' => 'The ZIP code must be 5 or 9 digits.', 'profile.sex' => 'Not a sex code.'], 'kept' => ['profile.address.zip' => '1298', 'profile.sex' => 'male']]],
            'mapping' => [
                $hrJordan => ['sso_id' => 9001, 'updated_at' => '2026-10-13T12:01:00+00:00', 'licenses' => [$hrJordanEms => 61], 'phones' => [$hrJordanPhone => 32]],
                $hrAlex => ['sso_id' => 9003, 'updated_at' => '2026-10-13T12:01:00+00:00'],
            ],
        ];

        $this->artisan('sso:push-master-data', ['entity' => 'personnel'])
            ->expectsOutputToContain('conflicts=1 truncated_descriptions=0')
            ->expectsOutputToContain('SSO already had a different value for these and kept it')
            ->expectsOutputToContain('SSO kept these values as pending')
            ->assertSuccessful();

        $hrPeople = collect($this->imports[1]['personnel'])->keyBy('local_id');
        $this->assertSame(['local_id' => $hrJordanEms, 'state' => '36', 'number' => '123456', 'first_issued_on' => null, 'expires_on' => null, 'level' => '9925007'], $hrPeople[$hrJordan]['licenses'][0]);
        $this->assertCount(1, $hrPeople[$hrJordan]['licenses'], "HR's driver's licence is not an EMS licence and is not pushed");
        $this->assertSame('male', $hrPeople[$hrJordan]['profile']['sex']);

        // 3. A delivery before the flag is on changes nothing.
        $this->deliver($this->ssoJordan())->assertOk()->assertJson(['status' => 'ignored']);

        // 4. Both turn the mirror on and resync.
        config(['sso.master_data.personnel' => true]);
        $this->actAs('cloudpcr');
        $this->artisan('sso:resync-master-data', ['entity' => 'personnel'])->assertSuccessful();
        $this->actAs('hr');
        $this->artisan('sso:resync-master-data', ['entity' => 'personnel'])->assertSuccessful();

        // CloudPCR: same ids, SSO's data, the volunteer untouched, Alex (HR's
        // only) created as a new row.
        $this->assertSame(4, DB::table('dem_personnel')->count());
        $demLinks = DB::table('dem_personnel')->whereNotNull('sso_user_id')->orderBy('sso_user_id')->pluck('id', 'sso_user_id')->map(fn ($id): int => (int) $id)->all();
        $this->assertSame([9001, 9002, 9003], array_keys($demLinks));
        $this->assertSame([$demJordan, $demSam], [$demLinks[9001], $demLinks[9002]]);
        $this->assertSame('Kim', DB::table('dem_personnel')->find($demLinks[9003])->dpersonnel_01);
        $jordan = DB::table('dem_personnel')->find($demJordan);
        $this->assertSame('1988-04-12', $jordan->dpersonnel_11);
        $this->assertSame('12 Main St', $jordan->dpersonnel_04, "HR's street, filled into SSO, reaches CloudPCR");
        $this->assertNull($jordan->dpersonnel_07, "HR's refused ZIP is HR's, never CloudPCR's");
        $this->assertNull($jordan->dpersonnel_40);
        $this->assertSame([$demJordanLicense], DB::table('dem_personnel_certifications')->where('dem_personnel_id', $demJordan)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('2027-01-01', DB::table('dem_personnel_certifications')->find($demJordanLicense)->dpersonnel_27);
        $this->assertSame([$demJordanLevel], DB::table('dem_personnel_cert_levels')->where('dem_personnel_id', $demJordan)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('Sam', DB::table('dem_personnel')->find($demSam)->dpersonnel_02);
        $volunteer = DB::table('dem_personnel')->find($demVolunteer);
        $this->assertNull($volunteer->sso_user_id);
        $this->assertSame('Volunteer', $volunteer->dpersonnel_01);
        $this->assertTrue((bool) $volunteer->is_active);

        // HR: same ids, SSO's birthdate (the conflict SSO won), HR's own fields,
        // HR's refused ZIP kept, the driver's licence and SSO-unknown phone kept.
        $this->assertSame(3, DB::table('personnel_profiles')->count(), 'Sam (CloudPCR only) gets an HR profile; nobody is duplicated');
        $hr = DB::table('personnel_profiles')->find($hrJordan);
        $this->assertSame(9001, (int) $hr->sso_user_id);
        $this->assertFalse((bool) $hr->sso_link_pending);
        $this->assertSame('1988-04-12', $hr->date_of_birth);
        $this->assertSame('1298', $hr->postal_code, 'A value SSO refused is never wiped from the app that sent it');
        $this->assertSame('Captain', $hr->rank);
        $this->assertSame('Prefers nights', $hr->notes);
        $this->assertSame('male', $hr->gender);
        $this->assertSame('Jordan Reyes', DB::table('users')->find($jordanUser)->name);
        $this->assertSame(61, (int) DB::table('personnel_licenses')->find($hrJordanEms)->sso_license_id);
        $this->assertSame('2027-01-01', DB::table('personnel_licenses')->find($hrJordanEms)->expiration_date);
        $dl = DB::table('personnel_licenses')->find($hrJordanDl);
        $this->assertSame(['DL', 'D1234', null], [$dl->license_type, $dl->number, $dl->sso_license_id]);
        $this->assertSame(2, DB::table('personnel_licenses')->where('personnel_profile_id', $hrJordan)->count());
        $this->assertSame(32, (int) DB::table('personnel_phones')->find($hrJordanPhone)->sso_phone_id);
        $this->assertSame(9003, (int) DB::table('personnel_profiles')->find($hrAlex)->sso_user_id);
        $this->assertSame('EMT', DB::table('personnel_profiles')->find($hrAlex)->rank);
        $this->assertSame(
            DB::table('dem_personnel')->whereNotNull('sso_user_id')->orderBy('sso_user_id')->pluck('sso_user_id')->map(fn ($id): int => (int) $id)->all(),
            DB::table('personnel_profiles')->orderBy('sso_user_id')->pluck('sso_user_id')->map(fn ($id): int => (int) $id)->all(),
            'Both apps hold the same SSO people',
        );

        // A second resync in each app changes nothing.
        $this->artisan('sso:resync-master-data', ['entity' => 'personnel'])->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=3 deactivated=0')->assertSuccessful();
        $this->actAs('cloudpcr');
        $this->artisan('sso:resync-master-data', ['entity' => 'personnel'])->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=3 deactivated=0')->assertSuccessful();
    }

    /**
     * Jordan in SSO after both pushes: CloudPCR's values, HR's street filled
     * in, HR's ZIP and sex kept as HR's pending values, the NY licence and
     * HR's phone under SSO ids.
     *
     * @return array<string, mixed>
     */
    private function ssoJordan(): array
    {
        return $this->person(70, 9001, [
            'profile' => ['sex' => null, 'race' => [], 'address' => ['street' => '12 Main St', 'city_gnis' => null, 'city_name' => null, 'state' => null, 'zip' => null, 'country' => null], 'national_registry' => null],
            'phones' => [['id' => 32, 'number' => '518-555-0177', 'type' => '9913003', 'is_primary' => false]],
            'emails' => [],
            'immunizations' => [],
            'pending' => ['profile.address.zip' => ['hr' => '1298'], 'profile.sex' => ['hr' => 'male']],
            'updated_at' => '2026-10-13T12:02:00+00:00',
        ]);
    }

    private function fakeSso(): void
    {
        Http::fake(function (Request $request) {
            $app = (string) config('sso.app_slug');

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/companies/70/personnel/import')) {
                $this->imports[] = $request->data();

                return Http::response($this->importResponses[$app]);
            }

            return Http::response(['company' => ['id' => 70], 'data' => [
                $this->ssoJordan(),
                $this->person(70, 9002, ['user' => ['email' => 'sam@example.com', 'first_name' => 'Sam', 'last_name' => 'Okafor'], 'phones' => [], 'emails' => [], 'immunizations' => [], 'licenses' => [], 'membership' => ['practice_levels' => []]]),
                $this->person(70, 9003, ['user' => ['first_name' => 'Alex', 'last_name' => 'Kim'], 'phones' => [], 'emails' => [], 'immunizations' => [], 'licenses' => [], 'membership' => ['practice_levels' => []]]),
            ], 'next_cursor' => null]);
        });
    }
}

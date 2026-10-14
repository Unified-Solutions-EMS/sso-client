<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Divisions;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * sso:push-master-data divisions: the one-time upward seed, run with the
 * entity still disabled (only the migration is needed).
 */
class DivisionPushTest extends DivisionsTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sso.master_data.divisions' => false]);
        $this->companyId = $this->company(70);
    }

    public function test_it_sends_crews_divisions_and_people_and_links_the_mapping(): void
    {
        $alpha = $this->localDivision($this->companyId, 'Alpha Shift');
        $long = $this->localDivision($this->companyId, str_repeat('Long name ', 15));
        DB::table('divisions')->where('id', $long)->update(['is_active' => false]);
        $otherCompany = $this->company(80);
        $foreign = $this->localDivision($otherCompany, 'Elsewhere');

        $this->user(9001, $this->companyId, $alpha);
        $this->user(9002, $this->companyId);
        $noSsoId = (int) DB::table('users')->insertGetId(['name' => 'Local only', 'email' => 'local@example.com']);
        $this->member($noSsoId, $this->companyId, $alpha);
        $this->user(9003, $otherCompany, $foreign);

        Http::fake(['sso.test/api/internal/companies/70/divisions/import' => Http::response([
            'created' => 1, 'matched' => 1, 'assignments_added' => 1,
            'conflicts' => [['name' => 'Long name', 'reason' => 'turned_off_in_sso']],
            'assignment_conflicts' => [], 'unknown_users' => [], 'invalid' => [],
            'mapping' => [
                (string) $alpha => ['sso_id' => 501, 'updated_at' => '2026-10-09T12:00:00+00:00'],
                (string) $long => ['sso_id' => 502, 'updated_at' => '2026-10-09T12:00:00+00:00'],
            ],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'divisions', '--company' => '70'])
            ->expectsOutputToContain("name of local row {$long} is longer than SSO allows")
            ->expectsOutputToContain('created=1 matched=1 assignments_added=1 conflicts=1 truncated_descriptions=0 unknown_users=0 skipped_local_users_without_sso_id=1 linked=2 link_collisions=0 assignment_conflicts=0 invalid=0')
            ->expectsTable(['Conflict', 'Reason'], [['Long name', 'turned_off_in_sso']])
            ->assertSuccessful();

        Http::assertSent(function (Request $request) use ($alpha, $long): bool {
            return $request->url() === 'https://sso.test/api/internal/companies/70/divisions/import'
                && $request['app_slug'] === 'crew-scheduling'
                && $request['divisions'] === [
                    ['local_id' => $alpha, 'name' => 'Alpha Shift', 'code' => null, 'is_active' => true],
                    ['local_id' => $long, 'name' => mb_substr(str_repeat('Long name ', 15), 0, 100), 'code' => null, 'is_active' => false],
                ]
                && $request['assignments'] === [['user_sso_id' => '9001', 'local_division_id' => $alpha]];
        });

        $this->assertSame(501, (int) DB::table('divisions')->find($alpha)->sso_division_id);
        $this->assertSame('2026-10-09 12:00:00', DB::table('divisions')->find($alpha)->sso_updated_at);
        $this->assertSame(str_repeat('Long name ', 15), DB::table('divisions')->find($long)->name, 'the local name is unchanged');
        $this->assertNull(DB::table('divisions')->find($foreign)->sso_division_id);
    }

    public function test_a_person_sso_places_elsewhere_is_reported(): void
    {
        $alpha = $this->localDivision($this->companyId, 'Alpha Shift');
        $this->user(9001, $this->companyId, $alpha);

        Http::fake(['sso.test/*' => Http::response([
            'created' => 0, 'matched' => 1, 'assignments_added' => 0, 'conflicts' => [], 'unknown_users' => [], 'invalid' => [],
            'assignment_conflicts' => [['user_sso_id' => 9001, 'sso_division_id' => 777, 'incoming_division_id' => 501]],
            'mapping' => [(string) $alpha => ['sso_id' => 501, 'updated_at' => null]],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'divisions'])
            ->expectsOutputToContain('assignment_conflicts=1')
            ->expectsTable(['SSO user', 'SSO has', 'This app had'], [['9001', '777', '501']])
            ->assertSuccessful();
    }

    public function test_dry_run_sends_nothing(): void
    {
        $alpha = $this->localDivision($this->companyId, 'Alpha Shift');
        $this->user(9001, $this->companyId, $alpha);
        Http::fake();

        $this->artisan('sso:push-master-data', ['entity' => 'divisions', '--dry-run' => true])
            ->expectsOutputToContain("would send 1 divisions and 1 users' assignments")
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull(DB::table('divisions')->find($alpha)->sso_division_id);
    }
}

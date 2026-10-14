<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Divisions;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DivisionResyncTest extends DivisionsTestCase
{
    public function test_a_full_resync_reconciles_the_list_and_everyones_division(): void
    {
        $companyId = $this->company(70);
        $alpha = $this->localDivision($companyId, 'Alpha Shift', 501);
        $gone = $this->localDivision($companyId, 'Gone', 509);
        $local = $this->localDivision($companyId, 'Local only');
        DB::table('locations')->insert(['division_id' => $gone, 'name' => 'Old post']);

        $alice = $this->user(9001, $companyId, $gone);
        $bob = $this->user(9002, $companyId, $alpha);
        $cara = $this->user(9003, $companyId, $local);
        $this->user(9004, $companyId);

        Http::fake(['sso.test/api/internal/companies/70/divisions' => Http::response($this->snapshot(70, [
            $this->divisionRecord(501, 'Alpha Shift'),
            $this->divisionRecord(502, 'North District', ['code' => 'ND', 'sort_order' => 2]),
        ], [
            ['user_id' => 9001, 'division_id' => 502],
            ['user_id' => 9003, 'division_id' => 502],
            ['user_id' => 7777, 'division_id' => 501],
        ]))]);

        $this->artisan('sso:resync-master-data', ['entity' => 'divisions'])
            ->expectsOutputToContain('created=1 updated=1 linked=0 unchanged=0 deactivated=1 assignments_set=1 assignments_cleared=1 assignments_kept_unconfirmed=1 unknown_users=1')
            ->assertSuccessful();

        $north = (int) $this->mirrored($companyId, 502)->id;
        $this->assertSame($north, $this->divisionOf($alice, $companyId));
        $this->assertNull($this->divisionOf($bob, $companyId), 'SSO lists no division for Bob');
        $this->assertSame($local, $this->divisionOf($cara, $companyId), 'an unlinked local division is never replaced');

        $this->assertFalse((bool) DB::table('divisions')->find($gone)->is_active);
        $this->assertSame(1, DB::table('locations')->count(), 'turned off, never deleted');
        $this->assertTrue((bool) DB::table('divisions')->find($local)->is_active);

        $this->artisan('sso:resync-master-data', ['entity' => 'divisions'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=2 deactivated=0 assignments_set=0 assignments_cleared=0')
            ->assertSuccessful();
    }

    public function test_link_by_name_links_crews_rows_without_creating_or_deleting(): void
    {
        $companyId = $this->company(70);
        $alpha = $this->localDivision($companyId, 'Alpha Shift');
        $this->localDivision($companyId, 'Bravo Shift');

        Http::fake(['sso.test/*' => Http::response($this->snapshot(70, [
            $this->divisionRecord(501, 'alpha shift'),
            $this->divisionRecord(502, 'North'),
        ]))]);

        $this->artisan('sso:resync-master-data', ['entity' => 'divisions', '--link-by-name' => true])
            ->expectsOutputToContain('linked 1, ambiguous 0, local only 1, SSO only 1')
            ->assertSuccessful();

        $this->assertSame(501, (int) DB::table('divisions')->find($alpha)->sso_division_id);
        $this->assertSame(2, DB::table('divisions')->count());
    }

    public function test_a_daily_resync_is_scheduled_once_divisions_are_enabled(): void
    {
        $this->app->forgetInstance(Schedule::class);

        $commands = collect($this->app->make(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command)
            ->filter(fn (string $command): bool => str_contains($command, 'sso:resync-master-data'))
            ->values();

        $this->assertCount(1, $commands);
        $this->assertStringContainsString("'divisions'", $commands[0]);
    }
}

<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * `sso:resync-master-data personnel` walks SSO's pages, writes every person
 * and turns off (never deletes) people SSO no longer lists. It never wipes:
 * people and child rows SSO does not know stay as they are, a section an
 * older SSO leaves out is not written, and a failed page changes nothing.
 */
class PersonnelResyncTest extends PersonnelTestCase
{
    private ?\Closure $responder = null;

    public function test_the_resync_walks_every_page_and_is_idempotent(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        $this->user(9002);

        $this->respond(fn (array $query) => ($query['cursor'] ?? null) === '9001'
            ? Http::response(['company' => ['id' => 70], 'data' => [$this->person(70, 9002, ['user' => ['first_name' => 'Sam']])], 'next_cursor' => null])
            : Http::response(['company' => ['id' => 70], 'data' => [$this->person(70, 9001)], 'next_cursor' => 9001]));

        $this->artisan('sso:resync-master-data', ['entity' => 'personnel'])
            ->expectsOutputToContain('created=2')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sso.test/api/internal/companies/70/personnel'
            && $request->hasHeader('Authorization', 'Bearer core-key'));
        $this->assertSame(['Jordan', 'Sam'], DB::table('dem_personnel')->where('company_id', $companyId)->orderBy('sso_user_id')->pluck('dpersonnel_02')->all());

        $this->artisan('sso:resync-master-data', ['entity' => 'personnel'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=2 deactivated=0')
            ->assertSuccessful();
    }

    public function test_a_failed_page_changes_nothing_locally(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        $this->user(9002);
        $this->fakePages([[$this->person(70, 9001), $this->person(70, 9002)]]);
        $this->artisan('sso:resync-master-data', ['entity' => 'personnel'])->assertSuccessful();

        $this->respond(fn (array $query) => isset($query['cursor'])
            ? Http::response(['message' => 'Server Error'], 500)
            : Http::response(['company' => ['id' => 70], 'data' => [], 'next_cursor' => 1]));

        $this->artisan('sso:resync-master-data', ['entity' => 'personnel'])->assertFailed();

        $this->assertSame(2, DB::table('dem_personnel')->where('company_id', $companyId)->where('is_active', true)->count(), 'An incomplete set never turns anyone off');
    }

    public function test_the_resync_never_wipes(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        $this->user(9002);
        $this->user(9003);
        $this->fakePages([[$this->person(70, 9001), $this->person(70, 9002), $this->person(70, 9003)]]);
        $this->artisan('sso:resync-master-data', ['entity' => 'personnel'])->assertSuccessful();

        $jordan = $this->demRow($companyId, 9001);
        $localLicense = (int) DB::table('dem_personnel_certifications')->insertGetId(['dem_personnel_id' => $jordan->id, 'dpersonnel_22' => '42', 'dpersonnel_23' => 'PA-LOCAL']);
        $volunteer = (int) DB::table('dem_personnel')->insertGetId(['company_id' => $companyId, 'dpersonnel_01' => 'Volunteer', 'dpersonnel_02' => 'Pat', 'is_ems_personnel' => false]);

        // An older SSO without the licence list, profile or membership
        // sections for 9001; 9002 has left (listed, not a member); 9003 is
        // no longer listed at all.
        $older = $this->person(70, 9001, ['updated_at' => '2026-10-13T13:00:00+00:00']);
        unset($older['licenses'], $older['profile'], $older['membership']);
        $left = $this->person(70, 9002, ['membership' => ['is_member' => false, 'is_active' => false], 'updated_at' => '2026-10-13T13:00:00+00:00']);
        $this->fakePages([[$older, $left]]);

        $this->artisan('sso:resync-master-data', ['entity' => 'personnel'])
            ->expectsOutputToContain('unlinked_local=1')
            ->assertSuccessful();

        $jordan = $this->demRow($companyId, 9001);
        $this->assertTrue((bool) $jordan->is_active);
        $this->assertSame('1988-04-12', $jordan->dpersonnel_11, 'A section SSO left out is not written');
        $this->assertSame('A-17', $jordan->dpersonnel_21);
        $this->assertSame(2, DB::table('dem_personnel_certifications')->where('dem_personnel_id', $jordan->id)->count(), 'A licence list SSO left out is not touched');
        $this->assertNotNull(DB::table('dem_personnel_certifications')->find($localLicense), 'A local child SSO never knew is kept');

        $this->assertFalse((bool) $this->demRow($companyId, 9002)->is_active);
        $this->assertFalse((bool) $this->demRow($companyId, 9003)->is_active);
        $this->assertSame('Reyes', $this->demRow($companyId, 9003)->dpersonnel_01, 'Turned off, never blanked or deleted');
        $this->assertSame(1, DB::table('dem_personnel_certifications')->where('dem_personnel_id', $this->demRow($companyId, 9003)->id)->count());

        $this->assertSame('Volunteer', DB::table('dem_personnel')->find($volunteer)->dpersonnel_01, 'People SSO never knew stay as they are');
        $this->assertTrue((bool) DB::table('dem_personnel')->find($volunteer)->is_active);
        $this->assertSame(4, DB::table('dem_personnel')->count());
    }

    public function test_the_resync_confirms_a_pending_adoption_and_writes_it(): void
    {
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $localId = (int) DB::table('dem_personnel')->insertGetId(['company_id' => $companyId, 'user_id' => $userId, 'dpersonnel_01' => 'Reyes-Local']);
        $localLicense = (int) DB::table('dem_personnel_certifications')->insertGetId(['dem_personnel_id' => $localId, 'dpersonnel_22' => '36', 'dpersonnel_23' => 'OLD']);
        $this->deliver($this->person(70, 9001))->assertOk()->assertJson(['result' => 'linked']);

        $this->fakePages([[$this->person(70, 9001)]]);
        $this->artisan('sso:resync-master-data', ['entity' => 'personnel'])->expectsOutputToContain('updated=1')->assertSuccessful();

        $row = DB::table('dem_personnel')->find($localId);
        $this->assertFalse((bool) $row->sso_link_pending);
        $this->assertSame('Reyes', $row->dpersonnel_01);
        $license = DB::table('dem_personnel_certifications')->find($localLicense);
        $this->assertSame(61, (int) $license->sso_license_id, 'The local licence for the same state is adopted in place');
        $this->assertSame('123456', $license->dpersonnel_23);
        $this->assertSame(1, DB::table('dem_personnel')->count());
    }

    /**
     * @param  list<list<array<string, mixed>>>  $pages
     */
    private function fakePages(array $pages): void
    {
        $this->respond(function (array $query) use ($pages) {
            $index = (int) ($query['cursor'] ?? 0);

            return Http::response(['company' => ['id' => 70], 'data' => $pages[$index], 'next_cursor' => $index === count($pages) - 1 ? null : $index + 1]);
        });
    }

    /**
     * SSO's answer by query string. Http::fake() keeps the first stub that
     * answers, so the fake is installed once and reads the current responder.
     *
     * @param  \Closure(array<string, mixed>): mixed  $responder
     */
    private function respond(\Closure $responder): void
    {
        $installed = $this->responder !== null;
        $this->responder = $responder;

        if (! $installed) {
            Http::fake(function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return ($this->responder)($query);
            });
        }
    }
}

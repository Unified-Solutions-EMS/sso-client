<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * `sso:push-master-data personnel` runs with the flag off, sends every local
 * person (children with their local ids), and links rows from SSO's mapping
 * without writing any field. A mapping that would steal a link is refused.
 */
class PersonnelPushTest extends PersonnelTestCase
{
    public function test_a_dry_run_sends_nothing(): void
    {
        config(['sso.master_data.personnel' => false]);
        $companyId = $this->company(70);
        DB::table('dem_personnel')->insert(['company_id' => $companyId, 'dpersonnel_01' => 'Reyes']);
        Http::fake();

        $this->artisan('sso:push-master-data', ['entity' => 'personnel', '--dry-run' => true])
            ->expectsOutputToContain('would send 1 personnel')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_mapping_links_rows_and_refuses_collisions_and_writes_no_field(): void
    {
        config(['sso.master_data.personnel' => false]);
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $first = (int) DB::table('dem_personnel')->insertGetId(['company_id' => $companyId, 'user_id' => $userId, 'dpersonnel_01' => 'Reyes-Local']);
        $duplicate = (int) DB::table('dem_personnel')->insertGetId(['company_id' => $companyId, 'dpersonnel_10' => 'user9001@example.com', 'dpersonnel_01' => 'Reyes']);
        $phone = (int) DB::table('dem_personnel_phones')->insertGetId(['dem_personnel_id' => $first, 'phone_number' => '518-555-0199']);
        $otherCompany = $this->company(71);
        $foreign = (int) DB::table('dem_personnel')->insertGetId(['company_id' => $otherCompany, 'dpersonnel_01' => 'Elsewhere']);

        Http::fake(['*/companies/70/personnel/import' => Http::response([
            'matched' => 1,
            'mapping' => [
                $first => ['sso_id' => 9001, 'updated_at' => '2026-10-13T12:00:00Z', 'phones' => [$phone => 31]],
                $duplicate => ['sso_id' => 9001, 'updated_at' => '2026-10-13T12:00:00Z'],
                $foreign => 9009,
            ],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'personnel', '--company' => '70'])
            ->expectsOutputToContain('linked=1 link_collisions=2')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['app_slug'] === 'cloudpcr' && count($request['personnel']) === 2);

        $row = DB::table('dem_personnel')->find($first);
        $this->assertSame(9001, (int) $row->sso_user_id);
        $this->assertSame('2026-10-13 12:00:00', $row->sso_updated_at);
        $this->assertFalse((bool) $row->sso_link_pending);
        $this->assertSame('Reyes-Local', $row->dpersonnel_01, 'The push links; only the resync writes fields');
        $this->assertSame(31, (int) DB::table('dem_personnel_phones')->find($phone)->sso_phone_id);
        $this->assertNull(DB::table('dem_personnel')->find($duplicate)->sso_user_id, 'Two local rows for one person: the second is reported, not linked');
        $this->assertNull(DB::table('dem_personnel')->find($foreign)->sso_user_id, "Another company's row is never linked");

        $this->artisan('sso:push-master-data', ['entity' => 'personnel', '--company' => '70'])
            ->expectsOutputToContain('linked=1 link_collisions=2')
            ->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => collect($request['personnel'])->firstWhere('local_id', $first)['user_sso_id'] === 9001);
    }
}

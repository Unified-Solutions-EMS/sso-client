<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SSO's personnel import takes at most 500 rows per request (422 above), so a
 * larger roster goes up in chunks and the answers fold back into one.
 */
class PersonnelPushBatchingTest extends PersonnelTestCase
{
    /**
     * @return list<int>
     */
    private function seedPeople(int $companyId, int $count): array
    {
        DB::table('dem_personnel')->insert(array_map(fn (int $i): array => ['company_id' => $companyId, 'dpersonnel_01' => "Person {$i}"], range(1, $count)));

        return DB::table('dem_personnel')->where('company_id', $companyId)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function test_twelve_hundred_people_go_up_in_three_requests_and_every_row_is_linked(): void
    {
        config(['sso.master_data.personnel' => false]);
        $companyId = $this->company(70);
        $ids = $this->seedPeople($companyId, 1200);

        Http::fake(function (Request $request) {
            $mapping = [];
            $unmatched = [];

            foreach ($request['personnel'] as $person) {
                $mapping[$person['local_id']] = ['sso_id' => 100000 + $person['local_id'], 'updated_at' => '2026-10-13T12:00:00Z'];
            }

            $unmatched[] = ['local_id' => $request['personnel'][0]['local_id'], 'name' => 'x', 'reason' => 'no_user'];

            return Http::response(['matched' => count($mapping), 'filled' => [], 'conflicts' => [], 'partial' => [], 'unmatched' => $unmatched, 'invalid' => [], 'not_values_dropped' => 2, 'mapping' => $mapping]);
        });

        $this->artisan('sso:push-master-data', ['entity' => 'personnel', '--company' => '70'])
            ->expectsOutputToContain('created=0 matched=1200 assignments_added=0 conflicts=0 truncated_descriptions=0 unknown_users=0 skipped_local_users_without_sso_id=1200 linked=1200 link_collisions=0 invalid=0 filled=0 partial=0 unmatched=3')
            ->assertSuccessful();

        $sizes = [];
        Http::assertSentCount(3);
        Http::assertSent(function (Request $request) use (&$sizes): bool {
            $sizes[] = count($request['personnel']);

            return $request['app_slug'] === 'cloudpcr';
        });
        $this->assertSame([500, 500, 200], $sizes);

        $this->assertSame(1200, DB::table('dem_personnel')->where('company_id', $companyId)->whereNotNull('sso_user_id')->count());
        $this->assertSame(100000 + $ids[1199], (int) DB::table('dem_personnel')->find($ids[1199])->sso_user_id);
    }

    public function test_a_roster_under_the_cap_is_one_request(): void
    {
        config(['sso.master_data.personnel' => false]);
        $companyId = $this->company(70);
        $this->seedPeople($companyId, 500);

        Http::fake(fn (Request $request) => Http::response(['matched' => 0, 'mapping' => (object) []]));

        $this->artisan('sso:push-master-data', ['entity' => 'personnel', '--company' => '70'])->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => count($request['personnel']) === 500);
    }
}

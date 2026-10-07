<?php

namespace Unified\SsoClient\Tests\Feature\MasterData;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PushMasterDataCommandTest extends MasterDataTestCase
{
    private const IMPORT_URL = 'sso.test/api/internal/companies/70/qualifications/import';

    /**
     * @param  array<int|string, int>  $mapping
     * @param  array<string, mixed>  $extra
     */
    private function fakeImport(array $mapping, array $extra = []): void
    {
        Http::fake([self::IMPORT_URL => Http::response(array_merge([
            'mapping' => (object) $mapping,
            'created' => 0,
            'matched' => count($mapping),
            'assignments_added' => 0,
            'conflicts' => [],
            'unknown_users' => 0,
        ], $extra))]);
    }

    public function test_it_sends_the_catalog_and_assignments_in_the_import_shape(): void
    {
        $companyId = $this->company(70);
        $alice = $this->user(9001);
        $noSso = (int) DB::table('users')->insertGetId(['name' => 'Legacy', 'email' => 'legacy@example.com', 'sso_id' => null]);
        $medic = $this->localQualification($companyId, 'Paramedic');
        $driver = $this->localQualification($companyId, 'Driver');
        DB::table('qualifications')->where('id', $driver)->update(['description' => 'Cleared to drive', 'is_active' => false]);
        $this->assign($alice, $companyId, $medic);
        $this->assign($alice, $companyId, $driver);
        $this->assign($noSso, $companyId, $medic);

        $other = $this->company(80, 'Bravo');
        $otherRow = $this->localQualification($other, 'Hazmat');
        $this->assign($alice, $other, $otherRow);

        $this->fakeImport([(string) $medic => 501, (string) $driver => 502]);

        $this->artisan('sso:push-master-data', ['entity' => 'qualifications', '--company' => '70'])
            ->expectsOutputToContain('skipped_local_users_without_sso_id=1 linked=2 link_collisions=0')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($medic, $driver): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://'.self::IMPORT_URL
                && $request->hasHeader('Authorization', 'Bearer core-key')
                && $request->data() === [
                    'app_slug' => 'crew-scheduling',
                    'qualifications' => [
                        ['local_id' => $medic, 'name' => 'Paramedic', 'description' => null, 'is_active' => true],
                        ['local_id' => $driver, 'name' => 'Driver', 'description' => 'Cleared to drive', 'is_active' => false],
                    ],
                    'assignments' => [
                        ['user_sso_id' => '9001', 'local_qualification_ids' => [$medic, $driver]],
                    ],
                ];
        });
    }

    public function test_it_applies_the_mapping_and_prints_the_sso_summary(): void
    {
        $companyId = $this->company(70);
        $medic = $this->localQualification($companyId, 'Paramedic');
        $driver = $this->localQualification($companyId, 'Driver');
        $this->fakeImport([
            (string) $medic => ['sso_id' => 501, 'updated_at' => '2026-10-07T11:30:00-04:00'],
            (string) $driver => ['sso_id' => 502, 'updated_at' => '2026-10-07T15:30:05Z'],
        ], [
            'created' => 1,
            'matched' => 1,
            'assignments_added' => 4,
            'conflicts' => [['name' => 'Driver', 'local_description' => 'Ours', 'sso_description' => 'Theirs']],
            'unknown_users' => [9999, 9998],
        ]);

        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('created=1 matched=1 assignments_added=4 conflicts=1 unknown_users=2 skipped_local_users_without_sso_id=0 linked=2')
            ->expectsTable(['Conflict', 'Local description', 'SSO description'], [['Driver', 'Ours', 'Theirs']])
            ->assertSuccessful();

        $this->assertSame($medic, (int) $this->mirrored($companyId, 501)->id);
        $this->assertSame($driver, (int) $this->mirrored($companyId, 502)->id);
        $this->assertSame('2026-10-07 15:30:00', $this->mirrored($companyId, 501)->sso_updated_at);
        $this->assertSame('2026-10-07 15:30:05', $this->mirrored($companyId, 502)->sso_updated_at);
        $this->assertSame(2, DB::table('qualifications')->count());
    }

    public function test_rerunning_reapplies_the_same_mapping(): void
    {
        $companyId = $this->company(70);
        $medic = $this->localQualification($companyId, 'Paramedic');
        $this->fakeImport([(string) $medic => 501]);

        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])->assertSuccessful();
        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('linked=1 link_collisions=0')
            ->assertSuccessful();

        $this->assertSame($medic, (int) $this->mirrored($companyId, 501)->id);
        $this->assertSame(1, DB::table('qualifications')->count());
    }

    public function test_a_mapping_that_folds_two_local_rows_into_one_sso_row_links_only_the_first(): void
    {
        $companyId = $this->company(70);
        $driver = $this->localQualification($companyId, 'Driver');
        $shout = $this->localQualification($companyId, 'DRIVER');
        $elsewhere = $this->localQualification($companyId, 'Paramedic', 777);
        $this->fakeImport([(string) $driver => 502, (string) $shout => 502, (string) $elsewhere => 501]);

        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('linked=1 link_collisions=2')
            ->assertSuccessful();

        $this->assertSame($driver, (int) $this->mirrored($companyId, 502)->id);
        $this->assertNull(DB::table('qualifications')->where('id', $shout)->value('sso_qualification_id'));
        $this->assertSame(777, (int) DB::table('qualifications')->where('id', $elsewhere)->value('sso_qualification_id'));
    }

    public function test_dry_run_sends_nothing_and_links_nothing(): void
    {
        $companyId = $this->company(70);
        $alice = $this->user(9001);
        $noSso = (int) DB::table('users')->insertGetId(['name' => 'Legacy', 'email' => 'legacy@example.com']);
        $medic = $this->localQualification($companyId, 'Paramedic');
        $this->assign($alice, $companyId, $medic);
        $this->assign($noSso, $companyId, $medic);
        Http::fake();

        $this->artisan('sso:push-master-data', ['entity' => 'qualifications', '--dry-run' => true])
            ->expectsOutputToContain("would send 1 qualifications and 1 users' assignments; skipped local users without sso_id=1")
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull(DB::table('qualifications')->where('id', $medic)->value('sso_qualification_id'));
    }

    public function test_a_response_without_a_mapping_fails_and_links_nothing(): void
    {
        $companyId = $this->company(70);
        $medic = $this->localQualification($companyId, 'Paramedic');
        Http::fake([self::IMPORT_URL => Http::response(['created' => 1])]);

        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('no mapping')
            ->assertFailed();

        $this->assertNull(DB::table('qualifications')->where('id', $medic)->value('sso_qualification_id'));
    }

    public function test_it_runs_while_the_entity_is_still_disabled(): void
    {
        config(['sso.master_data.qualifications' => false]);
        $companyId = $this->company(70);
        $medic = $this->localQualification($companyId, 'Paramedic');
        $this->fakeImport([(string) $medic => 501]);

        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('linked=1')
            ->assertSuccessful();

        $this->assertSame($medic, (int) $this->mirrored($companyId, 501)->id);
    }

    public function test_the_bare_int_mapping_form_leaves_sso_updated_at_empty_so_the_next_delivery_applies(): void
    {
        $companyId = $this->company(70);
        $medic = $this->localQualification($companyId, 'Paramedic');
        $this->fakeImport([(string) $medic => 501]);

        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])->assertSuccessful();

        $this->assertNull($this->mirrored($companyId, 501)->sso_updated_at);

        $this->postWebhook('qualification.updated', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Paramedic', ['description' => 'From SSO', 'updated_at' => '2026-10-07T09:00:00Z']),
        ])->assertJson(['status' => 'ok', 'result' => 'updated']);
    }

    public function test_the_imports_own_webhooks_are_not_stale_against_the_mapping_stamp(): void
    {
        $companyId = $this->company(70);
        $medic = $this->localQualification($companyId, 'paramedic');
        $this->fakeImport([(string) $medic => ['sso_id' => 501, 'updated_at' => '2026-10-07T12:00:00Z']]);

        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])->assertSuccessful();

        $this->postWebhook('qualification.created', [
            'company' => ['id' => 70],
            'qualification' => $this->qualificationRecord(501, 'Paramedic', ['applies_to' => ['cloudpcr'], 'updated_at' => '2026-10-07T12:00:00Z']),
        ])->assertJson(['status' => 'ok', 'result' => 'updated']);

        $this->assertSame('Paramedic', $this->mirrored($companyId, 501)->name);
    }

    public function test_an_import_timeout_prints_a_clear_message_and_links_nothing(): void
    {
        config(['sso.master_data.timeout' => 120]);
        $companyId = $this->company(70);
        $medic = $this->localQualification($companyId, 'Paramedic');
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 120001 milliseconds'));

        $this->artisan('sso:push-master-data', ['entity' => 'qualifications'])
            ->expectsOutputToContain('did not answer qualifications/import for company 70 within 120s; nothing was changed locally')
            ->assertFailed();

        $this->assertNull(DB::table('qualifications')->where('id', $medic)->value('sso_qualification_id'));
    }

    public function test_an_unknown_entity_is_refused_even_when_disabled(): void
    {
        config(['sso.master_data.qualifications' => false]);
        Http::fake();

        $this->artisan('sso:push-master-data', ['entity' => 'vehicles'])->assertFailed();

        Http::assertNothingSent();
    }
}

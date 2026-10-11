<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\CodeLists;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * CloudPCR, the first app to send its list: sso:push-master-data
 * medications collapses the per-state, per-level tree to one entry per code,
 * SSO adds them to the empty agency list at once, and the mapping links the
 * mirror rows. Nothing in CloudPCR's own tree changes until the flag is on.
 */
class CodeListPushTest extends CodeListsTestCase
{
    private int $companyId;

    private int $newYork;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sso.master_data.medications' => false, 'sso.master_data.procedures' => false]);
        $this->companyId = $this->company(70);
        $this->newYork = $this->configuration($this->companyId, self::NY, [
            self::AEMT => [['4337', 'Fentanyl', true], ['237648', 'D10']],
            self::PARAMEDIC => [['4337', 'Fentanyl (Sublimaze)', true], ['237648', 'D10'], ['99999', 'Unknown thing']],
        ], [
            self::EMT => [['268400002', '12 Lead ECG']],
        ]);
        $this->configuration($this->companyId, self::VT, [
            self::PARAMEDIC => [['4337', 'Fentanyl', true]],
        ], [
            self::EMT => [['268400002', '12 Lead ECG']],
        ]);
        $this->configuration($this->company(80), self::NY, [self::EMT => [['161', 'Not ours']]]);
    }

    public function test_the_tree_goes_up_as_one_entry_per_code_and_a_direct_add_links_at_once(): void
    {
        Http::fake(['sso.test/api/internal/companies/70/medications/import' => Http::sequence()->push([
            'created' => 2, 'matched' => 0, 'in_review' => [], 'review_id' => null,
            'invalid' => [['local_id' => '99999', 'name' => 'Unknown thing', 'message' => "We couldn't find that code. Add it from the search in Settings."]],
            'partial' => [['local_id' => '237648', 'name' => 'D10', 'fields' => ['levels' => 'These levels are not on your state\'s list of certification levels, so they were left off: 9917001.'], 'dropped' => ['levels' => [self::AEMT]]]],
            'mapping' => [
                '4337' => ['sso_id' => 901, 'updated_at' => '2026-10-11T12:00:00+00:00'],
                '237648' => ['sso_id' => 902, 'updated_at' => '2026-10-11T12:00:00+00:00'],
            ],
        ])->push([
            'created' => 0, 'matched' => 2, 'in_review' => [['local_id' => '99999', 'name' => 'Unknown thing']], 'review_id' => 12, 'invalid' => [],
            'mapping' => ['4337' => ['sso_id' => 901, 'updated_at' => null], '237648' => ['sso_id' => 902, 'updated_at' => null]],
        ])]);

        $this->artisan('sso:push-master-data', ['entity' => 'medications', '--company' => '70'])
            ->expectsOutputToContain('created=2 matched=0 assignments_added=0 conflicts=0 truncated_descriptions=0 unknown_users=0 skipped_local_users_without_sso_id=0 linked=2 link_collisions=0 invalid=1 partial=1 in_review=0')
            ->expectsTable(['Local id', 'Name', 'Not created in SSO because'], [['99999', 'Unknown thing', "We couldn't find that code. Add it from the search in Settings."]])
            ->expectsTable(['Local id', 'Name', 'Field', 'Why', 'Left off'], [['237648', 'D10', 'levels', 'These levels are not on your state\'s list of certification levels, so they were left off: 9917001.', '["9917001"]']])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['app_slug'] === 'cloudpcr' && $request['medications'] === [
            ['local_id' => '4337', 'code' => '4337', 'label' => 'Fentanyl', 'levels' => [self::AEMT, self::PARAMEDIC], 'is_controlled' => true],
            ['local_id' => '237648', 'code' => '237648', 'label' => 'D10', 'levels' => [self::AEMT, self::PARAMEDIC], 'only_states' => [self::NY], 'is_controlled' => false],
            ['local_id' => '99999', 'code' => '99999', 'label' => 'Unknown thing', 'levels' => [self::PARAMEDIC], 'only_states' => [self::NY], 'is_controlled' => false],
        ]);

        $fentanyl = DB::table('sso_medications')->where('sso_medication_id', 901)->first();
        $this->assertSame('4337', $fentanyl->code);
        $this->assertSame($this->companyId, (int) $fentanyl->company_id);
        $this->assertNull($fentanyl->sso_updated_at, 'SSO\'s next delivery or the resync replaces the local copy.');
        $this->assertSame(2, DB::table('sso_medications')->count());
        $this->assertSame([
            self::AEMT => ['4337' => 'Fentanyl', '237648' => 'D10'],
            self::PARAMEDIC => ['4337' => 'Fentanyl (Sublimaze)', '237648' => 'D10', '99999' => 'Unknown thing'],
        ], $this->medicationTree($this->newYork), 'The push changes nothing in the app\'s own list.');

        $this->artisan('sso:push-master-data', ['entity' => 'medications', '--company' => '70'])
            ->expectsOutputToContain('created=0 matched=2')
            ->expectsOutputToContain('1 row waits in the agency\'s review in SSO (review 12, Settings > Medications). They link here when it is finished, or run `php artisan sso:push-master-data medications --company=70 --review=12` afterwards.')
            ->assertSuccessful();
        $this->assertSame(2, DB::table('sso_medications')->count(), 'Sending again links the same rows.');
    }

    public function test_procedures_go_up_the_same_way(): void
    {
        Http::fake(['sso.test/*' => Http::response(['created' => 1, 'matched' => 0, 'in_review' => [], 'review_id' => null, 'invalid' => [], 'mapping' => ['268400002' => ['sso_id' => 951, 'updated_at' => null]]])]);

        $this->artisan('sso:push-master-data', ['entity' => 'procedures'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sso.test/api/internal/companies/70/procedures/import' && $request['procedures'] === [
            ['local_id' => '268400002', 'code' => '268400002', 'label' => '12 Lead ECG', 'levels' => [self::EMT]],
        ]);
        $this->assertSame(951, (int) DB::table('sso_procedures')->where('code', '268400002')->value('sso_procedure_id'));
    }

    public function test_the_finished_review_links_this_apps_rows_even_while_the_entity_is_off(): void
    {
        $this->postWebhook('medication.review_completed', [
            'company' => ['id' => 70],
            'review' => ['id' => 12, 'entity' => 'medication', 'mapping' => [
                'cloudpcr' => [['local_id' => '4337', 'sso_id' => 901], ['local_id' => '99999', 'sso_id' => null]],
                'drug-tracking' => [['local_id' => 'drug-1', 'sso_id' => 901]],
            ]],
        ])->assertOk()->assertJson(['status' => 'ok', 'result' => 'linked', 'applied' => 1, 'left_out' => 1, 'collisions' => 0]);

        $this->assertSame('4337', DB::table('sso_medications')->where('sso_medication_id', 901)->value('code'));
        $this->assertSame(1, DB::table('sso_medications')->count());
    }

    public function test_the_review_option_reads_a_finished_review_from_sso(): void
    {
        Http::fake(['sso.test/api/internal/companies/70/medications/reviews/12' => Http::sequence()
            ->push(['id' => 12, 'status' => 'open', 'completed_at' => null, 'mapping' => []])
            ->push(['id' => 12, 'status' => 'completed', 'completed_at' => '2026-10-11T15:00:00+00:00', 'mapping' => [
                'cloudpcr' => [['local_id' => '4337', 'sso_id' => 901], ['local_id' => '237648', 'sso_id' => 902], ['local_id' => '99999', 'sso_id' => null]],
            ]])]);

        $this->artisan('sso:push-master-data', ['entity' => 'medications', '--company' => '70', '--review' => '12'])
            ->expectsOutputToContain('review 12 is not finished yet')
            ->assertSuccessful();
        $this->assertSame(0, DB::table('sso_medications')->count());

        $this->artisan('sso:push-master-data', ['entity' => 'medications', '--company' => '70', '--review' => '12'])
            ->expectsOutputToContain('review 12 linked=2 left_out=1 link_collisions=0')
            ->expectsOutputToContain('Left out of the list by the agency: 99999')
            ->assertSuccessful();
        $this->assertSame([901, 902], DB::table('sso_medications')->orderBy('sso_medication_id')->pluck('sso_medication_id')->map(fn ($id): int => (int) $id)->all());

        $this->artisan('sso:push-master-data', ['entity' => 'facilities', '--company' => '70', '--review' => '12'])->assertFailed();
    }

    public function test_a_list_longer_than_sso_takes_is_not_sent(): void
    {
        $items = [];
        foreach (range(1, 501) as $code) {
            $items[] = [(string) (100000 + $code), "Drug {$code}"];
        }
        $this->configuration($this->companyId, '09', [self::PARAMEDIC => $items]);
        Http::fake();

        $this->artisan('sso:push-master-data', ['entity' => 'medications', '--company' => '70'])
            ->expectsOutputToContain('SSO takes at most 500 in one push. Nothing was sent.')
            ->assertFailed();

        Http::assertNothingSent();
    }
}

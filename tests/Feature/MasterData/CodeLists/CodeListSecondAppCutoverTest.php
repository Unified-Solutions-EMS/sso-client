<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\CodeLists;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The second app to cut over. CloudPCR already pushed, so the agency's list
 * holds fentanyl (901) and glucose (902). Drug Tracking now sends its stock
 * list: fentanyl matches at once; morphine and ketamine (no code) wait in
 * the agency's review. The agency finishes it (morphine added as stock
 * only, ketamine left out), SSO sends review_completed while Drug
 * Tracking's flag is still off, then the flag goes on and the resync links
 * every product through the projection. Nothing local is lost or invented.
 */
class CodeListSecondAppCutoverTest extends CodeListsTestCase
{
    protected string $shape = 'drug-tracking';

    private int $companyId;

    private int $fentanyl;

    private int $morphine;

    private int $ketamine;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sso.master_data.medications' => false]);
        $this->companyId = $this->company(70);
        $this->fentanyl = $this->drug('Fentanyl citrate 100 mcg/2 mL', '4337', 'II');
        $this->morphine = $this->drug('Morphine 10 mg/mL', '7052', 'II');
        $this->ketamine = $this->drug('Ketamine 500 mg/10 mL', null, 'III');
    }

    private function drug(string $name, ?string $rxcui, ?string $schedule): int
    {
        return (int) DB::table('company_drugs')->insertGetId([
            'company_id' => $this->companyId,
            'name' => $name,
            'rxcui' => $rxcui,
            'is_controlled' => $schedule !== null,
            'dea_schedule' => $schedule,
        ]);
    }

    public function test_the_overlapping_list_goes_to_review_and_links_when_the_agency_finishes(): void
    {
        Http::fake([
            'sso.test/api/internal/companies/70/medications/import' => Http::response([
                'created' => 0,
                'matched' => 1,
                'in_review' => [
                    ['local_id' => "drug-{$this->morphine}", 'name' => 'Morphine 10 mg/mL'],
                    ['local_id' => "drug-{$this->ketamine}", 'name' => 'Ketamine 500 mg/10 mL'],
                ],
                'invalid' => [],
                'review_id' => 12,
                'mapping' => ["drug-{$this->fentanyl}" => ['sso_id' => 901, 'updated_at' => '2026-10-11T12:00:00+00:00']],
            ]),
            'sso.test/api/internal/companies/70/medications' => Http::response([
                'company' => ['id' => 70],
                'medications' => [
                    $this->medicationRecord(901, '4337', 'Fentanyl', [self::PARAMEDIC], ['is_controlled' => true, 'dea_schedule' => 'II']),
                    $this->medicationRecord(902, '237648', 'Glucose', [self::EMT]),
                    $this->medicationRecord(903, '7052', 'Morphine', [], ['display_name' => 'Morphine 10 mg/mL', 'is_controlled' => true, 'dea_schedule' => 'II']),
                ],
            ]),
        ]);

        // 1. Push with the flag off: the overlap maps, the rest waits for the agency.
        $this->artisan('sso:push-master-data', ['entity' => 'medications', '--company' => '70'])
            ->expectsOutputToContain('created=0 matched=1')
            ->expectsOutputToContain('2 rows wait in the agency\'s review in SSO (review 12, Settings > Medications)')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/import') && $request['app_slug'] === 'drug-tracking' && $request['medications'] === [
            ['local_id' => "drug-{$this->fentanyl}", 'code' => '4337', 'label' => 'Fentanyl citrate 100 mcg/2 mL', 'levels' => [], 'is_controlled' => true, 'dea_schedule' => 'II'],
            ['local_id' => "drug-{$this->morphine}", 'code' => '7052', 'label' => 'Morphine 10 mg/mL', 'levels' => [], 'is_controlled' => true, 'dea_schedule' => 'II'],
            ['local_id' => "drug-{$this->ketamine}", 'label' => 'Ketamine 500 mg/10 mL', 'levels' => [], 'is_controlled' => true, 'dea_schedule' => 'III'],
        ]);
        $this->assertSame([901], DB::table('sso_medications')->pluck('sso_medication_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNull(DB::table('company_drugs')->where('id', $this->fentanyl)->value('sso_medication_id'), 'The products link only once the flag is on.');

        // 2. While the review is open, CloudPCR-era deliveries are ignored here.
        $this->postWebhook('medication.created', ['company' => ['id' => 70], 'medication' => $this->medicationRecord(902, '237648', 'Glucose', [self::EMT])])
            ->assertJson(['status' => 'ignored']);

        // 3. The agency finishes the review: morphine stock only, ketamine left out.
        $this->postWebhook('medication.review_completed', [
            'company' => ['id' => 70],
            'review' => ['id' => 12, 'entity' => 'medication', 'mapping' => [
                'cloudpcr' => [],
                'drug-tracking' => [
                    ['local_id' => "drug-{$this->fentanyl}", 'sso_id' => 901],
                    ['local_id' => "drug-{$this->morphine}", 'sso_id' => 903],
                    ['local_id' => "drug-{$this->ketamine}", 'sso_id' => null],
                ],
            ]],
        ])->assertOk()->assertJson(['status' => 'ok', 'result' => 'linked', 'applied' => 2, 'left_out' => 1]);

        $this->assertSame('7052', DB::table('sso_medications')->where('sso_medication_id', 903)->value('code'));
        $this->assertSame(2, DB::table('sso_medications')->count());

        // 4. Flag on, resync: SSO's list wins, the projection links every product it can.
        config(['sso.master_data.medications' => true]);
        $this->artisan('sso:resync-master-data', ['entity' => 'medications'])
            ->expectsOutputToContain('created=1 updated=2 linked=0 unchanged=0 deactivated=0')
            ->assertSuccessful();

        $this->assertSame(901, (int) DB::table('company_drugs')->where('id', $this->fentanyl)->value('sso_medication_id'));
        $this->assertSame(903, (int) DB::table('company_drugs')->where('id', $this->morphine)->value('sso_medication_id'));
        $this->assertNull(DB::table('company_drugs')->where('id', $this->ketamine)->value('sso_medication_id'));
        $this->assertSame(3, DB::table('company_drugs')->count(), 'Nothing local is deleted.');
        $this->assertSame('Morphine 10 mg/mL', DB::table('sso_medications')->where('sso_medication_id', 903)->value('label'));
        $this->assertSame('[]', DB::table('sso_medications')->where('sso_medication_id', 903)->value('levels'), 'Stock only.');

        $this->artisan('sso:resync-master-data', ['entity' => 'medications'])
            ->expectsOutputToContain('created=0 updated=0 linked=0 unchanged=3 deactivated=0')
            ->assertSuccessful();
    }
}

<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\CodeLists;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Medications\MedicationCatalog;

/**
 * medication.* / procedure.* webhooks in CloudPCR's shape: the mirror row,
 * then the projection rebuilds each state configuration's level groups.
 */
class CodeListWebhookTest extends CodeListsTestCase
{
    private int $companyId;

    private int $newYork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->newYork = $this->configuration($this->companyId, self::NY, []);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function deliver(string $event, array $record, string $kind = 'medication'): TestResponse
    {
        return $this->postWebhook($event, ['company' => ['id' => 70], $kind => $record, 'updated_at' => $record['updated_at']]);
    }

    public function test_a_medication_is_mirrored_and_written_into_every_level_group(): void
    {
        $this->deliver('medication.created', $this->medicationRecord(901, '4337', 'Fentanyl', [self::AEMT, self::PARAMEDIC], [
            'display_name' => 'Fentanyl citrate', 'is_controlled' => true, 'dea_schedule' => 'II', 'ingredient_rxcui' => '4337',
        ]))->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $row = DB::table('sso_medications')->where('sso_medication_id', 901)->first();
        $this->assertSame('4337', $row->code);
        $this->assertSame('Fentanyl', $row->name);
        $this->assertSame('Fentanyl citrate', $row->label);
        $this->assertSame('["9917001","9917007"]', $row->levels);
        $this->assertTrue((bool) $row->is_controlled);
        $this->assertSame('II', $row->dea_schedule);
        $this->assertSame($this->companyId, (int) $row->company_id);

        $this->assertSame([
            self::AEMT => ['4337' => 'Fentanyl citrate'],
            self::PARAMEDIC => ['4337' => 'Fentanyl citrate'],
        ], $this->medicationTree($this->newYork));
        $this->assertTrue((bool) DB::table('dem_configuration_medication_items')->where('dconfiguration_09', '4337')->value('is_controlled'));
        $this->assertSame('9924003', DB::table('dem_configuration_medication_items')->where('dconfiguration_09', '4337')->value('code_type'));
    }

    public function test_stock_only_and_turned_off_rows_stay_in_the_mirror_but_out_of_the_chart_tree(): void
    {
        $this->deliver('medication.created', $this->medicationRecord(901, '4337', 'Fentanyl', [self::PARAMEDIC]));
        $this->deliver('medication.created', $this->medicationRecord(903, '7052', 'Morphine', []));

        $this->assertSame([self::PARAMEDIC => ['4337' => 'Fentanyl']], $this->medicationTree($this->newYork));
        $this->assertCount(2, MedicationCatalog::usableForCompany($this->companyId));
        $this->assertSame(['4337'], array_column(MedicationCatalog::chartableForCompany($this->companyId), 'code'));

        $this->deliver('medication.deactivated', $this->medicationRecord(901, '4337', 'Fentanyl', [self::PARAMEDIC], ['is_active' => false, 'updated_at' => '2026-10-11T13:00:00+00:00']))
            ->assertJson(['result' => 'updated']);

        $this->assertSame([], $this->medicationTree($this->newYork));
        $this->assertSame(2, DB::table('sso_medications')->count(), 'Turned off, never deleted.');
        $this->assertFalse((bool) DB::table('sso_medications')->where('sso_medication_id', 901)->value('is_active'));
    }

    public function test_only_report_in_writes_a_code_into_the_named_states_only(): void
    {
        $vermont = $this->configuration($this->companyId, self::VT, []);

        $this->deliver('medication.created', $this->medicationRecord(901, '4337', 'Fentanyl', [self::PARAMEDIC]));
        $this->deliver('medication.created', $this->medicationRecord(902, '237648', 'Glucose', [self::EMT], ['only_states' => [self::NY]]));

        $this->assertSame([self::PARAMEDIC => ['4337' => 'Fentanyl'], self::EMT => ['237648' => 'Glucose']], $this->medicationTree($this->newYork));
        $this->assertSame([self::PARAMEDIC => ['4337' => 'Fentanyl']], $this->medicationTree($vermont));
        $this->assertSame(['4337'], array_column(MedicationCatalog::chartableForCompany($this->companyId, self::VT), 'code'));
    }

    public function test_an_unchanged_delivery_leaves_the_tree_alone_and_an_older_one_is_ignored(): void
    {
        $this->deliver('medication.created', $this->medicationRecord(901, '4337', 'Fentanyl', [self::PARAMEDIC], ['updated_at' => '2026-10-11T13:00:00+00:00']));
        $groupIds = DB::table('dem_configuration_medication_groups')->pluck('id')->all();

        $this->deliver('medication.updated', $this->medicationRecord(901, '4337', 'Fentanyl', [self::PARAMEDIC], ['updated_at' => '2026-10-11T13:00:00+00:00']))
            ->assertJson(['result' => 'unchanged']);
        $this->deliver('medication.updated', $this->medicationRecord(901, '4337', 'Fentanyl', [self::EMT], ['updated_at' => '2026-10-11T12:00:00+00:00']))
            ->assertJson(['status' => 'stale']);

        $this->assertSame($groupIds, DB::table('dem_configuration_medication_groups')->pluck('id')->all());
        $this->assertSame([self::PARAMEDIC => ['4337' => 'Fentanyl']], $this->medicationTree($this->newYork));
    }

    public function test_a_batch_from_a_finished_review_adds_rows_and_rebuilds_once(): void
    {
        $this->postWebhook('medication.batch_updated', [
            'company' => ['id' => 70],
            'medications' => [
                $this->medicationRecord(901, '4337', 'Fentanyl', [self::PARAMEDIC]),
                $this->medicationRecord(903, '7052', 'Morphine', [], ['is_controlled' => true, 'dea_schedule' => 'II']),
            ],
            'updated_at' => '2026-10-11T12:00:00+00:00',
        ])->assertOk()->assertJson(['status' => 'ok', 'counts' => ['created' => 2]]);

        $this->assertSame([self::PARAMEDIC => ['4337' => 'Fentanyl']], $this->medicationTree($this->newYork), 'Drug Tracking\'s stock-only morphine never reaches the chart.');
        $this->assertSame(2, DB::table('sso_medications')->count());
    }

    public function test_a_review_for_another_app_links_nothing_here(): void
    {
        $this->postWebhook('medication.review_completed', [
            'company' => ['id' => 70],
            'review' => ['id' => 7, 'entity' => 'medication', 'mapping' => ['drug-tracking' => [['local_id' => 'drug-1', 'sso_id' => 901]]]],
        ])->assertOk()->assertJson(['status' => 'ok', 'result' => 'not_in_review']);

        $this->assertSame(0, DB::table('sso_medications')->count());
    }

    public function test_procedures_land_in_the_procedure_groups(): void
    {
        $this->deliver('procedure.created', $this->procedureRecord(951, '268400002', 'ECG, 12 lead', [self::EMT, self::PARAMEDIC], ['display_name' => '12 Lead ECG']), 'procedure')
            ->assertOk()->assertJson(['result' => 'created']);

        $this->assertSame('12 Lead ECG', DB::table('sso_procedures')->where('sso_procedure_id', 951)->value('label'));
        $this->assertSame(
            [[self::EMT, '268400002', '12 Lead ECG', '9924005'], [self::PARAMEDIC, '268400002', '12 Lead ECG', '9924005']],
            DB::table('dem_configuration_procedure_items')
                ->join('dem_configuration_procedure_groups', 'dem_configuration_procedure_groups.id', '=', 'dem_configuration_procedure_items.dem_configuration_procedure_group_id')
                ->orderBy('dem_configuration_procedure_groups.sort_order')
                ->get(['dconfiguration_06', 'dconfiguration_07', 'name', 'code_type'])
                ->map(fn (object $item): array => array_values((array) $item))
                ->all(),
        );
        $this->assertSame(0, DB::table('dem_configuration_medication_items')->count());
    }

    public function test_events_are_ignored_while_the_entity_is_off(): void
    {
        config(['sso.master_data.medications' => false]);

        $this->deliver('medication.created', $this->medicationRecord(901, '4337', 'Fentanyl', [self::PARAMEDIC]))
            ->assertJson(['status' => 'ignored', 'reason' => 'master_data.medications disabled']);
        $this->assertSame(0, DB::table('sso_medications')->count());
    }
}

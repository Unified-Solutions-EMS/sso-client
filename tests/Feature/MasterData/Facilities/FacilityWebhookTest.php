<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Facilities;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Facilities\FacilityCatalog;

/**
 * facility.* webhooks into CloudPCR's shape: an entry under a per-company
 * type group, phones (and SSO's fax) as child rows, ids kept for life.
 */
class FacilityWebhookTest extends FacilitiesTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    public function test_a_new_facility_lands_under_its_type_group_with_phones_and_fax(): void
    {
        $this->deliver('facility.created', 70, $this->facilityRecord(601, 'Adirondack Medical Center', [
            'location_code' => 'AMC-01',
            'designations' => ['9908007', '9908021'],
            'npis' => ['1234567893'],
            'fax' => '+15188910000',
            'phones' => [['id' => 1, 'number' => '+15188914141', 'type' => '9913009']],
        ]))->assertOk()->assertJson(['status' => 'ok', 'result' => 'created']);

        $entry = $this->mirrored(601);
        $this->assertTrue(Schema::hasIndex('dem_facility_entries', 'dem_facility_entries_sso_facility_unique'), 'Entries have no company_id: the SSO link is unique on its own.');
        $this->assertSame('Adirondack Medical Center', $entry->dfacility_02);
        $this->assertSame('AMC-01', $entry->dfacility_03);
        $this->assertSame(['9908007', '9908021'], json_decode($entry->dfacility_04, true));
        $this->assertSame(['1234567893'], json_decode($entry->dfacility_05, true));
        $this->assertSame('978016', $entry->dfacility_08);
        $this->assertSame('Saranac Lake', $entry->mailing_city);
        $this->assertSame('44.3247,-74.1313', $entry->dfacility_13);
        $this->assertTrue((bool) $entry->is_active);
        $this->assertSame('2026-10-11 12:00:00', $entry->sso_updated_at);
        $this->assertSame('1701005', DB::table('dem_facilities')->where('id', $entry->dem_facility_id)->value('dfacility_01'));
        $this->assertSame($this->companyId, (int) DB::table('dem_facilities')->where('id', $entry->dem_facility_id)->value('company_id'));
        $this->assertSame(
            [['+15188914141', '9913009'], ['+15188910000', '9913001']],
            DB::table('dem_facility_entry_phones')->where('dem_facility_entry_id', $entry->id)->orderBy('sort_order')->get()->map(fn (object $phone): array => [$phone->phone_number, $phone->phone_type])->all(),
        );
    }

    public function test_an_update_keeps_the_entry_id_and_a_type_change_moves_it_to_another_group(): void
    {
        $this->deliver('facility.created', 70, $this->facilityRecord(601, 'Adirondack Medical Center'));
        $id = (int) $this->mirrored(601)->id;

        $this->deliver('facility.updated', 70, $this->facilityRecord(601, 'Adirondack Health', [
            'type' => '1701007',
            'phones' => [['id' => 1, 'number' => '+15188914141', 'type' => null]],
            'updated_at' => '2026-10-11T13:00:00+00:00',
        ]))->assertJson(['result' => 'updated']);

        $entry = $this->mirrored(601);
        $this->assertSame($id, (int) $entry->id);
        $this->assertSame('Adirondack Health', $entry->dfacility_02);
        $this->assertSame('1701007', DB::table('dem_facilities')->where('id', $entry->dem_facility_id)->value('dfacility_01'));
        $this->assertSame(2, DB::table('dem_facilities')->where('company_id', $this->companyId)->count(), 'The old group stays; nothing is deleted.');
        $this->assertSame(1, DB::table('dem_facility_entry_phones')->where('dem_facility_entry_id', $id)->count());
    }

    public function test_turning_off_never_deletes_and_an_older_delivery_is_ignored(): void
    {
        $this->deliver('facility.created', 70, $this->facilityRecord(601, 'Adirondack Medical Center', ['updated_at' => '2026-10-11T13:00:00+00:00']));

        $this->deliver('facility.updated', 70, $this->facilityRecord(601, 'Old name', ['updated_at' => '2026-10-11T12:00:00+00:00']))
            ->assertJson(['status' => 'stale']);
        $this->assertSame('Adirondack Medical Center', $this->mirrored(601)->dfacility_02);

        $this->deliver('facility.deactivated', 70, $this->facilityRecord(601, 'Adirondack Medical Center', ['is_active' => false, 'updated_at' => '2026-10-11T14:00:00+00:00']))
            ->assertJson(['result' => 'updated']);

        $this->assertFalse((bool) $this->mirrored(601)->is_active);
        $this->assertSame(1, DB::table('dem_facility_entries')->count());
        $this->assertCount(0, FacilityCatalog::usableForCompany($this->companyId));
    }

    public function test_a_delivery_adopts_a_local_entry_by_code_then_by_name_and_zip_and_leaves_it_pending(): void
    {
        $byCode = $this->localFacility($this->companyId, 'AMC', ['dfacility_03' => 'amc-01', 'dfacility_07' => 'Local street']);
        $byNameZip = $this->localFacility($this->companyId, 'Mercy Hospital', ['dfacility_10' => '12983']);
        $nameOnly = $this->localFacility($this->companyId, 'St. Mary', ['dfacility_10' => '10001']);

        $this->deliver('facility.created', 70, $this->facilityRecord(601, 'Adirondack Medical Center', ['location_code' => 'AMC-01']))->assertJson(['result' => 'linked']);
        $this->deliver('facility.created', 70, $this->facilityRecord(602, 'MERCY HOSPITAL'))->assertJson(['result' => 'linked']);
        $this->deliver('facility.created', 70, $this->facilityRecord(603, 'St. Mary'))->assertJson(['result' => 'created']);

        $this->assertSame($byCode, (int) $this->mirrored(601)->id);
        $this->assertSame('Local street', $this->mirrored(601)->dfacility_07, 'A pending adoption keeps the agency\'s fields.');
        $this->assertTrue((bool) $this->mirrored(601)->sso_link_pending);
        $this->assertSame($byNameZip, (int) $this->mirrored(602)->id);
        $this->assertNotSame($nameOnly, (int) $this->mirrored(603)->id, 'Same name in another ZIP is another facility.');

        $this->deliver('facility.updated', 70, $this->facilityRecord(601, 'Adirondack Medical Center', ['location_code' => 'AMC-01', 'updated_at' => '2026-10-11T13:00:00+00:00']))
            ->assertJson(['result' => 'pending']);
    }

    public function test_the_state_list_batch_upserts_every_row(): void
    {
        $this->postWebhook('facility.batch_updated', [
            'company' => ['id' => 70],
            'facilities' => [
                $this->facilityRecord(601, 'Adirondack Medical Center'),
                $this->facilityRecord(602, 'Alice Hyde Medical Center', ['address' => ['zip' => '12953', 'state' => '36']]),
            ],
            'updated_at' => '2026-10-11T12:00:00+00:00',
        ])->assertOk()->assertJson(['status' => 'ok', 'counts' => ['created' => 2]]);

        $this->assertSame(['Adirondack Medical Center', 'Alice Hyde Medical Center'], FacilityCatalog::usableForCompany($this->companyId)->pluck('dfacility_02')->sort()->values()->all());
    }

    public function test_another_companys_entries_are_never_touched(): void
    {
        $other = $this->company(80);
        $theirs = $this->localFacility($other, 'Adirondack Medical Center', ['dfacility_03' => 'AMC-01']);

        $this->deliver('facility.created', 70, $this->facilityRecord(601, 'Adirondack Medical Center', ['location_code' => 'AMC-01']))->assertJson(['result' => 'created']);

        $this->assertNull(DB::table('dem_facility_entries')->where('id', $theirs)->value('sso_facility_id'));
        $this->assertCount(1, FacilityCatalog::usableForCompany($other));
        $this->assertCount(1, FacilityCatalog::usableForCompany($this->companyId));
    }

    public function test_a_disabled_entity_ignores_facility_events(): void
    {
        config(['sso.master_data.facilities' => false]);

        $this->deliver('facility.created', 70, $this->facilityRecord(601, 'Adirondack Medical Center'))
            ->assertJson(['status' => 'ignored', 'reason' => 'master_data.facilities disabled']);
        $this->assertSame(0, DB::table('dem_facility_entries')->count());
    }
}

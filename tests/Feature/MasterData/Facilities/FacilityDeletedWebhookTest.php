<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Facilities;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Facilities\FacilityMirror;
use Unified\SsoClient\MasterData\Facilities\FacilityProjection;
use Unified\SsoClient\Tests\Stubs\DeletingDemFacilityProjection;

/**
 * facility.deleted: SSO removed facilities nothing referenced. The app's
 * copy is deleted only when its projection confirms nothing here references
 * it; otherwise it is turned off and unlinked, never deleted.
 */
class FacilityDeletedWebhookTest extends FacilitiesTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
        $this->deliver('facility.created', 70, $this->facilityRecord(601, 'Adirondack Medical Center'))->assertOk();
        $this->deliver('facility.created', 70, $this->facilityRecord(602, 'Alice Hyde Medical Center'))->assertOk();
    }

    protected function tearDown(): void
    {
        DeletingDemFacilityProjection::$referenced = [];

        parent::tearDown();
    }

    public function test_without_delete_support_the_rows_are_turned_off_and_unlinked(): void
    {
        $id = (int) $this->mirrored(601)->id;

        $this->sendDeleted(70, [601])->assertOk()->assertJson(['status' => 'ok', 'deleted' => 0, 'turned_off' => 1]);

        $row = DB::table('dem_facility_entries')->where('id', $id)->first();
        $this->assertNotNull($row, 'Never deleted without the projection confirming it.');
        $this->assertFalse((bool) $row->is_active);
        $this->assertNull($row->sso_facility_id);
        $this->assertTrue((bool) $this->mirrored(602)->is_active, 'Only the named facility changes.');
    }

    public function test_a_projection_that_deletes_removes_only_unreferenced_rows(): void
    {
        $this->app->bind(FacilityProjection::class, DeletingDemFacilityProjection::class);
        $this->app->forgetInstance(FacilityMirror::class);
        $kept = (int) $this->mirrored(602)->id;
        $gone = (int) $this->mirrored(601)->id;
        DeletingDemFacilityProjection::$referenced = [$kept];

        $this->sendDeleted(70, [601, 602])->assertOk()->assertJson(['deleted' => 1, 'turned_off' => 1]);

        $this->assertNull(DB::table('dem_facility_entries')->where('id', $gone)->first());
        $this->assertSame(0, DB::table('dem_facility_entry_phones')->where('dem_facility_entry_id', $gone)->count());
        $row = DB::table('dem_facility_entries')->where('id', $kept)->first();
        $this->assertFalse((bool) $row->is_active);
        $this->assertNull($row->sso_facility_id);
    }

    public function test_another_companys_rows_are_never_touched(): void
    {
        $this->company(80);

        $this->sendDeleted(80, [601])->assertOk()->assertJson(['deleted' => 0, 'turned_off' => 0]);

        $this->assertTrue((bool) $this->mirrored(601)->is_active);
    }

    public function test_a_disabled_entity_ignores_the_event(): void
    {
        config(['sso.master_data.facilities' => false]);

        $this->sendDeleted(70, [601])->assertJson(['status' => 'ignored']);

        $this->assertTrue((bool) $this->mirrored(601)->is_active);
    }

    /**
     * @param  list<int>  $ids
     */
    private function sendDeleted(int $ssoCompanyId, array $ids): TestResponse
    {
        return $this->postWebhook('facility.deleted', [
            'company' => ['id' => $ssoCompanyId, 'division_label' => 'division'],
            'ids' => $ids,
            'facilities' => array_map(fn (int $id): array => ['id' => $id, 'uuid' => null, 'name' => 'x', 'location_code' => null], $ids),
            'deleted_at' => now()->toIso8601String(),
        ]);
    }
}

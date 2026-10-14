<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Facilities;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * The default projection: an app with no facility table gets the standard
 * `facilities` table from the migration and mirrors the contract's own
 * column names.
 */
class FacilityStandardShapeTest extends FacilitiesTestCase
{
    protected string $shape = 'standard';

    public function test_the_migration_creates_the_standard_table_and_is_safe_to_run_twice(): void
    {
        $this->runPublishedMirrorMigration();

        $this->assertTrue(Schema::hasColumns('facilities', ['company_id', 'name', 'type', 'location_code', 'designations', 'address_zip', 'sso_facility_id', 'is_active', 'sort_order', 'sso_updated_at', 'sso_link_pending']));
        $this->assertTrue(Schema::hasIndex('facilities', 'facilities_sso_facility_unique'));
    }

    public function test_webhook_resync_and_push_use_the_contract_columns(): void
    {
        $companyId = $this->company(70);

        $this->deliver('facility.created', 70, $this->facilityRecord(601, 'Adirondack Medical Center', [
            'location_code' => 'AMC-01', 'designations' => ['9908007'], 'fax' => '+15188910000',
            'registry' => ['state' => '36', 'code' => '0001', 'synced_at' => '2026-10-01T00:00:00+00:00'],
        ]))->assertJson(['result' => 'created']);

        $row = DB::table('facilities')->where('sso_facility_id', 601)->first();
        $this->assertSame($companyId, (int) $row->company_id);
        $this->assertSame('AMC-01', $row->location_code);
        $this->assertSame('["9908007"]', $row->designations);
        $this->assertSame('12983', $row->address_zip);
        $this->assertSame('0001', $row->registry_code);

        $this->deliver('facility.updated', 70, $this->facilityRecord(601, 'Adirondack Medical Center', [
            'location_code' => 'AMC-01', 'designations' => ['9908007'], 'fax' => '+15188910000',
            'registry' => ['state' => '36', 'code' => '0001', 'synced_at' => '2026-10-01T00:00:00+00:00'],
        ]))->assertJson(['result' => 'unchanged']);

        config(['sso.master_data.facilities' => false]);
        $local = (int) DB::table('facilities')->insertGetId(['company_id' => $companyId, 'name' => 'Clinic', 'address_zip' => '12983', 'npis' => '["1234567893"]']);
        Http::fake(['sso.test/*' => Http::response(['created' => 1, 'matched' => 1, 'mapping' => [(string) $local => ['sso_id' => 602, 'updated_at' => null]]])]);

        $this->artisan('sso:push-master-data', ['entity' => 'facilities'])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['facilities'][1] === [
            'local_id' => $local, 'name' => 'Clinic', 'is_active' => true, 'npis' => ['1234567893'], 'address' => ['zip' => '12983'],
        ] && ! array_key_exists('registry', $request['facilities'][0]));
        $this->assertSame(602, (int) DB::table('facilities')->where('id', $local)->value('sso_facility_id'));
    }
}

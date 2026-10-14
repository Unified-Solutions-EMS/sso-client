<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Support\Facades\DB;

/**
 * CloudPCR's dem_personnel children are a pure copy of SSO's lists and
 * declare `delete`: a licence SSO removed leaves the table (and the DEM
 * export).
 */
class PersonnelChildRemovalCloudPcrTest extends PersonnelTestCase
{
    protected string $shape = 'cloudpcr';

    public function test_a_pure_mirror_table_deletes_the_removed_child(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        $this->deliver($this->person(70, 9001))->assertOk();
        $rowId = (int) $this->demRow($companyId, 9001)->id;
        $this->assertSame(1, DB::table('dem_personnel_certifications')->where('dem_personnel_id', $rowId)->count());

        $this->deliver($this->person(70, 9001, ['licenses' => [], 'updated_at' => '2026-10-13T13:00:00+00:00']))->assertOk()->assertJson(['result' => 'updated']);

        $this->assertSame(0, DB::table('dem_personnel_certifications')->where('dem_personnel_id', $rowId)->count());
    }
}

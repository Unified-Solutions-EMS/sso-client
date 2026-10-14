<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * No projection bound: the migration creates the package's `sso_personnel`
 * read model and the mirror fills it, translating SSO's division through
 * the divisions mirror. Rolling back drops only the link columns.
 */
class PersonnelStandardShapeTest extends PersonnelTestCase
{
    protected string $shape = 'standard';

    public function test_the_read_model_is_created_and_filled(): void
    {
        $companyId = $this->company(70);
        $userId = $this->user(9001);
        $divisionId = (int) DB::table('divisions')->insertGetId(['company_id' => $companyId, 'name' => 'North', 'sso_division_id' => 5]);

        $this->deliver($this->person(70, 9001, ['membership' => ['division_id' => 5]]))->assertOk()->assertJson(['result' => 'created']);

        $row = DB::table('sso_personnel')->where('company_id', $companyId)->sole();
        $this->assertSame([$userId, 9001, 'Jordan', 'Reyes', 'user9001@example.com', '1988-04-12', 'A-17', $divisionId], [
            (int) $row->user_id, (int) $row->sso_user_id, $row->first_name, $row->last_name, $row->email, $row->date_of_birth, $row->agency_personnel_id, (int) $row->division_id,
        ]);
        $this->assertTrue((bool) $row->is_ems_personnel);
        $this->assertSame([['state' => '36', 'number' => '123456', 'level' => '9925007', 'current_since' => '2024-01-01', 'first_issued_on' => '2015-06-01', 'expires_on' => '2027-01-01']], json_decode($row->licenses, true));

        $this->deliver($this->person(70, 9001, ['membership' => ['division_id' => 6], 'updated_at' => '2026-10-13T12:30:00+00:00']))->assertOk();
        $this->assertSame($divisionId, (int) DB::table('sso_personnel')->value('division_id'), 'A division this app has not linked leaves the local value');

        $this->deliver($this->person(70, 9001, ['membership' => ['division_id' => null], 'updated_at' => '2026-10-13T12:40:00+00:00']))->assertOk();
        $this->assertNull(DB::table('sso_personnel')->value('division_id'));
        $this->assertSame('unchanged', $this->deliver($this->person(70, 9001, ['membership' => ['division_id' => null], 'updated_at' => '2026-10-13T12:40:00+00:00']))->json('result'));
    }

    public function test_rolling_back_drops_only_the_link_columns(): void
    {
        (require __DIR__.'/../../../../database/master-data/2026_10_13_000000_create_or_extend_personnel_mirror.php')->down();

        $this->assertTrue(Schema::hasTable('sso_personnel'));
        $this->assertTrue(Schema::hasColumn('sso_personnel', 'is_active'));
        $this->assertFalse(Schema::hasColumn('sso_personnel', 'sso_user_id'));
        $this->assertFalse(Schema::hasColumn('sso_personnel', 'sso_link_pending'));
    }
}

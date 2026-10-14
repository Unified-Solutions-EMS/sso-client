<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Divisions;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\Divisions\DivisionCatalog;
use Unified\SsoClient\MasterData\Divisions\HasMirroredDivision;
use Unified\SsoClient\Tests\Stubs\Models\User;

class DivisionReadSideTest extends DivisionsTestCase
{
    private function userModel(int $id): User
    {
        $model = new class extends User
        {
            use HasMirroredDivision;
        };

        return $model->newQuery()->findOrFail($id);
    }

    public function test_pickers_get_the_companys_active_divisions_in_sso_order(): void
    {
        $companyId = $this->company(70);
        $b = $this->localDivision($companyId, 'Bravo', 502);
        $a = $this->localDivision($companyId, 'Alpha', 501);
        $off = $this->localDivision($companyId, 'Old', 503);
        DB::table('divisions')->where('id', $b)->update(['sort_order' => 1]);
        DB::table('divisions')->where('id', $a)->update(['sort_order' => 2]);
        DB::table('divisions')->where('id', $off)->update(['is_active' => false]);
        $this->localDivision($this->company(80), 'Elsewhere');

        $this->assertSame(['Bravo', 'Alpha'], DivisionCatalog::usableForCompany($companyId)->pluck('name')->all());
    }

    public function test_the_user_trait_answers_for_one_company(): void
    {
        $companyA = $this->company(70);
        $companyB = $this->company(80);
        $north = $this->localDivision($companyA, 'North', 501);
        $userId = $this->user(9001, $companyA, $north);
        $this->member($userId, $companyB);

        $user = $this->userModel($userId);

        $this->assertSame($north, $user->companyDivisionId($companyA));
        $this->assertSame('North', $user->companyDivisionName($companyA));
        $this->assertTrue($user->isInDivision($north, $companyA));
        $this->assertTrue($user->isInDivision(' north ', $companyA));
        $this->assertNull($user->companyDivision($companyB));
        $this->assertFalse($user->isInDivision($north, $companyB));
    }
}

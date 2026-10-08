<?php

namespace Unified\SsoClient\Tests\Feature\MasterData;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\Qualifications\HasMirroredQualifications;
use Unified\SsoClient\MasterData\Qualifications\QualificationCatalog;
use Unified\SsoClient\Tests\Stubs\Models\User;

class HasMirroredQualificationsTest extends MasterDataTestCase
{
    private function userModel(int $id): User
    {
        $model = new class extends User
        {
            use HasMirroredQualifications;
        };

        return $model->newQuery()->findOrFail($id);
    }

    public function test_it_returns_only_active_qualifications_that_apply_to_this_app_in_that_company(): void
    {
        $companyA = $this->company(70, 'Alpha');
        $companyB = $this->company(80, 'Bravo');
        $userId = $this->user(9001);

        $everyApp = $this->localQualification($companyA, 'Paramedic', 501);
        $thisApp = $this->localQualification($companyA, 'Driver', 502);
        $otherApp = $this->localQualification($companyA, 'Billing Coder', 503);
        $inactive = $this->localQualification($companyA, 'Retired', 504);
        $otherCompany = $this->localQualification($companyB, 'Hazmat', 601);
        DB::table('qualifications')->where('id', $thisApp)->update(['applies_to' => '["crew-scheduling"]']);
        DB::table('qualifications')->where('id', $otherApp)->update(['applies_to' => '["billing"]']);
        DB::table('qualifications')->where('id', $inactive)->update(['is_active' => false]);

        foreach ([$everyApp, $thisApp, $otherApp, $inactive] as $id) {
            $this->assign($userId, $companyA, $id);
        }
        $this->assign($userId, $companyB, $otherCompany);

        $user = $this->userModel($userId);

        $this->assertSame(['Driver', 'Paramedic'], $user->companyQualificationNames($companyA));
        $this->assertEqualsCanonicalizing([$everyApp, $thisApp], $user->companyQualificationIds($companyA));
        $this->assertTrue($user->hasQualificationInCompany('paramedic', $companyA));
        $this->assertTrue($user->hasQualificationInCompany($thisApp, $companyA));
        $this->assertFalse($user->hasQualificationInCompany('Billing Coder', $companyA));
        $this->assertFalse($user->hasQualificationInCompany('Hazmat', $companyA));
        $this->assertSame(['Hazmat'], $user->companyQualificationNames($companyB));

        $this->assertSame(['Driver', 'Paramedic'], QualificationCatalog::usableForCompany($companyA)->pluck('name')->all());
    }
}

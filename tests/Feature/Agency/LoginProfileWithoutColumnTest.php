<?php

namespace Unified\SsoClient\Tests\Feature\Agency;

use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Tests\Stubs\Models\Company;

/**
 * An app that has not run the sso-company-profile migration signs users in
 * and acknowledges agency_profile.updated exactly as before.
 */
class LoginProfileWithoutColumnTest extends AgencyProfileTestCase
{
    protected bool $runProfileMigration = false;

    public function test_login_skips_the_profile(): void
    {
        $this->login([['id' => 70, 'name' => 'Acme EMS', 'roles' => ['User'], 'profile' => $this->compactProfile()]]);

        $this->assertFalse(Schema::hasColumn('companies', 'sso_profile'));
        $this->assertSame(1, Company::query()->where('sso_company_id', 70)->count());
    }

    public function test_the_webhook_is_acknowledged(): void
    {
        Company::create(['name' => 'Acme EMS', 'sso_company_id' => 70]);

        $this->deliverProfile(70, $this->fullProfile())
            ->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'agency_profile.updated', 'result' => 'unsupported']);
    }
}

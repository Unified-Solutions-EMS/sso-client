<?php

namespace Unified\SsoClient\Tests\Feature\Agency;

use Unified\SsoClient\Tests\Stubs\Models\Company;

/**
 * companies[].profile on `/api/user`: the compact agency information lands
 * in companies.sso_profile at sign-in, for apps that ran the
 * sso-company-profile migration.
 */
class LoginProfileSyncTest extends AgencyProfileTestCase
{
    public function test_a_new_company_gets_the_profile_flattened(): void
    {
        $this->login([['id' => 70, 'name' => 'Acme EMS', 'roles' => ['User'], 'profile' => $this->compactProfile()]]);

        $profile = $this->storedProfile(70);
        $this->assertSame('1234', $profile['state_agency_id']);
        $this->assertSame('12 Main St', $profile['address_street']);
        $this->assertSame('12983', $profile['address_zip']);
        $this->assertArrayNotHasKey('address', $profile);
        $this->assertNull(Company::query()->where('sso_company_id', 70)->value('sso_profile_updated_at'), 'The compact block carries no timestamp.');
    }

    public function test_an_existing_company_merges_the_block_and_keeps_fields_only_the_webhook_sends(): void
    {
        Company::create(['name' => 'Acme EMS', 'sso_company_id' => 70, 'sso_profile' => json_encode($this->fullProfile(['updated_at' => null])), 'sso_profile_updated_at' => '2026-10-14 12:00:00']);

        $this->login([['id' => 70, 'name' => 'Acme EMS', 'roles' => ['User'], 'profile' => $this->compactProfile(['npi' => '1972583940'])]]);

        $profile = $this->storedProfile(70);
        $this->assertSame('1972583940', $profile['npi']);
        $this->assertSame('123456789', $profile['tax_id']);
        $this->assertSame('PO Box 9', $profile['mailing_street']);
        $this->assertSame('2026-10-14 12:00:00', Company::query()->where('sso_company_id', 70)->value('sso_profile_updated_at'));
    }

    public function test_a_block_older_than_the_stored_copy_is_ignored(): void
    {
        Company::create(['name' => 'Acme EMS', 'sso_company_id' => 70, 'sso_profile' => json_encode(['npi' => '1972583940']), 'sso_profile_updated_at' => '2026-10-14 12:00:00']);

        $this->login([['id' => 70, 'name' => 'Acme EMS', 'roles' => ['User'], 'profile' => $this->compactProfile(), 'profile_updated_at' => '2026-10-14T11:00:00+00:00']]);

        $this->assertSame(['npi' => '1972583940'], $this->storedProfile(70));
    }

    public function test_a_null_profile_changes_nothing(): void
    {
        Company::create(['name' => 'Acme EMS', 'sso_company_id' => 70, 'sso_profile' => json_encode(['npi' => '1972583940'])]);

        $this->login([['id' => 70, 'name' => 'Acme EMS', 'roles' => ['User'], 'profile' => null]]);

        $this->assertSame(['npi' => '1972583940'], $this->storedProfile(70));
    }
}

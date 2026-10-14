<?php

namespace Unified\SsoClient\Tests\Feature\Agency;

use Unified\SsoClient\Agency\AgencyProfile;
use Unified\SsoClient\Concerns\SyncsCompanyProfile;
use Unified\SsoClient\Tests\Stubs\Models\ProfiledCompany;

/**
 * The Company-model read helpers over companies.sso_profile.
 */
class HasSsoProfileTest extends AgencyProfileTestCase
{
    public function test_the_accessors_read_the_stored_profile(): void
    {
        $company = ProfiledCompany::create([
            'name' => 'Acme EMS',
            'sso_company_id' => 70,
            'sso_profile' => AgencyProfile::normalize($this->fullProfile()),
            'sso_profile_updated_at' => '2026-10-14 12:00:00',
        ])->fresh();

        $this->assertSame('1234', $company->ssoProfileValue('state_agency_id'));
        $this->assertSame('fallback', $company->ssoProfileValue('fire_department_id', 'fallback'));
        $this->assertSame('36', $company->agencyProfile()->state());
        $this->assertSame('1234567893', $company->agencyProfile()->npi());
        $this->assertSame('+15185550101', $company->agencyProfile()->fax());
        $this->assertSame('Saranac Lake', $company->agencyProfile()->physicalAddress()['city_name']);
        $this->assertSame('PO Box 9', $company->agencyProfile()->mailingAddress()['street']);
        $this->assertSame('2026-10-14 12:00:00', $company->ssoProfileUpdatedAt()?->format('Y-m-d H:i:s'));
        $this->assertIsArray($company->ssoProfile());
    }

    public function test_mailing_same_as_physical_reads_the_physical_address(): void
    {
        $profile = new AgencyProfile(AgencyProfile::normalize($this->fullProfile(['mailing_same_as_physical' => true])));

        $this->assertSame('12 Main St', $profile->mailingAddress()['street']);
    }

    public function test_a_company_without_a_profile_reads_empty(): void
    {
        $company = ProfiledCompany::create(['name' => 'Acme EMS', 'sso_company_id' => 70])->fresh();

        $this->assertSame([], $company->ssoProfile());
        $this->assertTrue($company->agencyProfile()->isEmpty());
        $this->assertNull($company->agencyProfile()->npi());
        $this->assertNull($company->ssoProfileUpdatedAt());
    }

    public function test_the_login_sync_writes_an_array_through_a_model_that_casts_the_column(): void
    {
        $company = ProfiledCompany::create(['name' => 'Acme EMS', 'sso_company_id' => 70]);

        $sync = new class
        {
            use SyncsCompanyProfile;

            public function apply(ProfiledCompany $company, array $profile): string
            {
                return $this->applyCompanyProfile($company, $profile);
            }
        };

        $this->assertSame('stored', $sync->apply($company, $this->compactProfile()));
        $this->assertSame('A-77', $company->fresh()->agencyProfile()->agencyNumber());
        $this->assertSame('12 Main St', $company->fresh()->ssoProfileValue('address_street'));
    }
}

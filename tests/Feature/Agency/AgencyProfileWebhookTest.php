<?php

namespace Unified\SsoClient\Tests\Feature\Agency;

use Unified\SsoClient\Tests\Stubs\Models\Company;

/**
 * agency_profile.updated: SSO's full record replaces the local copy, older
 * deliveries are ignored, and every outcome is a 200.
 */
class AgencyProfileWebhookTest extends AgencyProfileTestCase
{
    public function test_the_full_record_replaces_the_copy_with_ssos_timestamp(): void
    {
        Company::create(['name' => 'Acme EMS', 'sso_company_id' => 70, 'sso_profile' => json_encode(['legacy_key' => 'x'])]);

        $this->deliverProfile(70, $this->fullProfile())
            ->assertOk()
            ->assertJson(['status' => 'ok', 'result' => 'stored']);

        $profile = $this->storedProfile(70);
        $this->assertSame('123456789', $profile['tax_id']);
        $this->assertSame('9920001', $profile['primary_service_type']);
        $this->assertArrayNotHasKey('legacy_key', $profile);
        $this->assertArrayNotHasKey('updated_at', $profile);
        $this->assertSame('2026-10-14 12:00:00', Company::query()->where('sso_company_id', 70)->value('sso_profile_updated_at'));

        $this->deliverProfile(70, $this->fullProfile())->assertJson(['result' => 'unchanged']);
    }

    public function test_an_older_delivery_is_ignored(): void
    {
        Company::create(['name' => 'Acme EMS', 'sso_company_id' => 70]);

        $this->deliverProfile(70, $this->fullProfile(['npi' => '1972583940', 'updated_at' => '2026-10-14T13:00:00+00:00']))->assertJson(['result' => 'stored']);
        $this->deliverProfile(70, $this->fullProfile(['updated_at' => '2026-10-14T12:30:00+00:00']))
            ->assertOk()
            ->assertJson(['status' => 'stale', 'result' => 'stale']);

        $this->assertSame('1972583940', $this->storedProfile(70)['npi']);
    }

    public function test_the_top_level_timestamp_is_used_when_the_record_has_none(): void
    {
        Company::create(['name' => 'Acme EMS', 'sso_company_id' => 70]);

        $profile = $this->fullProfile();
        unset($profile['updated_at']);

        $this->postWebhook('agency_profile.updated', [
            'company' => ['id' => 70],
            'agency_profile' => $profile,
            'updated_at' => '2026-10-14T15:00:00+00:00',
        ])->assertJson(['result' => 'stored']);

        $this->assertSame('2026-10-14 15:00:00', Company::query()->where('sso_company_id', 70)->value('sso_profile_updated_at'));
    }

    public function test_an_unknown_company_is_acknowledged_and_nothing_is_created(): void
    {
        $this->deliverProfile(999, $this->fullProfile())
            ->assertOk()
            ->assertJson(['status' => 'ok', 'result' => 'company_not_found']);

        $this->assertSame(0, Company::query()->count());
    }

    public function test_another_companys_copy_is_never_touched(): void
    {
        Company::create(['name' => 'Acme EMS', 'sso_company_id' => 70]);
        Company::create(['name' => 'Other EMS', 'sso_company_id' => 80, 'sso_profile' => json_encode(['npi' => '1972583940'])]);

        $this->deliverProfile(70, $this->fullProfile())->assertJson(['result' => 'stored']);

        $this->assertSame(['npi' => '1972583940'], $this->storedProfile(80));
    }
}

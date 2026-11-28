<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Support\Facades\DB;

class ReleaseChannelCommandTest extends ReleaseChannelTestCase
{
    public function test_it_puts_one_agency_on_beta_and_back(): void
    {
        $this->agency(601);
        $this->agency(602);

        $this->artisan('sso:release-channel', ['ssoCompanyId' => '601', 'channel' => 'beta'])
            ->expectsOutputToContain('stable -> beta')
            ->assertSuccessful();

        $this->assertSame('beta', DB::table('companies')->where('sso_company_id', '601')->value('release_channel'));
        $this->assertSame('stable', DB::table('companies')->where('sso_company_id', '602')->value('release_channel'));

        $this->artisan('sso:release-channel', ['ssoCompanyId' => '601', 'channel' => 'STABLE'])->assertSuccessful();

        $this->assertSame('stable', DB::table('companies')->where('sso_company_id', '601')->value('release_channel'));
    }

    public function test_it_warns_when_this_deployment_has_no_beta_address(): void
    {
        config(['sso.release.urls.beta' => null]);
        $this->agency(603);

        $this->artisan('sso:release-channel', ['ssoCompanyId' => '603', 'channel' => 'beta'])
            ->expectsOutputToContain('No beta address')
            ->assertSuccessful();
    }

    public function test_it_refuses_unknown_channels_agencies_and_usage(): void
    {
        $this->agency(604);

        $this->artisan('sso:release-channel', ['ssoCompanyId' => '604', 'channel' => 'alpha'])->assertFailed();
        $this->artisan('sso:release-channel', ['ssoCompanyId' => '999', 'channel' => 'beta'])->assertFailed();
        $this->artisan('sso:release-channel', ['ssoCompanyId' => 'abc', 'channel' => 'beta'])->assertFailed();
        $this->artisan('sso:release-channel')->assertFailed();

        $this->assertSame('stable', DB::table('companies')->where('sso_company_id', '604')->value('release_channel'));
    }

    public function test_it_changes_nothing_when_two_local_rows_share_the_sso_id(): void
    {
        $this->agency(605);
        $this->agency(605);

        $this->artisan('sso:release-channel', ['ssoCompanyId' => '605', 'channel' => 'beta'])->assertFailed();

        $this->assertSame(0, DB::table('companies')->where('release_channel', 'beta')->count());
    }

    public function test_list_shows_the_settings_and_the_agencies_not_on_stable(): void
    {
        $this->agency(606);
        $this->agency(607, 'beta');

        $this->artisan('sso:release-channel', ['--list' => true])
            ->expectsOutputToContain('This deployment: stable')
            ->expectsOutputToContain('beta: https://beta.test')
            ->expectsTable(['Local id', 'SSO id', 'Agency', 'Channel'], [
                [DB::table('companies')->where('sso_company_id', '607')->value('id'), '607', 'Agency 607', 'beta'],
            ])
            ->assertSuccessful();
    }

    public function test_list_with_every_agency_on_stable(): void
    {
        $this->agency(608);

        $this->artisan('sso:release-channel', ['--list' => true])
            ->expectsOutputToContain('Every agency is on stable.')
            ->assertSuccessful();
    }
}

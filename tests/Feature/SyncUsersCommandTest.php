<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\Console\SyncUsersCommand;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;
use Unified\SsoClient\Tests\TestCase;

class SyncUsersCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sso.app_slug' => 'crew-scheduling',
            'sso.company_model' => Company::class,
        ]);

        $this->app->bind(SsoUserSynchronizerContract::class, StubSynchronizer::class);
    }

    private function rosterPayload(int $ssoCompanyId): array
    {
        $company = ['id' => $ssoCompanyId, 'name' => 'Acme', 'roles' => ['User']];

        return [
            ['user' => ['id' => 9001, 'email' => 'a@acme.com', 'displayName' => 'Aaron A'], 'companies' => [$company], 'selectedCompany' => $company, 'staffRoles' => []],
            ['user' => ['id' => 9002, 'email' => 'b@acme.com', 'displayName' => 'Bea B'], 'companies' => [$company], 'selectedCompany' => $company, 'staffRoles' => []],
        ];
    }

    public function test_it_reconciles_every_roster_member_into_the_local_company(): void
    {
        $local = Company::create(['name' => 'Acme', 'sso_company_id' => '15']);

        $this->mock(SsoClient::class, function ($mock) {
            $mock->shouldReceive('fetchCompanyRoster')->with('15', 'crew-scheduling')->once()->andReturn($this->rosterPayload(15));
        });

        $this->artisan(SyncUsersCommand::class)->assertSuccessful();

        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame(2, DB::table('company_user')->where('company_id', $local->id)->count());
    }

    public function test_a_company_without_an_sso_id_is_skipped(): void
    {
        Company::create(['name' => 'Local Only', 'sso_company_id' => null]);

        $this->mock(SsoClient::class, function ($mock) {
            $mock->shouldNotReceive('fetchCompanyRoster');
        });

        $this->artisan(SyncUsersCommand::class)->assertSuccessful();

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_a_roster_fetch_failure_does_not_abort_the_run(): void
    {
        Company::create(['name' => 'Acme', 'sso_company_id' => '15']);
        Company::create(['name' => 'Beta', 'sso_company_id' => '16']);

        $this->mock(SsoClient::class, function ($mock) {
            $mock->shouldReceive('fetchCompanyRoster')->with('15', 'crew-scheduling')->andThrow(new \RuntimeException('boom'));
            $mock->shouldReceive('fetchCompanyRoster')->with('16', 'crew-scheduling')->andReturn($this->rosterPayload(16));
        });

        $this->artisan(SyncUsersCommand::class)->assertSuccessful();

        // The failing company is skipped; the healthy one still reconciles.
        $this->assertSame(2, DB::table('users')->count());
    }
}

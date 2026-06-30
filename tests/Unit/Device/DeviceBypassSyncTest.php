<?php

namespace Unified\SsoClient\Tests\Unit\Device;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\Tests\Stubs\Models\BypassUser;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;
use Unified\SsoClient\Tests\TestCase;

class DeviceBypassSyncTest extends TestCase
{
    private function sync(array $companies): array
    {
        return (new StubSynchronizer)->synchronize([
            'user' => ['id' => 5001, 'email' => 'medic@example.com', 'displayName' => 'Jordan Medic', 'username' => 'jmedic'],
            'companies' => $companies,
            'selectedCompany' => ['id' => $companies[0]['id'] ?? null],
        ]);
    }

    public function test_it_mirrors_a_company_wide_bypass(): void
    {
        [$user] = $this->sync([
            ['id' => 70, 'name' => 'Acme EMS', 'roles' => ['User'], 'deviceBypass' => ['*']],
        ]);

        $company = Company::where('sso_company_id', 70)->first();

        $this->assertDatabaseHas('sso_device_bypasses', [
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
        $this->assertSame(['*'], json_decode(DB::table('sso_device_bypasses')->where('user_id', $user->id)->value('app_slugs'), true));
    }

    public function test_an_empty_bypass_clears_a_previous_one(): void
    {
        $this->sync([['id' => 70, 'name' => 'Acme EMS', 'roles' => ['User'], 'deviceBypass' => ['*']]]);
        [$user] = $this->sync([['id' => 70, 'name' => 'Acme EMS', 'roles' => ['User'], 'deviceBypass' => []]]);

        $this->assertDatabaseMissing('sso_device_bypasses', ['user_id' => $user->id]);
    }

    public function test_has_device_bypass_matches_wildcard_and_app_slug(): void
    {
        config(['sso.app_slug' => 'cloudpcr']);

        DB::table('sso_device_bypasses')->insert([
            ['user_id' => 1, 'company_id' => 70, 'app_slugs' => json_encode(['*']), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 2, 'company_id' => 70, 'app_slugs' => json_encode(['cloudpcr']), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 3, 'company_id' => 70, 'app_slugs' => json_encode(['cad']), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $wildcard = (new BypassUser)->forceFill(['id' => 1]);
        $appScoped = (new BypassUser)->forceFill(['id' => 2]);
        $otherApp = (new BypassUser)->forceFill(['id' => 3]);
        $none = (new BypassUser)->forceFill(['id' => 4]);

        $this->assertTrue($wildcard->hasDeviceBypass(70));
        $this->assertTrue($appScoped->hasDeviceBypass(70));
        $this->assertFalse($otherApp->hasDeviceBypass(70));
        $this->assertFalse($none->hasDeviceBypass(70));
        $this->assertTrue($otherApp->hasDeviceBypass(70, 'cad'));
    }
}

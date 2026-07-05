<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Concerns\SyncsCompanyRoles;
use Unified\SsoClient\Device\DeviceGuard;
use Unified\SsoClient\Device\DeviceSessionState;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\TestCase;

class DeviceLockMiddlewareTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('auth.providers.users.model', DeviceTestUser::class);
        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.core_api_key', 'test-core-key');
        $app['config']->set('sso.app_slug', 'cloudpcr');
        $app['config']->set('sso.company_model', Company::class);
        $app['config']->set('sso.device.enabled', true);
        $app['config']->set('sso.device.session_ttl', 1800);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('sso_session_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action', 32);
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    protected function defineRoutes($router): void
    {
        $router->middleware([StartSession::class, 'sso.device-lock'])
            ->get('/protected', fn () => response('OK'));
    }

    private function fakePolicy(array $policy): void
    {
        Http::fake([
            'https://sso.test/api/internal/companies/*/device-policy' => Http::response($policy),
        ]);
    }

    private function makeUserAndCompany(): array
    {
        $user = DeviceTestUser::create(['name' => 'Medic', 'email' => 'medic@acme.test']);
        $company = Company::create(['name' => 'Acme EMS', 'sso_company_id' => '70']);
        DB::table('company_user')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $company];
    }

    public function test_it_allows_when_policy_is_off(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        $this->fakePolicy(['mode' => 'off', 'locked_app_slugs' => [], 'grace_until' => null]);

        $this->actingAs($user)->withSession(['selected_company_id' => $company->id])
            ->get('/protected')->assertOk()->assertSee('OK');
    }

    public function test_it_blocks_a_locked_app_without_an_authorized_device(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        $this->fakePolicy(['mode' => 'selected', 'locked_app_slugs' => ['cloudpcr'], 'grace_until' => null]);

        $this->actingAs($user)->withSession(['selected_company_id' => $company->id])
            ->get('/protected')->assertStatus(423)->assertSee('Authorize this device', false);
    }

    public function test_an_xhr_request_gets_423_json_not_html(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        $this->fakePolicy(['mode' => 'all', 'locked_app_slugs' => [], 'grace_until' => null]);

        $this->actingAs($user)->withSession(['selected_company_id' => $company->id])
            ->getJson('/protected')->assertStatus(423)->assertJsonStructure(['message', 'device_authorization_url']);
    }

    public function test_a_user_with_a_bypass_is_allowed(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        DB::table('sso_device_bypasses')->insert([
            'user_id' => $user->id, 'company_id' => $company->id,
            'app_slugs' => json_encode(['*']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakePolicy(['mode' => 'all', 'locked_app_slugs' => [], 'grace_until' => null]);

        $this->actingAs($user)->withSession(['selected_company_id' => $company->id])
            ->get('/protected')->assertOk();
    }

    public function test_a_grace_period_allows_the_request(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        $this->fakePolicy(['mode' => 'all', 'locked_app_slugs' => [], 'grace_until' => now()->addDay()->toIso8601String()]);

        $this->actingAs($user)->withSession(['selected_company_id' => $company->id])
            ->get('/protected')->assertOk();
    }

    public function test_a_session_bound_to_the_company_is_allowed(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        $this->fakePolicy(['mode' => 'all', 'locked_app_slugs' => [], 'grace_until' => null]);

        $binding = ['sso_company_id' => '70', 'device_id' => 1, 'expires_at' => now()->addHour()->timestamp];

        $this->actingAs($user)
            ->withSession(['selected_company_id' => $company->id, DeviceSessionState::KEY_BINDING => $binding])
            ->get('/protected')->assertOk();
    }

    public function test_a_binding_for_a_different_company_does_not_unlock(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        $this->fakePolicy(['mode' => 'all', 'locked_app_slugs' => [], 'grace_until' => null]);

        $binding = ['sso_company_id' => '999', 'device_id' => 1, 'expires_at' => now()->addHour()->timestamp];

        $this->actingAs($user)
            ->withSession(['selected_company_id' => $company->id, DeviceSessionState::KEY_BINDING => $binding])
            ->get('/protected')->assertStatus(423);
    }

    public function test_a_revoked_device_locks_a_previously_bound_session(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        $this->fakePolicy(['mode' => 'all', 'locked_app_slugs' => [], 'grace_until' => null]);

        app(DeviceGuard::class)->markDeviceRevoked(11);

        $binding = ['sso_company_id' => '70', 'device_id' => 11, 'expires_at' => now()->addHour()->timestamp];

        $this->actingAs($user)
            ->withSession(['selected_company_id' => $company->id, DeviceSessionState::KEY_BINDING => $binding])
            ->get('/protected')->assertStatus(423)
            ->assertSessionMissing(DeviceSessionState::KEY_BINDING);
    }
}

class DeviceTestUser extends Authenticatable
{
    use SyncsCompanyRoles;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}

<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Device\DeviceSessionState;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\TestCase;

class DeviceHandshakeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('auth.providers.users.model', HandshakeTestUser::class);
        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.core_api_key', 'test-core-key');
        $app['config']->set('sso.company_model', Company::class);
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

    private function userAndCompany(string $ssoCompanyId = '70'): array
    {
        $user = HandshakeTestUser::create(['name' => 'Medic', 'email' => 'medic@acme.test', 'sso_id' => 'sso-1']);
        $company = Company::create(['name' => 'Acme EMS', 'sso_company_id' => $ssoCompanyId]);
        DB::table('company_user')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $company];
    }

    public function test_verify_binds_the_session_on_success(): void
    {
        [$user, $company] = $this->userAndCompany('70');
        Http::fake([
            'https://sso.test/api/internal/device/verify' => Http::response([
                'verified' => true,
                'device' => ['id' => 11, 'sso_company_id' => '70'],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['selected_company_id' => $company->id])
            ->postJson('/sso/device/verify', ['key_id' => 'k1', 'challenge' => 'c1', 'signature' => 's1']);

        $response->assertOk()->assertJsonPath('verified', true);
        $response->assertSessionHas(DeviceSessionState::KEY_BINDING);
    }

    public function test_verify_rejects_a_device_from_another_company(): void
    {
        [$user, $company] = $this->userAndCompany('70');
        Http::fake([
            'https://sso.test/api/internal/device/verify' => Http::response([
                'verified' => true,
                'device' => ['id' => 11, 'sso_company_id' => '999'],
            ]),
        ]);

        $this->actingAs($user)
            ->withSession(['selected_company_id' => $company->id])
            ->postJson('/sso/device/verify', ['key_id' => 'k1', 'challenge' => 'c1', 'signature' => 's1'])
            ->assertStatus(403)
            ->assertJsonPath('verified', false);
    }

    public function test_register_code_mode_proxies_to_sso(): void
    {
        [$user, $company] = $this->userAndCompany('70');
        Http::fake([
            'https://sso.test/api/internal/device/register' => Http::response(['device' => ['id' => 12, 'sso_company_id' => '70']], 201),
        ]);

        $this->actingAs($user)
            ->withSession(['selected_company_id' => $company->id])
            ->postJson('/sso/device/register', [
                'mode' => 'code', 'code' => 'ABCD-EFGH-IJKL', 'key_id' => 'k1', 'public_key' => 'pk',
            ])
            ->assertStatus(201)
            ->assertJsonPath('device.id', 12);

        Http::assertSent(fn ($request) => $request->url() === 'https://sso.test/api/internal/device/register'
            && $request['mode'] === 'code'
            && $request['code'] === 'ABCD-EFGH-IJKL');
    }

    public function test_challenge_requires_authentication(): void
    {
        $this->postJson('/sso/device/challenge', [])->assertStatus(401);
    }
}

class HandshakeTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}

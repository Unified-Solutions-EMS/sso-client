<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;
use Unified\SsoClient\SsoClient;
use Unified\SsoClient\SsoSessionState;
use Unified\SsoClient\SsoUserSynchronizer;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;
use Unified\SsoClient\Tests\TestCase;

/**
 * An agency sets a person to "No access" for an app in SSO's Settings. SSO
 * then sends that app `appRole: null` and no roles for the company. The
 * package used to read the empty role list as "User" and let them in.
 */
class NoAppAccessTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.name', 'Unified Billing');
        $app['config']->set('auth.providers.users.model', AccessTestUser::class);
        $app['config']->set('sso.base_url', 'https://sso.example.test');
        $app['config']->set('sso.client_id', 'client-1');
        $app['config']->set('sso.client_secret', 'secret');
        $app['config']->set('sso.app_slug', 'billing');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'login')->name('login');
        $router->get('/board', fn () => 'board')->middleware(['web', 'sso.session']);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('users', function (Blueprint $table) {
            $table->rememberToken();
            $table->json('staff_roles')->nullable();
        });

        Schema::create('sso_session_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action', 32);
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function company(int $id, ?string $appRole): array
    {
        return ['id' => $id, 'name' => "Agency {$id}", 'roles' => $appRole !== null ? [$appRole] : [], 'appRole' => $appRole];
    }

    public function test_only_companies_with_a_role_in_this_app_count_as_access(): void
    {
        $companies = [$this->company(10, 'User'), $this->company(20, null)];

        $this->assertSame([10], array_column(SsoUserSynchronizer::companiesWithAppAccess($companies), 'id'));
        $this->assertFalse(SsoUserSynchronizer::deniesAppAccess(['companies' => $companies]));
        $this->assertTrue(SsoUserSynchronizer::deniesAppAccess(['companies' => [$this->company(20, null)]]));
        $this->assertFalse(SsoUserSynchronizer::deniesAppAccess(['companies' => []]), 'no companies at all is not a refusal');
    }

    public function test_a_payload_without_app_roles_keeps_every_company_as_before(): void
    {
        $legacy = [['id' => 30, 'name' => 'Older SSO', 'roles' => []]];

        $this->assertSame([30], array_column(SsoUserSynchronizer::companiesWithAppAccess($legacy), 'id'));
        $this->assertFalse(SsoUserSynchronizer::deniesAppAccess(['companies' => $legacy]));
    }

    public function test_sign_in_drops_the_agency_set_to_no_access_and_removes_the_existing_membership(): void
    {
        $user = User::create(['name' => 'Patrick Boyd', 'email' => 'pboyd@example.test', 'sso_id' => '2874']);
        $kept = Company::create(['name' => 'Agency 10', 'sso_company_id' => '10']);
        $removed = Company::create(['name' => 'Agency 20', 'sso_company_id' => '20']);
        $user->companies()->attach([$kept->id, $removed->id]);
        $role = Role::create(['name' => 'User', 'company_id' => $removed->id]);
        DB::table('company_user_roles')->insert(['company_id' => $removed->id, 'user_id' => $user->id, 'role_id' => $role->id]);

        [$synced, $selected] = (new StubSynchronizer)->synchronize([
            'user' => ['id' => 2874, 'email' => 'pboyd@example.test'],
            'companies' => [$this->company(10, 'User'), $this->company(20, null)],
            'selectedCompany' => ['id' => 20],
        ]);

        $this->assertSame($user->id, $synced->id);
        $this->assertSame($kept->id, $selected->id, 'the agency left with access is selected, never the one without');
        $this->assertSame([$kept->id], DB::table('company_user')->where('user_id', $user->id)->pluck('company_id')->all());
        $this->assertSame(0, DB::table('company_user_roles')->where('user_id', $user->id)->where('company_id', $removed->id)->count());
        $this->assertSame(['User'], DB::table('company_user_roles')->join('roles', 'roles.id', '=', 'company_user_roles.role_id')->where('company_user_roles.user_id', $user->id)->where('company_user_roles.company_id', $kept->id)->pluck('roles.name')->all());
    }

    public function test_sign_in_is_refused_with_a_plain_page_when_the_person_has_no_access_anywhere(): void
    {
        $user = User::create(['name' => 'Patrick Boyd', 'email' => 'pboyd@example.test', 'sso_id' => '2874']);
        $company = Company::create(['name' => 'Agency 20', 'sso_company_id' => '20']);
        $user->companies()->attach($company->id);

        $client = Mockery::mock(SsoClient::class);
        $client->shouldReceive('exchangeCode')->andReturn(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]);
        $client->shouldReceive('fetchUser')->andReturn([
            'user' => ['id' => 2874, 'email' => 'pboyd@example.test'],
            'companies' => [$this->company(20, null)],
        ]);
        $this->app->instance(SsoClient::class, $client);
        $this->app->bind(SsoUserSynchronizerContract::class, StubSynchronizer::class);

        $response = $this->withSession([
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
        ])->get('/auth/sso/callback?state=state-abc&code=auth-code');

        $response->assertForbidden()
            ->assertSee("You don't have access to Unified Billing", false)
            ->assertSee('Ask your agency administrator')
            ->assertSee('https://sso.example.test/dashboard', false);
        $this->assertGuest();
        $this->assertSame(0, DB::table('company_user')->where('user_id', $user->id)->count());
    }

    public function test_a_signed_in_person_whose_access_is_removed_is_signed_out_on_the_next_check(): void
    {
        $user = AccessTestUser::create(['name' => 'Patrick Boyd', 'email' => 'pboyd@example.test', 'sso_id' => '2874']);

        Http::fake([
            'sso.example.test/api/user' => Http::response([
                'user' => ['id' => 2874, 'email' => 'pboyd@example.test'],
                'companies' => [$this->company(20, null)],
            ]),
        ]);

        $response = $this->actingAs($user)->withSession([
            SsoSessionState::KEY_ACCESS_TOKEN => 'live-access',
            SsoSessionState::KEY_REFRESH_TOKEN => 'live-refresh',
            SsoSessionState::KEY_TOKEN_EXPIRES_AT => now()->addHour()->timestamp,
            SsoSessionState::KEY_SSO_USER_ID => '2874',
        ])->get('/board');

        $response->assertForbidden()->assertSee('Unified Billing');
        $this->assertGuest();
    }

    public function test_a_signed_in_person_who_keeps_access_somewhere_stays_signed_in(): void
    {
        $user = AccessTestUser::create(['name' => 'Ann Smith', 'email' => 'ann@example.test', 'sso_id' => '3000']);

        Http::fake([
            'sso.example.test/api/user' => Http::response([
                'user' => ['id' => 3000, 'email' => 'ann@example.test'],
                'companies' => [$this->company(10, 'User'), $this->company(20, null)],
            ]),
        ]);

        $this->actingAs($user)->withSession([
            SsoSessionState::KEY_ACCESS_TOKEN => 'live-access',
            SsoSessionState::KEY_REFRESH_TOKEN => 'live-refresh',
            SsoSessionState::KEY_TOKEN_EXPIRES_AT => now()->addHour()->timestamp,
            SsoSessionState::KEY_SSO_USER_ID => '3000',
        ])->get('/board')->assertOk()->assertSee('board');

        $this->assertAuthenticatedAs($user);
    }

    public function test_staff_are_never_refused_and_keep_every_agency(): void
    {
        $staff = ['companies' => [$this->company(20, null)], 'staffRoles' => ['global-admin']];

        $this->assertFalse(SsoUserSynchronizer::deniesAppAccess($staff));
        $this->assertTrue(SsoUserSynchronizer::isStaff($staff));
        $this->assertFalse(SsoUserSynchronizer::isStaff(['staffRoles' => []]));
        $this->assertFalse(SsoUserSynchronizer::isStaff(['staffRoles' => [null, '']]));
    }

    public function test_staff_sign_in_with_no_app_role_anywhere_and_land_in_their_agency(): void
    {
        $client = Mockery::mock(SsoClient::class);
        $client->shouldReceive('exchangeCode')->andReturn(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]);
        $client->shouldReceive('fetchUser')->andReturn([
            'user' => ['id' => 298, 'email' => 'steve@unified.test', 'displayName' => 'Steve Wilson'],
            'companies' => [$this->company(2, null)],
            'selectedCompany' => ['id' => 2],
            'staffRoles' => ['global-admin'],
        ]);
        $this->app->instance(SsoClient::class, $client);
        $this->app->bind(SsoUserSynchronizerContract::class, AccessTestSynchronizer::class);

        $response = $this->withSession([
            SsoSessionState::KEY_OAUTH_STATE => 'state-abc',
            SsoSessionState::KEY_CODE_VERIFIER => 'verifier-xyz',
        ])->get('/auth/sso/callback?state=state-abc&code=auth-code');

        $response->assertRedirect('/dashboard');
        $user = AccessTestUser::where('sso_id', '298')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $company = Company::where('sso_company_id', '2')->firstOrFail();
        $this->assertSame([$company->id], DB::table('company_user')->where('user_id', $user->id)->pluck('company_id')->all());
        $this->assertSame(['global-admin'], json_decode((string) DB::table('users')->where('id', $user->id)->value('staff_roles'), true));
    }

    public function test_signed_in_staff_with_no_app_role_anywhere_stay_signed_in(): void
    {
        $user = AccessTestUser::create(['name' => 'Steve Wilson', 'email' => 'steve@unified.test', 'sso_id' => '298']);

        Http::fake([
            'sso.example.test/api/user' => Http::response([
                'user' => ['id' => 298, 'email' => 'steve@unified.test'],
                'companies' => [$this->company(2, null)],
                'staffRoles' => ['global-admin'],
            ]),
        ]);

        $this->actingAs($user)->withSession([
            SsoSessionState::KEY_ACCESS_TOKEN => 'live-access',
            SsoSessionState::KEY_REFRESH_TOKEN => 'live-refresh',
            SsoSessionState::KEY_TOKEN_EXPIRES_AT => now()->addHour()->timestamp,
            SsoSessionState::KEY_SSO_USER_ID => '298',
        ])->get('/board')->assertOk()->assertSee('board');

        $this->assertAuthenticatedAs($user);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

class AccessTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user', 'user_id', 'company_id')->withTimestamps();
    }
}

/**
 * The stub synchronizer, but signing in a user the session guard accepts.
 */
class AccessTestSynchronizer extends StubSynchronizer
{
    protected function getUserModelClass(): string
    {
        return AccessTestUser::class;
    }
}

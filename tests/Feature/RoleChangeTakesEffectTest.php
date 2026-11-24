<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Unified\SsoClient\Http\SsoWebhookController;
use Unified\SsoClient\Middleware\EnforceSsoSessionActions;
use Unified\SsoClient\Models\SsoSessionAction;
use Unified\SsoClient\SsoServiceProvider;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\FailingLoaderUser;
use Unified\SsoClient\Tests\Stubs\Models\OwnLoaderUser;
use Unified\SsoClient\Tests\Stubs\Models\RoleSyncingUser;
use Unified\SsoClient\Tests\Stubs\Models\SpatieRole;
use Unified\SsoClient\Tests\Stubs\RoleSyncingWebhookController;
use Unified\SsoClient\Tests\TestCase;

/**
 * SSO changes a signed-in person's role (`user.app_role_changed`). The change
 * must reach Spatie's roles on that person's next request, in every session
 * they have open, without a new sign-in (James, 2026-11-23).
 */
class RoleChangeTakesEffectTest extends TestCase
{
    private const SSO_USER_ID = 7001;

    private const SSO_COMPANY_A = 41;

    private const SSO_COMPANY_B = 42;

    protected function getPackageProviders($app): array
    {
        return [PermissionServiceProvider::class, SsoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('sso.app_slug', 'reporting');
        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.server_url', null);
        $app['config']->set('permission.models.role', SpatieRole::class);
        $app['config']->set('auth.providers.users.model', RoleSyncingUser::class);
        $app->bind(SsoWebhookController::class, RoleSyncingWebhookController::class);
    }

    protected function defineRoutes($router): void
    {
        Route::middleware([EncryptCookies::class, StartSession::class, EnforceSsoSessionActions::class])
            ->get('/probe', fn () => Auth::user()->can('reports.manage') ? 'admin' : 'user');

        Route::middleware([EncryptCookies::class, StartSession::class, EnforceSsoSessionActions::class])
            ->get('/plain', fn () => 'ok');

        Route::middleware([EncryptCookies::class, StartSession::class, EnforceSsoSessionActions::class])
            ->get('/staff', fn () => Auth::user()->isStaff() ? 'staff' : 'agency');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        OwnLoaderUser::$loads = [];
    }

    /**
     * Two companies, each with its own Admin (holds reports.manage) and User
     * role rows, as SSO-synced apps keep them.
     *
     * @return array{0: Company, 1: Company}
     */
    private function seedCompanies(): array
    {
        $permission = Permission::create(['name' => 'reports.manage', 'guard_name' => 'web']);
        $companies = [];

        foreach ([self::SSO_COMPANY_A, self::SSO_COMPANY_B] as $ssoId) {
            $company = Company::create(['name' => "Agency {$ssoId}", 'sso_company_id' => (string) $ssoId]);
            SpatieRole::query()->create(['name' => 'Admin', 'guard_name' => 'web', 'company_id' => $company->id])->givePermissionTo($permission);
            SpatieRole::query()->create(['name' => 'User', 'guard_name' => 'web', 'company_id' => $company->id]);
            $companies[] = $company;
        }

        return $companies;
    }

    /**
     * @param  class-string<RoleSyncingUser>  $model
     * @param  array<int, string>  $rolesByCompanyId  local company id => role name
     */
    private function signedInMember(array $rolesByCompanyId, int $loadedCompanyId, string $model = RoleSyncingUser::class): RoleSyncingUser
    {
        $user = $model::create(['name' => 'Pat Medic', 'email' => 'pat@example.test', 'sso_id' => (string) self::SSO_USER_ID]);

        foreach ($rolesByCompanyId as $companyId => $roleName) {
            $user->companies()->attach($companyId);
            DB::table('company_user_roles')->insert([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'role_id' => SpatieRole::query()->where('company_id', $companyId)->where('name', $roleName)->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user->loadRolesForCompany($loadedCompanyId);

        return $user;
    }

    /**
     * @param  list<string>  $roles
     */
    private function sendRoleChange(int $ssoCompanyId, array $roles): void
    {
        $body = json_encode([
            'event' => 'user.app_role_changed',
            'app_slug' => 'reporting',
            'user' => ['id' => self::SSO_USER_ID, 'email' => 'pat@example.test'],
            'company' => ['id' => $ssoCompanyId],
            'roles' => $roles,
        ]);

        $this->call('POST', '/api/sso/provision', [], [], [], [
            'HTTP_X-SSO-Signature' => hash_hmac('sha256', $body, 'whsec'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertOk();
    }

    /**
     * One browser session: its own session data, the shared database. Laravel
     * Cloud and Vapor run several instances; each request here starts from the
     * session payload alone, exactly as a request landing on another instance.
     *
     * @param  array<string, mixed>  $session
     */
    private function clickAs(RoleSyncingUser $user, array &$session, string $path = '/probe'): TestResponse
    {
        $store = $this->app['session']->driver();
        $store->flush();
        $store->setId(null);
        Auth::forgetGuards();
        $fresh = $user::query()->find($user->id);
        DB::flushQueryLog();

        $response = $this->actingAs($fresh)->withSession($session)->get($path);
        $session = $this->app['session']->driver()->all();

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionFor(Company $company, array $extra = []): array
    {
        return ['selected_company_id' => $company->id] + $extra;
    }

    public function test_a_lowered_role_takes_effect_on_the_next_request_without_a_new_sign_in(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id);
        $session = $this->sessionFor($a);

        $this->clickAs($user, $session)->assertSee('admin');

        $this->sendRoleChange(self::SSO_COMPANY_A, ['User']);

        $this->clickAs($user, $session)->assertSee('user');
        $this->assertAuthenticated();
        $this->assertSame(['User'], RoleSyncingUser::query()->find($user->id)->getRoleNames()->all());
    }

    public function test_a_raised_role_takes_effect_on_the_next_request(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'User'], $a->id);
        $session = $this->sessionFor($a);

        $this->clickAs($user, $session)->assertSee('user');

        $this->sendRoleChange(self::SSO_COMPANY_A, ['Admin']);

        $this->clickAs($user, $session)->assertSee('admin');
    }

    public function test_no_access_for_the_selected_company_leaves_it_through_the_sso_redirect(): void
    {
        [$a, $b] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin', $b->id => 'User'], $a->id);
        $session = $this->sessionFor($a);

        $this->sendRoleChange(self::SSO_COMPANY_A, []);

        $response = $this->clickAs($user, $session);

        $response->assertRedirect('/auth/sso/redirect?intended='.urlencode('http://localhost/probe'));
        $this->assertGuest();
        $this->assertArrayNotHasKey('selected_company_id', $session);
        $this->assertSame(0, DB::table('company_user')->where('company_id', $a->id)->count());

        // Coming back through the callback with the same session must not bounce again.
        $session['selected_company_id'] = $b->id;
        $this->clickAs($user, $session)->assertOk();
    }

    public function test_a_change_for_a_company_not_selected_leaves_the_session_alone(): void
    {
        [$a, $b] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin', $b->id => 'Admin'], $a->id);
        $session = $this->sessionFor($a);

        $this->sendRoleChange(self::SSO_COMPANY_B, ['User']);
        $this->clickAs($user, $session)->assertSee('admin');

        $this->sendRoleChange(self::SSO_COMPANY_B, []);
        $this->clickAs($user, $session)->assertOk()->assertSee('admin');
        $this->assertAuthenticated();
        $this->assertSame($a->id, $session['selected_company_id']);
    }

    public function test_every_open_session_applies_the_change_once(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id);
        $laptop = $this->sessionFor($a);
        $phone = $this->sessionFor($a);

        $this->sendRoleChange(self::SSO_COMPANY_A, ['User']);

        $this->clickAs($user, $laptop)->assertSee('user');

        // Another session is put back to Admin in Spatie's tables (say, it
        // re-hydrated before the change); it must still apply the change.
        RoleSyncingUser::query()->find($user->id)->syncRoles(SpatieRole::query()->where('company_id', $a->id)->where('name', 'Admin')->get());
        $this->clickAs($user, $phone)->assertSee('user');

        $this->assertSame(1, SsoSessionAction::query()->where('action', SsoSessionAction::ACTION_RELOAD_ROLES)->count());
        $this->assertSame($laptop[EnforceSsoSessionActions::SESSION_ROLES_SEEN_ID], $phone[EnforceSsoSessionActions::SESSION_ROLES_SEEN_ID]);
    }

    public function test_a_newer_change_replaces_the_older_one_and_applies_again(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id);
        $session = $this->sessionFor($a);

        $this->sendRoleChange(self::SSO_COMPANY_A, ['User']);
        $this->clickAs($user, $session)->assertSee('user');

        $this->sendRoleChange(self::SSO_COMPANY_A, ['Admin']);
        $this->clickAs($user, $session)->assertSee('admin');

        $this->assertSame(1, SsoSessionAction::query()->where('action', SsoSessionAction::ACTION_RELOAD_ROLES)->count());
    }

    public function test_a_request_with_nothing_pending_runs_no_extra_query(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id);
        $session = $this->sessionFor($a);

        $queries = $this->countQueries(fn () => $this->clickAs($user, $session, '/plain')->assertOk());

        $this->sendRoleChange(self::SSO_COMPANY_A, ['User']);
        $this->clickAs($user, $session, '/plain')->assertOk();

        // The applied row stays for other sessions; this one has seen it.
        $this->assertSame($queries, $this->countQueries(fn () => $this->clickAs($user, $session, '/plain')->assertOk()));
        $this->assertSame(2, $queries, 'only the two session-action reads that ran before this change');
    }

    public function test_the_reload_leaves_the_app_wide_permission_cache_alone(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id);
        $session = $this->sessionFor($a);
        $this->clickAs($user, $session)->assertSee('admin');

        $this->sendRoleChange(self::SSO_COMPANY_A, ['User']);
        $registrar = app(PermissionRegistrar::class);
        $cached = $registrar->getCacheStore()->get($registrar->cacheKey) !== null;

        $this->clickAs($user, $session)->assertSee('user');

        $this->assertTrue($cached);
        $this->assertTrue($registrar->getCacheStore()->get($registrar->cacheKey) !== null);
    }

    public function test_an_app_that_overrides_load_roles_keeps_its_own_loader(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id, OwnLoaderUser::class);
        $this->app['config']->set('auth.providers.users.model', OwnLoaderUser::class);
        $session = $this->sessionFor($a);
        OwnLoaderUser::$loads = [];

        $this->sendRoleChange(self::SSO_COMPANY_A, ['User']);
        $this->clickAs($user, $session)->assertSee('user');

        $this->assertSame([$a->id], OwnLoaderUser::$loads);
    }

    public function test_a_reload_that_fails_lets_the_request_through_and_retries_next_time(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id);
        $session = $this->sessionFor($a);
        $this->sendRoleChange(self::SSO_COMPANY_A, ['User']);

        $failing = FailingLoaderUser::query()->find($user->id);
        $this->clickAs($failing, $session)->assertOk()->assertSee('admin');
        $this->assertAuthenticated();
        $this->assertArrayNotHasKey(EnforceSsoSessionActions::SESSION_ROLES_SEEN_ID, $session);

        $this->clickAs($user, $session)->assertSee('user');
    }

    public function test_the_role_change_still_applies_when_the_session_marker_cannot_be_written(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id);
        Schema::drop('sso_session_actions');

        $this->sendRoleChange(self::SSO_COMPANY_A, ['User']);

        $this->assertSame(
            ['User'],
            DB::table('company_user_roles')->join('roles', 'roles.id', '=', 'company_user_roles.role_id')
                ->where('company_user_roles.user_id', $user->id)->pluck('roles.name')->all(),
        );
    }

    public function test_an_impersonating_session_gets_the_change_and_keeps_the_staff_member(): void
    {
        [$a, $b] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin', $b->id => 'User'], $a->id);
        $session = $this->sessionFor($a, ['impersonator_id' => 9, 'impersonated_company_id' => $a->id]);

        $this->sendRoleChange(self::SSO_COMPANY_A, ['User']);
        $this->clickAs($user, $session)->assertSee('user');
        $this->assertAuthenticatedAs(RoleSyncingUser::query()->find($user->id));
        $this->assertSame(9, $session['impersonator_id']);

        $this->sendRoleChange(self::SSO_COMPANY_A, []);
        $response = $this->clickAs($user, $session);

        // Never SSO's own logout: that would end the staff member's SSO session.
        $response->assertRedirect('/auth/sso/redirect?intended='.urlencode('http://localhost/probe'));
        $this->assertSame(9, $session['impersonator_id']);
        $this->assertArrayNotHasKey('impersonated_company_id', $session);
    }

    /**
     * A full user update as SSO sends it since SSO #336: this app's role per
     * company, companies with No access here left out.
     *
     * @param  array<int, list<string>>  $rolesBySsoCompanyId
     */
    private function sendUserEvent(array $rolesBySsoCompanyId, string $event = 'user.updated', int $ssoUserId = self::SSO_USER_ID, string $email = 'pat@example.test'): void
    {
        $body = json_encode([
            'event' => $event,
            'user' => ['id' => $ssoUserId, 'email' => $email, 'first_name' => 'Pat', 'last_name' => 'Medic'],
            'companies' => array_map(
                fn (int $ssoCompanyId, array $roles): array => ['id' => $ssoCompanyId, 'roles' => $roles],
                array_keys($rolesBySsoCompanyId),
                array_values($rolesBySsoCompanyId),
            ),
        ]);

        $this->call('POST', '/api/sso/provision', [], [], [], [
            'HTTP_X-SSO-Signature' => hash_hmac('sha256', $body, 'whsec'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertOk();
    }

    private function reloadNotes(): int
    {
        return SsoSessionAction::query()->where('action', SsoSessionAction::ACTION_RELOAD_ROLES)->count();
    }

    public function test_a_role_lowered_inside_a_user_update_takes_effect_on_the_next_request(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id);
        $session = $this->sessionFor($a);
        $this->clickAs($user, $session)->assertSee('admin');

        $this->sendUserEvent([self::SSO_COMPANY_A => ['User']]);

        $this->clickAs($user, $session)->assertSee('user');
        $this->assertAuthenticated();
        $this->assertSame(['User'], RoleSyncingUser::query()->find($user->id)->getRoleNames()->all());
    }

    public function test_a_user_update_that_changes_no_role_leaves_no_note_and_reloads_nothing(): void
    {
        [$a, $b] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin', $b->id => 'User'], $a->id);
        $session = $this->sessionFor($a);
        $queries = $this->countQueries(fn () => $this->clickAs($user, $session, '/plain')->assertOk());

        // A name, phone or email edit: same companies, same roles.
        $this->sendUserEvent([self::SSO_COMPANY_A => ['Admin'], self::SSO_COMPANY_B => ['User']]);

        $this->assertSame(0, $this->reloadNotes());
        $this->assertSame($queries, $this->countQueries(fn () => $this->clickAs($user, $session, '/plain')->assertOk()));
        $this->assertSame(2, $queries);
        $this->assertArrayNotHasKey(EnforceSsoSessionActions::SESSION_ROLES_SEEN_ID, $session);
        $this->clickAs($user, $session)->assertSee('admin');
    }

    public function test_a_user_update_that_drops_the_open_company_signs_out_of_this_app_only(): void
    {
        [$a, $b] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin', $b->id => 'User'], $a->id);
        $session = $this->sessionFor($a, ['impersonator_id' => 9, 'impersonated_company_id' => $a->id]);

        $this->sendUserEvent([self::SSO_COMPANY_B => ['User']]);

        $response = $this->clickAs($user, $session);

        $response->assertRedirect('/auth/sso/redirect?intended='.urlencode('http://localhost/probe'));
        $this->assertGuest();
        $this->assertArrayNotHasKey('selected_company_id', $session);
        $this->assertArrayNotHasKey('impersonated_company_id', $session);
        $this->assertSame(9, $session['impersonator_id']);
        $this->assertSame(0, DB::table('company_user')->where('company_id', $a->id)->count());

        $session['selected_company_id'] = $b->id;
        $this->clickAs($user, $session)->assertOk();
    }

    public function test_a_user_update_that_drops_another_company_leaves_the_session_alone(): void
    {
        [$a, $b] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin', $b->id => 'User'], $a->id);
        $session = $this->sessionFor($a);

        $this->sendUserEvent([self::SSO_COMPANY_A => ['Admin']]);

        $this->assertSame(0, DB::table('company_user')->where('company_id', $b->id)->count());
        $this->clickAs($user, $session)->assertOk()->assertSee('admin');
        $this->assertAuthenticated();
        $this->assertSame($a->id, $session['selected_company_id']);
    }

    public function test_a_user_created_for_someone_already_here_applies_a_role_change_too(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id);
        $session = $this->sessionFor($a);

        $this->sendUserEvent([self::SSO_COMPANY_A => ['User']], 'user.created');

        $this->clickAs($user, $session)->assertSee('user');
    }

    public function test_a_new_user_from_user_created_gets_no_note(): void
    {
        $this->seedCompanies();

        $this->sendUserEvent([self::SSO_COMPANY_A => ['Admin']], 'user.created', 7002, 'new@example.test');

        $this->assertSame(1, DB::table('company_user')->count());
        $this->assertSame(0, $this->reloadNotes());
    }

    public function test_a_user_update_still_applies_when_memberships_cannot_be_compared_or_noted(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id);
        Schema::drop('sso_session_actions');

        $this->sendUserEvent([self::SSO_COMPANY_A => ['User']]);

        $this->assertSame(
            ['User'],
            DB::table('company_user_roles')->join('roles', 'roles.id', '=', 'company_user_roles.role_id')
                ->where('company_user_roles.user_id', $user->id)->pluck('roles.name')->all(),
        );
    }

    public function test_a_staff_role_change_needs_no_note_because_staff_checks_read_the_user_row(): void
    {
        [$a] = $this->seedCompanies();
        $user = $this->signedInMember([$a->id => 'Admin'], $a->id);
        $session = $this->sessionFor($a);
        $this->clickAs($user, $session, '/staff')->assertSee('agency');

        $body = json_encode([
            'event' => 'user.staff_role_changed',
            'user' => ['id' => self::SSO_USER_ID, 'email' => 'pat@example.test'],
            'staff_roles' => ['support'],
        ]);
        $this->call('POST', '/api/sso/provision', [], [], [], [
            'HTTP_X-SSO-Signature' => hash_hmac('sha256', $body, 'whsec'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame(0, $this->reloadNotes());
        $this->clickAs($user, $session, '/staff')->assertSee('staff');
    }

    private function countQueries(callable $request): int
    {
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}

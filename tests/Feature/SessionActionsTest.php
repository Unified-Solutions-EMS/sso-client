<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Unified\SsoClient\Middleware\EnforceSsoSessionActions;
use Unified\SsoClient\Models\SsoSessionAction;
use Unified\SsoClient\Tests\Feature\MasterData\MasterDataTestCase;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;

class SessionActionsTest extends MasterDataTestCase
{
    protected bool $runMirrorMigration = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! class_exists('App\\Models\\User')) {
            class_alias(User::class, 'App\\Models\\User');
            class_alias(Company::class, 'App\\Models\\Company');
            class_alias(Role::class, 'App\\Models\\Role');
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.server_url', null);
    }

    protected function defineRoutes($router): void
    {
        Route::middleware([EncryptCookies::class, StartSession::class, EnforceSsoSessionActions::class])->get('/probe', fn () => 'ok');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }

    private function probeUser(): User
    {
        return User::query()->create(['sso_id' => '501', 'email' => 'p@example.test']);
    }

    public function test_a_live_sign_out_instruction_ends_the_session_and_goes_to_sso_logout(): void
    {
        $user = $this->probeUser();
        SsoSessionAction::create(['user_id' => $user->id, 'action' => SsoSessionAction::ACTION_FORCE_LOGOUT, 'payload' => ['reason' => 'sso_logout'], 'expires_at' => now()->addMinutes(10)]);

        $this->actingAs($user)->get('/probe')->assertRedirect('https://sso.test/auth/logout');

        $this->assertGuest();
        $this->assertSame(0, SsoSessionAction::query()->count());
    }

    public function test_an_expired_instruction_is_dropped_without_signing_out(): void
    {
        $user = $this->probeUser();
        SsoSessionAction::create(['user_id' => $user->id, 'action' => SsoSessionAction::ACTION_FORCE_LOGOUT, 'payload' => ['reason' => 'sso_logout'], 'expires_at' => now()->subMinute()]);

        $this->actingAs($user)->get('/probe')->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, SsoSessionAction::query()->count());
    }

    public function test_a_sign_out_issued_before_this_sign_in_is_not_for_this_session(): void
    {
        $user = $this->probeUser();
        SsoSessionAction::create([
            'user_id' => $user->id,
            'action' => SsoSessionAction::ACTION_FORCE_LOGOUT,
            'payload' => ['reason' => 'sso_logout', 'issued_at' => now()->subMinutes(2)->toIso8601String()],
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user)
            ->withSession([EnforceSsoSessionActions::SESSION_SIGNED_IN_AT => now()->subMinute()->getTimestamp()])
            ->get('/probe')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, SsoSessionAction::query()->count());
    }

    public function test_a_sign_out_issued_after_this_sign_in_still_applies(): void
    {
        $user = $this->probeUser();
        SsoSessionAction::create([
            'user_id' => $user->id,
            'action' => SsoSessionAction::ACTION_FORCE_LOGOUT,
            'payload' => ['reason' => 'sso_logout', 'issued_at' => now()->toIso8601String()],
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user)
            ->withSession([EnforceSsoSessionActions::SESSION_SIGNED_IN_AT => now()->subMinutes(5)->getTimestamp()])
            ->get('/probe')
            ->assertRedirect('https://sso.test/auth/logout');

        $this->assertGuest();
    }
}

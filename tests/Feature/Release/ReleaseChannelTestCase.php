<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Release\ReleaseChannel;
use Unified\SsoClient\Tests\Feature\MasterData\MasterDataTestCase;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;

/**
 * An app with the package migrations run (so companies.release_channel
 * exists) on the stable deployment, with a beta deployment configured.
 */
abstract class ReleaseChannelTestCase extends MasterDataTestCase
{
    protected bool $runMirrorMigration = false;

    protected const STABLE = 'https://stable.test';

    protected const BETA = 'https://beta.test';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! class_exists('App\\Models\\User')) {
            class_alias(User::class, 'App\\Models\\User');
            class_alias(Company::class, 'App\\Models\\Company');
            class_alias(Role::class, 'App\\Models\\Role');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        ReleaseChannel::flushSchemaCache();
    }

    protected function tearDown(): void
    {
        ReleaseChannel::flushSchemaCache();

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('sso.server_url', null);
        $app['config']->set('sso.release.channel', 'stable');
        $app['config']->set('sso.release.urls', ['stable' => self::STABLE, 'beta' => self::BETA]);
        $app['config']->set('sso.release.company_model', Company::class);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'login')->name('login');

        Route::middleware('web')->group(function (): void {
            Route::match(['GET', 'POST'], '/probe', fn () => 'served');
            Route::get('/admin', fn () => 'admin');
            Route::get('/admin/companies', fn () => 'admin');
            Route::get('/reports/staff', fn () => 'reports');
            Route::get('/api/probe', fn () => 'served');
            Route::get('/assistant/probe', fn () => 'served');
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('users', function (Blueprint $table): void {
            $table->rememberToken();
        });

        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/database/migrations');
    }

    protected function agency(int $ssoCompanyId, string $channel = 'stable'): Company
    {
        return Company::query()->create([
            'name' => "Agency {$ssoCompanyId}",
            'sso_company_id' => (string) $ssoCompanyId,
            'release_channel' => $channel,
        ]);
    }

    protected function person(string $ssoId = '900'): User
    {
        return User::query()->create(['name' => 'Pat Medic', 'email' => "pat{$ssoId}@example.test", 'sso_id' => $ssoId]);
    }

    protected function asAgency(User $user, Company $company): static
    {
        return $this->actingAs($user)->withSession(['selected_company_id' => $company->id]);
    }
}

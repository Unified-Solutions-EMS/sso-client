<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Unified\SsoClient\Release\ReleaseChannel;
use Unified\SsoClient\SsoUserSynchronizer;
use Unified\SsoClient\Tests\Feature\MasterData\MasterDataTestCase;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;

/**
 * An app that updated the package but has not run its migrations, and has
 * no release settings: sign-in, webhooks, pages and queues behave as before.
 */
class ReleaseChannelNotInstalledTest extends MasterDataTestCase
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

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('n', 32)));
        $app['config']->set('sso.release.urls.beta', 'https://beta.test');
        $app['config']->set('sso.release.company_model', Company::class);
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->get('/probe', fn () => 'served');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        (require dirname(__DIR__, 3).'/database/migrations/2026_04_26_020000_create_sso_session_actions_table.php')->up();
    }

    public function test_sign_in_and_company_updated_ignore_the_value(): void
    {
        $this->company(90);

        app(SsoUserSynchronizer::class)->synchronize([
            'user' => ['id' => '900', 'email' => 'pat@example.test'],
            'companies' => [['id' => 90, 'name' => 'Agency 90', 'roles' => ['User'], 'releaseChannel' => 'beta']],
        ]);

        $this->postWebhook('company.updated', ['company' => ['id' => 90, 'release_channel' => 'beta']])->assertOk();

        $this->assertSame(1, DB::table('companies')->count());
    }

    public function test_every_agency_reads_as_stable_and_keeps_its_queue(): void
    {
        $id = $this->company(91);

        $this->assertSame('stable', ReleaseChannel::forCompany($id));
        $this->assertSame('stable', ReleaseChannel::forSsoCompanyId(91));
        $this->assertNull(ReleaseChannel::queueForCompany($id));
    }

    public function test_pages_are_served_where_they_are(): void
    {
        $user = User::query()->create(['email' => 'p@example.test', 'sso_id' => '901']);
        $id = $this->company(92);

        $this->actingAs($user)->withSession(['selected_company_id' => $id])->get('/probe')->assertOk();
    }
}

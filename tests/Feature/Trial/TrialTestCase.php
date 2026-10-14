<?php

namespace Unified\SsoClient\Tests\Feature\Trial;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\Tests\Feature\MasterData\Vehicles\VehiclesTestCase;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\Stubs\StubRunTrialPurger;
use Unified\SsoClient\Tests\Stubs\StubRunTrialSeeder;
use Unified\SsoClient\Tests\Stubs\StubTrialDataPurger;
use Unified\SsoClient\Tests\Stubs\StubTrialDataSeeder;

/**
 * A Crew-shaped app with all four sample-list mirrors installed and turned
 * on (divisions, locations, vehicles, qualifications) and the app's trial
 * seeder and purger stubbed as App\ classes.
 */
abstract class TrialTestCase extends VehiclesTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! class_exists('App\\Models\\User')) {
            class_alias(User::class, 'App\\Models\\User');
            class_alias(Company::class, 'App\\Models\\Company');
            class_alias(Role::class, 'App\\Models\\Role');
        }

        if (! class_exists('App\\Services\\TrialDataPurger')) {
            class_alias(StubTrialDataPurger::class, 'App\\Services\\TrialDataPurger');
            class_alias(StubRunTrialPurger::class, 'App\\Jobs\\RunTrialPurger');
        }

        if (! class_exists('App\\Services\\TrialDataSeeder')) {
            class_alias(StubTrialDataSeeder::class, 'App\\Services\\TrialDataSeeder');
            class_alias(StubRunTrialSeeder::class, 'App\\Jobs\\RunTrialSeeder');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        StubTrialDataSeeder::reset();
        StubTrialDataPurger::reset();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach (['divisions', 'locations', 'vehicles', 'qualifications'] as $entity) {
            $app['config']->set("sso.master_data.{$entity}", true);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('company_user_qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('qualification_id');
            $table->timestamps();
        });

        (require __DIR__.'/../../../database/master-data/2026_10_06_000000_add_sso_mirror_columns_to_qualifications_table.php')->up();
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    protected function linkedRow(string $table, string $ssoColumn, int $companyId, int $ssoId, string $name, array $columns = []): int
    {
        $nameColumn = $table === 'vehicles' ? 'unit_number' : 'name';

        return (int) DB::table($table)->insertGetId([
            'company_id' => $companyId,
            $nameColumn => $name,
            $ssoColumn => $ssoId,
            'is_active' => true,
            'sso_link_pending' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ] + $columns);
    }

    protected function postTrialWebhook(string $event, int $ssoCompanyId, array $extra = []): TestResponse
    {
        return $this->postWebhook($event, [
            'company' => ['id' => $ssoCompanyId, 'name' => "Trial {$ssoCompanyId}", 'status' => 'trial'],
            'users' => ['admin-uuid', 'fake-1'],
            'admin_user_id' => 'admin-uuid',
        ] + $extra);
    }
}

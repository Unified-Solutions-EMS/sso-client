<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Locations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Locations\LocationCatalog;
use Unified\SsoClient\MasterData\Locations\LocationProjection;
use Unified\SsoClient\Tests\Stubs\DemLocationProjection;
use Unified\SsoClient\Tests\TestCase;

/**
 * Crew-Scheduling's shape by default: `divisions` (with the divisions
 * mirror installed) and `locations(company_id, division_id, name,
 * phone_number, address)`. Set $cloudPcrShape for CloudPCR's
 * `dem_locations` + `dem_location_phones`, bound through
 * DemLocationProjection exactly as CloudPCR binds its own.
 */
abstract class LocationsTestCase extends TestCase
{
    protected bool $cloudPcrShape = false;

    protected bool $runMirrorMigration = true;

    protected function setUp(): void
    {
        parent::setUp();

        LocationCatalog::flushSchemaCache();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', $this->cloudPcrShape ? 'cloudpcr' : 'crew-scheduling');
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('sso.master_data.locations', true);

        if ($this->cloudPcrShape) {
            $app->bind(LocationProjection::class, DemLocationProjection::class);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        if ($this->cloudPcrShape) {
            Schema::create('dem_locations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                foreach (['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11'] as $element) {
                    $table->string("dlocation_{$element}")->nullable();
                    $table->string("dlocation_{$element}_nv")->nullable();
                }
                $table->string('dlocation_06b')->nullable();
                $table->string('mailing_city')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });

            Schema::create('dem_location_phones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('dem_location_id')->constrained('dem_locations')->cascadeOnDelete();
                $table->string('phone_number');
                $table->string('phone_type')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        } else {
            // Crew's 2024_11_05 tables, with division_id already nullable (Crew's
            // design PR 6 relaxes it before the mirror is turned on).
            Schema::create('divisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('name');
                $table->timestamps();
            });

            Schema::table('company_user', function (Blueprint $table) {
                $table->unsignedBigInteger('division_id')->nullable();
            });

            Schema::create('locations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->foreignId('division_id')->nullable()->constrained('divisions')->restrictOnDelete();
                $table->string('name');
                $table->string('phone_number')->nullable();
                $table->string('address')->nullable();
                $table->timestamps();
            });

            // A child that would cascade with its location, like Crew's resources.
            Schema::create('resources', function (Blueprint $table) {
                $table->id();
                $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
                $table->string('name');
            });

            (require __DIR__.'/../../../../database/master-data/2026_10_09_000000_create_or_extend_divisions_mirror.php')->up();
        }

        if ($this->runMirrorMigration) {
            $this->runPublishedMirrorMigration();
        }
    }

    protected function runPublishedMirrorMigration(): void
    {
        $migration = require __DIR__.'/../../../../database/master-data/2026_10_09_100000_create_or_extend_locations_mirror.php';
        $migration->up();
    }

    protected function table(): string
    {
        return $this->cloudPcrShape ? 'dem_locations' : 'locations';
    }

    protected function company(int $ssoCompanyId, string $name = 'Agency'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name.' '.$ssoCompanyId,
            'sso_company_id' => (string) $ssoCompanyId,
        ]);
    }

    protected function localDivision(int $companyId, string $name, ?int $ssoId = null): int
    {
        return (int) DB::table('divisions')->insertGetId(array_filter([
            'company_id' => $companyId,
            'name' => $name,
            'sso_division_id' => $ssoId,
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function localLocation(int $companyId, string $name, array $attributes = []): int
    {
        $nameColumn = $this->cloudPcrShape ? 'dlocation_02' : 'name';

        return (int) DB::table($this->table())->insertGetId([
            'company_id' => $companyId,
            $nameColumn => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ] + $attributes);
    }

    protected function mirrored(int $companyId, int $ssoId): ?object
    {
        return DB::table($this->table())->where('company_id', $companyId)->where('sso_location_id', $ssoId)->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postWebhook(string $event, array $payload): TestResponse
    {
        $body = json_encode(['event' => $event, 'timestamp' => now()->toIso8601String()] + $payload);

        return $this->call('POST', '/api/sso/provision', [], [], [], [
            'HTTP_X-SSO-Signature' => hash_hmac('sha256', $body, 'whsec'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /**
     * SSO's Location::toContractArray().
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function locationRecord(int $id, string $name, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'division_id' => null,
            'division_name' => null,
            'name' => $name,
            'number' => null,
            'location_type' => '1301005',
            'location_type_name' => 'Station',
            'address' => [
                'street' => '120 Lake Flower Ave',
                'street2' => null,
                'city_gnis' => '978016',
                'city_name' => 'Saranac Lake',
                'state' => '36',
                'state_name' => 'New York',
                'zip' => '12983',
                'county' => '36033',
                'county_name' => 'Franklin',
                'country' => 'US',
            ],
            'latitude' => 44.3247,
            'longitude' => -74.1313,
            'phones' => [['id' => 1, 'number' => '+15185550142', 'type' => '9913009']],
            'is_active' => true,
            'sort_order' => 1,
            'updated_at' => '2026-10-09T12:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @return array<string, mixed>
     */
    protected function snapshot(int $ssoCompanyId, array $locations): array
    {
        return [
            'company' => ['id' => $ssoCompanyId, 'division_label' => 'division'],
            'locations' => $locations,
        ];
    }
}

<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Vehicles;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Vehicles\VehicleCatalog;
use Unified\SsoClient\MasterData\Vehicles\VehicleProjection;
use Unified\SsoClient\Tests\Stubs\DemVehicleProjection;
use Unified\SsoClient\Tests\Stubs\FleetVehicleProjection;
use Unified\SsoClient\Tests\TestCase;

/**
 * Three app shapes:
 * - `standard`: no vehicle table yet; the published migration creates the
 *   default one. Crew-shaped divisions and locations with both mirrors
 *   installed, so home location and division translation can be watched;
 * - `fleet`: Fleet-Management's existing `vehicles` (with mileage and notes
 *   the mirror must never touch), bound through FleetVehicleProjection;
 * - `cloudpcr`: CloudPCR's `dem_vehicles` + `dem_vehicle_cert_levels` +
 *   `dem_vehicle_years`, bound through DemVehicleProjection.
 */
abstract class VehiclesTestCase extends TestCase
{
    /** @var 'standard'|'fleet'|'cloudpcr' */
    protected string $shape = 'standard';

    protected bool $runMirrorMigration = true;

    protected function setUp(): void
    {
        parent::setUp();

        VehicleCatalog::flushSchemaCache();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', ['standard' => 'crew-scheduling', 'fleet' => 'fleet-management', 'cloudpcr' => 'cloudpcr'][$this->shape]);
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('sso.master_data.vehicles', true);

        match ($this->shape) {
            'fleet' => $app->bind(VehicleProjection::class, FleetVehicleProjection::class),
            'cloudpcr' => $app->bind(VehicleProjection::class, DemVehicleProjection::class),
            default => null,
        };
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        match ($this->shape) {
            'fleet' => $this->createFleetTables(),
            'cloudpcr' => $this->createCloudPcrTables(),
            default => $this->createCrewReferenceTables(),
        };

        if ($this->runMirrorMigration) {
            $this->runPublishedMirrorMigration();
        }
    }

    protected function runPublishedMirrorMigration(): void
    {
        (require __DIR__.'/../../../../database/master-data/2026_10_11_000000_create_or_extend_vehicles_mirror.php')->up();
        (require __DIR__.'/../../../../database/master-data/2026_10_13_200000_add_sso_removed_at_to_mirror_child_tables.php')->up();
    }

    protected function createCrewReferenceTables(): void
    {
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
            $table->unsignedBigInteger('division_id')->nullable();
            $table->string('name');
            $table->string('phone_number')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        (require __DIR__.'/../../../../database/master-data/2026_10_09_000000_create_or_extend_divisions_mirror.php')->up();
        (require __DIR__.'/../../../../database/master-data/2026_10_09_100000_create_or_extend_locations_mirror.php')->up();
    }

    protected function createFleetTables(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('unit_number');
            $table->string('vin')->nullable();
            $table->string('license_plate')->nullable();
            $table->string('category')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->integer('model_year')->nullable();
            $table->string('color')->nullable();
            $table->string('fuel_type')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    protected function createCloudPcrTables(): void
    {
        Schema::create('dem_vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->uuid('uuid')->nullable();
            foreach (['01', '02', '03', '04', '05', '06', '07', '08', '09', '10'] as $element) {
                $table->string("dvehicle_{$element}")->nullable();
            }
            $table->string('export_vehicle_number')->nullable();
            $table->string('export_call_sign')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dem_vehicle_cert_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dem_vehicle_id')->constrained('dem_vehicles')->cascadeOnDelete();
            $table->string('dvehicle_05');
            $table->unsignedInteger('dvehicle_06')->nullable();
            $table->unsignedInteger('dvehicle_07')->nullable();
            $table->unsignedInteger('dvehicle_08')->nullable();
            $table->timestamps();
        });

        Schema::create('dem_vehicle_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dem_vehicle_id')->constrained('dem_vehicles')->cascadeOnDelete();
            $table->unsignedSmallInteger('dvehicle_11');
            $table->unsignedInteger('dvehicle_12')->nullable();
            $table->decimal('dvehicle_13', 8, 1)->nullable();
        });
    }

    protected function table(): string
    {
        return $this->shape === 'cloudpcr' ? 'dem_vehicles' : 'vehicles';
    }

    protected function company(int $ssoCompanyId, string $name = 'Agency'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name.' '.$ssoCompanyId,
            'sso_company_id' => (string) $ssoCompanyId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function localVehicle(int $companyId, string $unitNumber, array $attributes = []): int
    {
        return (int) DB::table($this->table())->insertGetId([
            'company_id' => $companyId,
            ($this->shape === 'cloudpcr' ? 'dvehicle_01' : 'unit_number') => $unitNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ] + $attributes);
    }

    protected function mirrored(int $companyId, int $ssoId): ?object
    {
        return DB::table($this->table())->where('company_id', $companyId)->where('sso_vehicle_id', $ssoId)->first();
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
     * @param  array<string, mixed>  $vehicle
     */
    protected function deliver(string $event, int $ssoCompanyId, array $vehicle): TestResponse
    {
        return $this->postWebhook($event, [
            'company' => ['id' => $ssoCompanyId, 'division_label' => 'division'],
            'vehicle' => $vehicle,
            'updated_at' => $vehicle['updated_at'] ?? null,
        ]);
    }

    /**
     * SSO's Vehicle::toContractArray().
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function vehicleRecord(int $id, string $unitNumber, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'uuid' => sprintf('6f1c2a40-0000-4000-8000-%012d', $id),
            'unit_number' => $unitNumber,
            'call_sign' => null,
            'export_unit_number' => null,
            'export_call_sign' => null,
            'reported_unit_number' => $overrides['export_unit_number'] ?? $unitNumber,
            'reported_call_sign' => null,
            'vin' => null,
            'vehicle_type' => '1404001',
            'vehicle_type_name' => 'Ambulance',
            'model_year' => 2021,
            'initial_cost' => null,
            'make' => 'Ford',
            'model' => 'F-450',
            'color' => 'White',
            'license_plate' => null,
            'license_plate_state' => null,
            'license_plate_state_name' => null,
            'fuel_type' => 'diesel',
            'fuel_type_name' => 'Diesel',
            'status' => 'in_service',
            'status_name' => 'In service',
            'home_location_id' => null,
            'home_location_name' => null,
            'division_id' => null,
            'division_name' => null,
            'crew_levels' => [],
            'yearly_usage' => [],
            'is_active' => true,
            'sort_order' => 1,
            'updated_at' => '2026-10-11T12:00:00+00:00',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    protected function crewLevel(int $id, string $level, ?int $ambulance = 2, ?int $nonTransport = null, ?int $non911 = null): array
    {
        return [
            'id' => $id,
            'uuid' => sprintf('7a2b3c40-0000-4000-8000-%012d', $id),
            'level' => $level,
            'level_name' => null,
            'on_911_ambulance' => $ambulance,
            'on_911_non_transport' => $nonTransport,
            'on_non_911_transport' => $non911,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function yearlyUsage(int $id, int $year, ?int $hours = null, ?float $distance = null): array
    {
        return [
            'id' => $id,
            'uuid' => sprintf('8b3c4d50-0000-4000-8000-%012d', $id),
            'year' => $year,
            'hours' => $hours,
            'distance' => $distance,
            'distance_unit' => $distance === null ? null : '9929003',
            'distance_unit_name' => $distance === null ? null : 'Miles',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $vehicles
     * @return array<string, mixed>
     */
    protected function snapshot(int $ssoCompanyId, array $vehicles): array
    {
        return [
            'company' => ['id' => $ssoCompanyId, 'division_label' => 'division'],
            'vehicles' => $vehicles,
        ];
    }
}

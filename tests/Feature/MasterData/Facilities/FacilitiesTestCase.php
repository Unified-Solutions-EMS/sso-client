<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Facilities;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Facilities\FacilityCatalog;
use Unified\SsoClient\MasterData\Facilities\FacilityProjection;
use Unified\SsoClient\Tests\Stubs\DemFacilityProjection;
use Unified\SsoClient\Tests\TestCase;

/**
 * Two app shapes:
 * - `standard`: no facility table yet; the published migration creates the
 *   default `facilities` one;
 * - `cloudpcr`: CloudPCR's `dem_facilities` type groups +
 *   `dem_facility_entries` + `dem_facility_entry_phones`, bound through
 *   DemFacilityProjection.
 */
abstract class FacilitiesTestCase extends TestCase
{
    /** @var 'standard'|'cloudpcr' */
    protected string $shape = 'cloudpcr';

    protected bool $runMirrorMigration = true;

    protected function setUp(): void
    {
        parent::setUp();

        FacilityCatalog::flushSchemaCache();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', $this->shape === 'cloudpcr' ? 'cloudpcr' : 'billing');
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('sso.master_data.facilities', true);

        if ($this->shape === 'cloudpcr') {
            $app->bind(FacilityProjection::class, DemFacilityProjection::class);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        if ($this->shape === 'cloudpcr') {
            $this->createCloudPcrTables();
        }

        if ($this->runMirrorMigration) {
            $this->runPublishedMirrorMigration();
        }
    }

    protected function runPublishedMirrorMigration(): void
    {
        (require __DIR__.'/../../../../database/master-data/2026_10_11_100000_create_or_extend_facilities_mirror.php')->up();
    }

    protected function createCloudPcrTables(): void
    {
        Schema::create('dem_facilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('dfacility_01')->nullable();
            $table->string('dfacility_01_nv')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('dem_facility_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dem_facility_id')->constrained('dem_facilities')->cascadeOnDelete();
            foreach (['02', '03', '06', '07', '08', '09', '10', '11', '12', '13', '14'] as $element) {
                $table->string("dfacility_{$element}")->nullable();
                $table->string("dfacility_{$element}_nv")->nullable();
            }
            $table->json('dfacility_04')->nullable();
            $table->json('dfacility_05')->nullable();
            $table->string('dfacility_07b')->nullable();
            $table->string('mailing_city')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('dem_facility_entry_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dem_facility_entry_id')->constrained('dem_facility_entries')->cascadeOnDelete();
            $table->string('phone_number');
            $table->string('phone_type')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    protected function table(): string
    {
        return $this->shape === 'cloudpcr' ? 'dem_facility_entries' : 'facilities';
    }

    protected function nameColumn(): string
    {
        return $this->shape === 'cloudpcr' ? 'dfacility_02' : 'name';
    }

    protected function company(int $ssoCompanyId, string $name = 'Agency'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name.' '.$ssoCompanyId,
            'sso_company_id' => (string) $ssoCompanyId,
        ]);
    }

    /**
     * A CloudPCR type group for the company.
     */
    protected function group(int $companyId, string $type = '1701005'): int
    {
        return (int) (DB::table('dem_facilities')->where('company_id', $companyId)->where('dfacility_01', $type)->value('id')
            ?? DB::table('dem_facilities')->insertGetId(['company_id' => $companyId, 'dfacility_01' => $type, 'created_at' => now(), 'updated_at' => now()]));
    }

    /**
     * A local facility the agency had before the cutover.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function localFacility(int $companyId, string $name, array $attributes = []): int
    {
        $owner = $this->shape === 'cloudpcr'
            ? ['dem_facility_id' => $this->group($companyId, $attributes['type'] ?? '1701005')]
            : ['company_id' => $companyId];
        unset($attributes['type']);

        return (int) DB::table($this->table())->insertGetId($owner + [
            $this->nameColumn() => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ] + $attributes);
    }

    protected function mirrored(int $ssoId): ?object
    {
        return DB::table($this->table())->where('sso_facility_id', $ssoId)->first();
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
     * @param  array<string, mixed>  $facility
     */
    protected function deliver(string $event, int $ssoCompanyId, array $facility): TestResponse
    {
        return $this->postWebhook($event, [
            'company' => ['id' => $ssoCompanyId, 'division_label' => 'division'],
            'facility' => $facility,
            'updated_at' => $facility['updated_at'] ?? null,
        ]);
    }

    /**
     * SSO's Facility::toContractArray().
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function facilityRecord(int $id, string $name, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'type' => '1701005',
            'name' => $name,
            'location_code' => null,
            'registry' => null,
            'designations' => [],
            'npis' => [],
            'address' => [
                'suite' => null,
                'street' => '2233 W Dunlap Ave',
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
            'fax' => null,
            'phones' => [],
            'is_active' => true,
            'sort_order' => 1,
            'updated_at' => '2026-10-11T12:00:00+00:00',
        ], $overrides);
    }
}

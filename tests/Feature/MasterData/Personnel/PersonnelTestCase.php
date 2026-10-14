<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Personnel\PersonnelProjection;
use Unified\SsoClient\SsoUserSynchronizer;
use Unified\SsoClient\Tests\Stubs\DemPersonnelProjection;
use Unified\SsoClient\Tests\Stubs\HrPersonnelProjection;
use Unified\SsoClient\Tests\TestCase;

/**
 * Three app shapes:
 * - `standard`: no personnel table; the published migration creates the
 *   package's `sso_personnel` read model (Crew-shaped divisions installed,
 *   so the division translation can be watched);
 * - `cloudpcr`: `dem_personnel` + certifications, cert levels, phones,
 *   emails and immunizations, bound through DemPersonnelProjection;
 * - `hr`: `personnel_profiles` + phones, emails and licences (driver's
 *   licences mixed in), bound through HrPersonnelProjection.
 */
abstract class PersonnelTestCase extends TestCase
{
    /** @var 'standard'|'cloudpcr'|'hr' */
    protected string $shape = 'cloudpcr';

    protected bool $runMirrorMigration = true;

    protected function setUp(): void
    {
        parent::setUp();

        SsoUserSynchronizer::flushSchemaCache();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', ['standard' => 'crew-scheduling', 'cloudpcr' => 'cloudpcr', 'hr' => 'hr'][$this->shape]);
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('sso.master_data.personnel', true);

        match ($this->shape) {
            'cloudpcr' => $app->bind(PersonnelProjection::class, DemPersonnelProjection::class),
            'hr' => $app->bind(PersonnelProjection::class, HrPersonnelProjection::class),
            default => null,
        };
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        match ($this->shape) {
            'cloudpcr' => $this->createCloudPcrTables(),
            'hr' => $this->createHrTables(),
            default => $this->createDivisions(),
        };

        if ($this->runMirrorMigration) {
            $this->runPublishedMirrorMigration();
        }
    }

    protected function runPublishedMirrorMigration(): void
    {
        (require __DIR__.'/../../../../database/master-data/2026_10_13_000000_create_or_extend_personnel_mirror.php')->up();
        (require __DIR__.'/../../../../database/master-data/2026_10_13_200000_add_sso_removed_at_to_mirror_child_tables.php')->up();
    }

    protected function createDivisions(): void
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

        (require __DIR__.'/../../../../database/master-data/2026_10_09_000000_create_or_extend_divisions_mirror.php')->up();
    }

    protected function createCloudPcrTables(): void
    {
        Schema::create('dem_personnel', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_ems_personnel')->default(true);
            foreach (['01', '02', '03', '11', '13', '31', '32', '34', '35', '40'] as $element) {
                $table->string("dpersonnel_{$element}_nv")->nullable();
            }
            foreach (['01', '02', '03', '04', '04b', '05', '06', '07', '08', '09', '10', '14', '15', '21', '28', '29', '31', '34', '40'] as $element) {
                $table->string("dpersonnel_{$element}")->nullable();
            }
            foreach (['11', '30', '32', '33', '37'] as $element) {
                $table->date("dpersonnel_{$element}")->nullable();
            }
            foreach (['13', '16', '17', '20', '35'] as $element) {
                $table->json("dpersonnel_{$element}")->nullable();
            }
            $table->unsignedSmallInteger('dpersonnel_36')->nullable();
            $table->string('mailing_city')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dem_personnel_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dem_personnel_id')->constrained('dem_personnel')->cascadeOnDelete();
            foreach (['22', '23', '24'] as $element) {
                $table->string("dpersonnel_{$element}")->nullable();
            }
            foreach (['25', '26', '27'] as $element) {
                $table->date("dpersonnel_{$element}")->nullable();
            }
            $table->timestamps();
        });

        Schema::create('dem_personnel_cert_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dem_personnel_id')->constrained('dem_personnel')->cascadeOnDelete();
            $table->string('dpersonnel_38')->nullable();
            $table->date('dpersonnel_39')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        foreach (['phones' => ['phone_number', 'phone_type'], 'emails' => ['email_address', 'email_type']] as $kind => [$value, $type]) {
            Schema::create("dem_personnel_{$kind}", function (Blueprint $table) use ($value, $type) {
                $table->id();
                $table->foreignId('dem_personnel_id')->constrained('dem_personnel')->cascadeOnDelete();
                $table->string($value);
                $table->string($type)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        Schema::create('dem_personnel_immunizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dem_personnel_id')->constrained('dem_personnel')->cascadeOnDelete();
            $table->string('dpersonnel_18')->nullable();
            $table->string('dpersonnel_19')->nullable();
            $table->timestamps();
        });
    }

    protected function createHrTables(): void
    {
        Schema::create('personnel_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('employee_number')->nullable();
            $table->string('rank')->nullable();
            $table->string('job_title')->nullable();
            $table->date('hire_date')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone')->nullable();
            $table->string('gender', 50)->nullable();
            $table->json('race')->nullable();
            $table->string('citizenship_code', 10)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('national_registry_number')->nullable();
            $table->string('national_registry_level', 50)->nullable();
            $table->date('national_registry_expiration')->nullable();
            $table->boolean('is_ems_personnel')->default(false);
            $table->string('current_status', 32)->default('active');
            $table->date('status_effective_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        foreach (['phones' => ['phone_number', 'phone_type'], 'emails' => ['email_address', 'email_type']] as $kind => [$value, $type]) {
            Schema::create("personnel_{$kind}", function (Blueprint $table) use ($value, $type) {
                $table->id();
                $table->foreignId('personnel_profile_id')->constrained()->cascadeOnDelete();
                $table->string($value);
                $table->string($type, 20)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // HR adds `level` for EMS licences (its table only knew DL/CDL types).
        // `status` / `approved_by` / `card_front_path` stand in for the
        // approval history and card images HR keeps on its licence rows.
        Schema::create('personnel_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_profile_id')->constrained()->cascadeOnDelete();
            $table->string('license_type', 50);
            $table->string('number');
            $table->string('state', 10)->nullable();
            $table->string('level')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiration_date')->nullable();
            // HR's own: approval workflow and card images, never written from SSO.
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('card_front_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    protected function company(int $ssoCompanyId): int
    {
        return (int) DB::table('companies')->insertGetId(['name' => "Agency {$ssoCompanyId}", 'sso_company_id' => (string) $ssoCompanyId]);
    }

    protected function user(int $ssoUserId, ?string $email = null): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => "User {$ssoUserId}",
            'email' => $email ?? "user{$ssoUserId}@example.com",
            'sso_id' => (string) $ssoUserId,
        ]);
    }

    /**
     * SSO's PersonnelSnapshot for one person at one company.
     *
     * @param  array<string, mixed>  $overrides  merged recursively (lists replace)
     * @return array<string, mixed>
     */
    protected function person(int $ssoCompanyId, int $ssoUserId, array $overrides = []): array
    {
        $snapshot = [
            'company' => ['id' => $ssoCompanyId],
            'user' => [
                'id' => $ssoUserId,
                'email' => "user{$ssoUserId}@example.com",
                'first_name' => 'Jordan',
                'last_name' => 'Reyes',
                'phone_number' => '+15185550100',
            ],
            'profile' => [
                'middle_name' => null,
                'date_of_birth' => '1988-04-12',
                'sex' => '9919001',
                'race' => ['2514011'],
                'citizenship' => 'US',
                'address' => ['street' => '12 Main St', 'street2' => null, 'city_gnis' => '978683', 'city_name' => 'Saranac Lake', 'state' => '36', 'zip' => '12983', 'country' => 'US'],
                'highest_degree' => null,
                'degree_fields' => [],
                'vehicle_license_types' => [],
                'languages' => [],
                'national_registry' => ['number' => 'E1234567', 'level' => '9925005', 'expires_on' => '2027-03-31'],
            ],
            'phones' => [['id' => 31, 'number' => '+15185550199', 'type' => '9913005', 'is_primary' => true]],
            'emails' => [['id' => 41, 'address' => 'jordan.home@example.com', 'type' => '9904001', 'is_primary' => false]],
            'immunizations' => [['id' => 51, 'type' => '9910035', 'year' => 2024]],
            'licenses' => [
                ['id' => 61, 'state' => '36', 'number' => '123456', 'level' => '9925007', 'current_since' => '2024-01-01', 'first_issued_on' => '2015-06-01', 'expires_on' => '2027-01-01'],
            ],
            'membership' => [
                'is_member' => true,
                'is_active' => true,
                'is_ems_personnel' => true,
                'division_id' => null,
                'agency_personnel_id' => 'A-17',
                'employment_status' => '2731001',
                'employment_status_date' => '2020-02-01',
                'hire_date' => '2020-02-01',
                'primary_job_role' => '2733011',
                'other_job_roles' => [],
                'total_service_years' => 8,
                'total_service_documented_on' => '2026-01-01',
                'practice_levels' => [['id' => 71, 'level' => '9925007', 'since' => '2020-02-01']],
                'deactivated_at' => null,
            ],
            'updated_at' => '2026-10-13T12:00:00+00:00',
        ];

        return self::merge($snapshot, $overrides);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null) && ! array_is_list($base[$key])
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
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
     * @param  array<string, mixed>  $snapshot
     */
    protected function deliver(array $snapshot): TestResponse
    {
        return $this->postWebhook('person.updated', [
            'company' => $snapshot['company'],
            'personnel' => $snapshot,
            'updated_at' => $snapshot['updated_at'],
        ]);
    }

    protected function demRow(int $companyId, int $ssoUserId): ?object
    {
        return DB::table('dem_personnel')->where('company_id', $companyId)->where('sso_user_id', $ssoUserId)->first();
    }
}

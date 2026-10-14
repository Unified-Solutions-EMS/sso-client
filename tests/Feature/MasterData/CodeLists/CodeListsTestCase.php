<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\CodeLists;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Unified\SsoClient\MasterData\Medications\MedicationProjection;
use Unified\SsoClient\MasterData\Procedures\ProcedureProjection;
use Unified\SsoClient\Tests\Stubs\DemMedicationProjection;
use Unified\SsoClient\Tests\Stubs\DemProcedureProjection;
use Unified\SsoClient\Tests\Stubs\DrugTrackingMedicationProjection;
use Unified\SsoClient\Tests\TestCase;

/**
 * Three app shapes around the package's own `sso_medications` /
 * `sso_procedures` tables:
 * - `cloudpcr`: the per-state, per-level configuration groups, bound
 *   through DemMedicationProjection / DemProcedureProjection;
 * - `drug-tracking`: `company_drugs` linked to SSO medications, bound
 *   through DrugTrackingMedicationProjection;
 * - `plain`: no projection (the package default), mirror only.
 */
abstract class CodeListsTestCase extends TestCase
{
    /** @var 'cloudpcr'|'drug-tracking'|'plain' */
    protected string $shape = 'cloudpcr';

    protected const PARAMEDIC = '9917007';

    protected const AEMT = '9917001';

    protected const EMT = '9917005';

    protected const NY = '36';

    protected const VT = '50';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sso.base_url', 'https://sso.test');
        $app['config']->set('sso.app_slug', $this->shape === 'plain' ? 'reporting' : $this->shape);
        $app['config']->set('sso.webhook_secret', 'whsec');
        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('sso.master_data.medications', true);
        $app['config']->set('sso.master_data.procedures', true);

        if ($this->shape === 'cloudpcr') {
            $app->bind(MedicationProjection::class, DemMedicationProjection::class);
            $app->bind(ProcedureProjection::class, DemProcedureProjection::class);
        }

        if ($this->shape === 'drug-tracking') {
            $app->bind(MedicationProjection::class, DrugTrackingMedicationProjection::class);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        match ($this->shape) {
            'cloudpcr' => $this->createCloudPcrTables(),
            'drug-tracking' => $this->createDrugTrackingTables(),
            default => null,
        };

        (require __DIR__.'/../../../../database/master-data/2026_10_11_110000_create_medications_mirror.php')->up();
        (require __DIR__.'/../../../../database/master-data/2026_10_11_120000_create_procedures_mirror.php')->up();
    }

    protected function createCloudPcrTables(): void
    {
        Schema::create('dem_configurations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('dconfiguration_01')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (['medication' => ['dconfiguration_08', 'dconfiguration_09'], 'procedure' => ['dconfiguration_06', 'dconfiguration_07']] as $kind => [$level, $code]) {
            Schema::create("dem_configuration_{$kind}_groups", function (Blueprint $table) use ($level) {
                $table->id();
                $table->foreignId('dem_configuration_id')->constrained('dem_configurations')->cascadeOnDelete();
                $table->string($level)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });

            Schema::create("dem_configuration_{$kind}_items", function (Blueprint $table) use ($kind, $code) {
                $table->id();
                $table->unsignedBigInteger("dem_configuration_{$kind}_group_id");
                $table->string($code)->nullable();
                $table->text('name')->nullable();
                $table->string('code_type', 10)->nullable();
                if ($kind === 'medication') {
                    $table->boolean('is_controlled')->default(false);
                }
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    protected function createDrugTrackingTables(): void
    {
        Schema::create('company_drugs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('rxcui')->nullable();
            $table->boolean('is_controlled')->default(false);
            $table->string('dea_schedule')->nullable();
            $table->unsignedBigInteger('sso_medication_id')->nullable();
            $table->timestamps();
        });
    }

    protected function company(int $ssoCompanyId, string $name = 'Agency'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name.' '.$ssoCompanyId,
            'sso_company_id' => (string) $ssoCompanyId,
        ]);
    }

    /**
     * One CloudPCR state configuration with its medication tree:
     * [level => [[code, name, controlled], ...]].
     *
     * @param  array<string, list<array{0: string, 1: string, 2?: bool}>>  $medications
     * @param  array<string, list<array{0: string, 1: string}>>  $procedures
     */
    protected function configuration(int $companyId, string $state, array $medications, array $procedures = []): int
    {
        $config = (int) DB::table('dem_configurations')->insertGetId(['company_id' => $companyId, 'dconfiguration_01' => $state]);

        foreach (['medication' => [$medications, 'dconfiguration_08', 'dconfiguration_09', '9924003'], 'procedure' => [$procedures, 'dconfiguration_06', 'dconfiguration_07', '9924005']] as $kind => [$tree, $levelColumn, $codeColumn, $codeType]) {
            $groupOrder = 0;
            foreach ($tree as $level => $items) {
                $group = (int) DB::table("dem_configuration_{$kind}_groups")->insertGetId(['dem_configuration_id' => $config, $levelColumn => $level, 'sort_order' => $groupOrder++]);

                foreach ($items as $index => $item) {
                    DB::table("dem_configuration_{$kind}_items")->insert([
                        "dem_configuration_{$kind}_group_id" => $group,
                        $codeColumn => $item[0],
                        'name' => $item[1],
                        'code_type' => $codeType,
                        'sort_order' => $index,
                    ] + ($kind === 'medication' ? ['is_controlled' => $item[2] ?? false] : []));
                }
            }
        }

        return $config;
    }

    /**
     * The medication tree of one configuration: [level => [code => name]].
     *
     * @return array<string, array<string, string>>
     */
    protected function medicationTree(int $configId): array
    {
        $tree = [];
        foreach (DB::table('dem_configuration_medication_items')
            ->join('dem_configuration_medication_groups', 'dem_configuration_medication_groups.id', '=', 'dem_configuration_medication_items.dem_configuration_medication_group_id')
            ->where('dem_configuration_id', $configId)
            ->orderBy('dem_configuration_medication_groups.sort_order')
            ->orderBy('dem_configuration_medication_items.sort_order')
            ->get(['dconfiguration_08', 'dconfiguration_09', 'name']) as $item) {
            $tree[$item->dconfiguration_08][$item->dconfiguration_09] = $item->name;
        }

        return $tree;
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
     * SSO's Medication::toContractArray().
     *
     * @param  list<string>  $levels
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function medicationRecord(int $id, string $code, string $name, array $levels, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'code' => $code,
            'code_system' => 'rxnorm',
            'code_type' => '9924003',
            'name' => $name,
            'display_name' => null,
            'label' => $overrides['display_name'] ?? $name,
            'source' => 'import',
            'levels' => $levels,
            'member_levels' => [],
            'is_chartable' => $levels !== [],
            'only_states' => [],
            'ingredient_rxcui' => null,
            'is_controlled' => false,
            'dea_schedule' => null,
            'is_blood_product' => false,
            'is_active' => true,
            'sort_order' => $id,
            'updated_at' => '2026-10-11T12:00:00+00:00',
        ], $overrides);
    }

    /**
     * SSO's Procedure::toContractArray().
     *
     * @param  list<string>  $levels
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function procedureRecord(int $id, string $code, string $name, array $levels, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'code' => $code,
            'code_system' => 'snomed',
            'code_type' => '9924005',
            'name' => $name,
            'display_name' => null,
            'label' => $overrides['display_name'] ?? $name,
            'source' => 'import',
            'levels' => $levels,
            'member_levels' => [],
            'is_chartable' => $levels !== [],
            'only_states' => [],
            'is_active' => true,
            'sort_order' => $id,
            'updated_at' => '2026-10-11T12:00:00+00:00',
        ], $overrides);
    }
}

<?php

namespace Unified\SsoClient\Tests\Feature\Trial;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Unified\SsoClient\Tests\Feature\MasterData\Demographics\DemographicsTestCase;
use Unified\SsoClient\Trial\Exceptions\TrialMasterDataNotReadyException;
use Unified\SsoClient\Trial\TrialMasterDataSync;
use Unified\SsoClient\Trial\TrialMirrorCleaner;

/**
 * The four entities SSO added to a trial's lists on 10-21 (agency profile,
 * contacts, devices, state configuration) through the trial seed readiness
 * check and the purge cleaner, on the standard read models.
 */
class TrialDemographicsTest extends DemographicsTestCase
{
    protected string $entity = 'agency';

    private const ENTITIES = ['agency', 'contacts', 'devices', 'state_configurations'];

    private const COUNTS = ['agency' => 1, 'contacts' => 2, 'devices' => 2, 'state_configurations' => 1];

    private const TABLES = [
        'agency' => ['sso_agency_profiles', 'sso_agency_id'],
        'contacts' => ['sso_agency_contacts', 'sso_contact_id'],
        'devices' => ['sso_devices', 'sso_device_id'],
        'state_configurations' => ['sso_state_configurations', 'sso_state_configuration_id'],
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach (self::ENTITIES as $entity) {
            $app['config']->set("sso.master_data.{$entity}", true);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        foreach (['contacts', 'devices', 'state_configurations'] as $entity) {
            $this->migrate($entity);
        }
    }

    /**
     * SSO's lists for a new trial, `$short` entities one row short.
     *
     * @param  list<string>  $short
     */
    private function fakeSso(int $ssoCompanyId, array $short = []): void
    {
        $stamp = '2026-10-21T12:00:00+00:00';
        $company = ['id' => $ssoCompanyId, 'name' => 'Example EMS', 'timezone' => 'America/New_York'];
        $take = fn (string $entity, array $rows): array => in_array($entity, $short, true) ? array_slice($rows, 0, -1) : $rows;

        Http::fake([
            "sso.test/api/internal/companies/{$ssoCompanyId}/agency" => Http::response(['company' => $company, 'agency' => in_array('agency', $short, true) ? null : [
                'id' => 301, 'state' => '36', 'state_agency_id' => "TRIAL-{$ssoCompanyId}", 'agency_number' => "TRIAL-{$ssoCompanyId}",
                'phone' => '+15185550100', 'service_areas' => [], 'year_stats' => [], 'custom_values' => [], 'updated_at' => $stamp,
            ]]),
            "sso.test/api/internal/companies/{$ssoCompanyId}/contacts" => Http::response(['company' => $company, 'contacts' => $take('contacts', [
                ['id' => 501, 'first_name' => 'Alex', 'last_name' => 'Owner', 'type' => 'administrator', 'is_primary' => true, 'is_active' => true, 'updated_at' => $stamp],
                ['id' => 502, 'first_name' => 'Priya', 'last_name' => 'Natarajan', 'type' => 'medical_director', 'is_primary' => false, 'is_active' => true, 'updated_at' => $stamp],
            ])]),
            "sso.test/api/internal/companies/{$ssoCompanyId}/devices" => Http::response(['company' => $company, 'devices' => $take('devices', [
                ['id' => 601, 'serial' => 'DEMO-LP15-0001', 'name' => 'LIFEPAK 15', 'vehicle_id' => null, 'is_active' => true, 'updated_at' => $stamp],
                ['id' => 602, 'serial' => 'DEMO-XS-0001', 'name' => 'Zoll X Series', 'vehicle_id' => null, 'is_active' => true, 'updated_at' => $stamp],
            ])]),
            "sso.test/api/internal/companies/{$ssoCompanyId}/state_configurations" => Http::response(['company' => $company, 'state_configurations' => $take('state_configurations', [
                ['id' => 701, 'state' => '36', 'crew_call_signs' => ['Medic 1', 'Medic 2', 'Medic 3', 'Engine 1'], 'is_active' => true, 'updated_at' => $stamp],
            ])]),
        ]);
    }

    private function sync(int $companyId, array $counts = self::COUNTS): array
    {
        return app(TrialMasterDataSync::class)->sync($companyId, 70, ['ready' => true, 'counts' => $counts]);
    }

    public function test_readiness_passes_when_every_new_list_is_mirrored_in_full(): void
    {
        $companyId = $this->company(70);
        $this->fakeSso(70);

        $this->assertSame(self::COUNTS, $this->sync($companyId));
        $this->assertSame('TRIAL-70', DB::table('sso_agency_profiles')->where('company_id', $companyId)->value('state_agency_id'));
    }

    #[DataProvider('entities')]
    public function test_readiness_fails_closed_when_a_new_list_is_short(string $entity): void
    {
        $companyId = $this->company(70);
        $this->fakeSso(70, short: [$entity]);

        $expected = self::COUNTS[$entity];

        try {
            $this->sync($companyId);
            $this->fail('A short list must throw so the job retries.');
        } catch (TrialMasterDataNotReadyException $e) {
            $this->assertStringContainsString("{$entity} ".($expected - 1)."/{$expected}", $e->getMessage());
        }
    }

    #[DataProvider('entities')]
    public function test_readiness_ignores_a_counted_list_this_app_has_turned_off(string $entity): void
    {
        config(["sso.master_data.{$entity}" => false]);
        $companyId = $this->company(70);
        $this->fakeSso(70, short: [$entity]);

        $this->assertSame(array_diff_key(self::COUNTS, [$entity => true]), $this->sync($companyId));
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), "/70/{$entity}"));
    }

    #[DataProvider('entities')]
    public function test_readiness_ignores_a_counted_list_whose_mirror_is_not_migrated(string $entity): void
    {
        Schema::drop(self::TABLES[$entity][0]);
        $companyId = $this->company(70);
        $this->fakeSso(70, short: [$entity]);

        $this->assertSame(array_diff_key(self::COUNTS, [$entity => true]), $this->sync($companyId));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function entities(): array
    {
        return array_combine(self::ENTITIES, array_map(fn (string $entity): array => [$entity], self::ENTITIES));
    }

    public function test_purge_cleans_listed_contacts_and_devices_and_never_the_agency_or_state_configuration(): void
    {
        $trial = $this->company(70);
        $other = $this->company(80);
        $this->fakeSso(70);
        $this->sync($trial);

        foreach (self::TABLES as [$table, $column]) {
            foreach (DB::table($table)->where('company_id', $trial)->get() as $row) {
                $copy = (array) $row;
                unset($copy['id']);
                DB::table($table)->insert(['company_id' => $other] + $copy);
            }
        }

        $report = app(TrialMirrorCleaner::class)->clean($trial, [
            'contacts' => [502],
            'devices' => [601, 602],
            'agency' => [301],
            'state_configurations' => [701],
            'personnel' => [13, 14],
        ]);

        $this->assertSame([
            'devices' => ['deleted' => 2, 'deactivated' => 0, 'kept' => 0, 'missing' => 0],
            'contacts' => ['deleted' => 1, 'deactivated' => 0, 'kept' => 0, 'missing' => 0],
        ], $report);

        $this->assertSame([501], DB::table('sso_agency_contacts')->where('company_id', $trial)->pluck('sso_contact_id')->map(fn ($id): int => (int) $id)->all(), 'the owner contact SSO kept stays');
        $this->assertSame(0, DB::table('sso_devices')->where('company_id', $trial)->count());
        $this->assertSame(1, DB::table('sso_agency_profiles')->where('company_id', $trial)->count());
        $this->assertSame(1, DB::table('sso_state_configurations')->where('company_id', $trial)->where('is_active', true)->count());

        foreach (['sso_agency_profiles' => 1, 'sso_agency_contacts' => 2, 'sso_devices' => 2, 'sso_state_configurations' => 1] as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->where('company_id', $other)->count(), "other company's {$table}");
        }
    }
}

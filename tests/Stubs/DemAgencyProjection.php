<?php

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\Agency\AgencyProjection;
use Unified\SsoClient\MasterData\Support\ChildRows;

/**
 * CloudPCR's shape, as a CloudPCR projection would bind it: one
 * `dem_agencies` row per company (dagency_01 state agency id, 02 agency
 * number, 03 name, 04 state, 09 primary service type + `_nv`, 25 NPI;
 * soft-deleted rows are not the company's agency), service areas in
 * `dem_agency_service_areas` (dagency_05 state, 06 county, 08 ZIP) and
 * yearly statistics in `dem_agency_year_stats` (dagency_15 year, 18 call
 * centre volume, 20 transports). The name is the company's name.
 *
 * The states the agency reports to land in `dem_agency_state_licenses`
 * (state FIPS, dagency_01, dagency_02, is_primary, reporting_rule), one
 * row per state, matched by state rather than position because the real
 * table is unique on (company_id, state). The primary row repeats the
 * dem_agencies values.
 *
 * The children are a pure copy of SSO's lists once the Agency page is
 * read-only, so ChildRows::sync() deletes the extras. A starting point for
 * the real one, which also maps the company's time zone to dAgency.23 and
 * writes dem_custom_values.
 */
class DemAgencyProjection implements AgencyProjection
{
    public function table(): string
    {
        return 'dem_agencies';
    }

    public function nameColumn(): string
    {
        return 'dagency_03';
    }

    public function matchColumns(): array
    {
        return [];
    }

    public function scope(Builder $query, int $localCompanyId): Builder
    {
        return $query->where('company_id', $localCompanyId)->whereNull('deleted_at');
    }

    public function insertColumns(int $localCompanyId): array
    {
        return ['company_id' => $localCompanyId];
    }

    public function fromSso(int $localCompanyId, array $record): array
    {
        return [
            'dagency_01' => $record['state_agency_id'] ?? null,
            'dagency_02' => $record['agency_number'] ?? null,
            'dagency_03' => $record['company']['name'] ?? null,
            'dagency_04' => $record['state'] ?? null,
            'dagency_09' => $record['primary_service_type'] ?? null,
            'dagency_09_nv' => $record['primary_service_type_nv'] ?? null,
            'dagency_25' => $record['npi'] ?? null,
        ];
    }

    public function written(int $localCompanyId, int $localId, array $record): void
    {
        if (is_array($record['service_areas'] ?? null)) {
            ChildRows::sync('dem_agency_service_areas', 'dem_agency_id', $localId, array_map(fn (array $area): array => [
                'dagency_05' => $area['state'] ?? null,
                'dagency_06' => $area['county'] ?? null,
                'dagency_08' => $area['zip'] ?? null,
            ], array_values(array_filter($record['service_areas'], 'is_array'))));
        }

        if (is_array($record['year_stats'] ?? null)) {
            ChildRows::sync('dem_agency_year_stats', 'dem_agency_id', $localId, array_map(fn (array $year): array => [
                'dagency_15' => $year['year'] ?? null,
                'dagency_18' => $year['call_center_volume'] ?? null,
                'dagency_20' => $year['transport_volume'] ?? null,
            ], array_values(array_filter($record['year_stats'], 'is_array'))));
        }

        if (is_array($record['licenses'] ?? null)) {
            $this->writeLicenses($localCompanyId, $localId, array_values(array_filter($record['licenses'], 'is_array')));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $licenses
     */
    private function writeLicenses(int $localCompanyId, int $localId, array $licenses): void
    {
        $states = array_map(fn (array $license): string => (string) $license['state'], $licenses);

        DB::table('dem_agency_state_licenses')->where('company_id', $localCompanyId)->whereNotIn('state', $states)->delete();

        foreach ($licenses as $index => $license) {
            DB::table('dem_agency_state_licenses')->updateOrInsert(
                ['company_id' => $localCompanyId, 'state' => (string) $license['state']],
                [
                    'dem_agency_id' => $localId,
                    'dagency_01' => $license['state_agency_id'] ?? null,
                    'dagency_02' => $license['agency_number'] ?? null,
                    'is_primary' => (bool) ($license['is_primary'] ?? false),
                    'reporting_rule' => $license['reporting_rule'] ?? 'all_charts',
                    'sort_order' => $index,
                ],
            );
        }
    }

    public function pushColumns(): array
    {
        return ['dagency_01', 'dagency_02', 'dagency_04', 'dagency_09', 'dagency_09_nv', 'dagency_25'];
    }

    public function toSso(int $localCompanyId, object $row): array
    {
        return array_filter([
            'state_agency_id' => $row->dagency_01,
            'agency_number' => $row->dagency_02,
            'state' => $row->dagency_04,
            'primary_service_type' => $row->dagency_09,
            'primary_service_type_nv' => $row->dagency_09_nv,
            'npi' => $row->dagency_25,
            'service_areas' => DB::table('dem_agency_service_areas')->where('dem_agency_id', $row->id)->orderBy('sort_order')->get()
                ->map(fn (object $area): array => ['state' => $area->dagency_05, 'county' => $area->dagency_06, 'zip' => $area->dagency_08])->all(),
            'year_stats' => DB::table('dem_agency_year_stats')->where('dem_agency_id', $row->id)->orderBy('sort_order')->get()
                ->map(fn (object $year): array => ['year' => (int) $year->dagency_15, 'call_center_volume' => $year->dagency_18 === null ? null : (int) $year->dagency_18, 'transport_volume' => $year->dagency_20 === null ? null : (int) $year->dagency_20])->all(),
            'licenses' => DB::table('dem_agency_state_licenses')->where('company_id', $localCompanyId)->orderBy('sort_order')->get()
                ->map(fn (object $license): array => ['state' => $license->state, 'state_agency_id' => $license->dagency_01, 'agency_number' => $license->dagency_02, 'is_primary' => (bool) $license->is_primary, 'reporting_rule' => $license->reporting_rule])->all(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}

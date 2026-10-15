<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Agency;

use Unified\SsoClient\MasterData\Support\StandardRecordProjection;

/**
 * The default projection: the package-owned `sso_agency_profiles` read
 * model, one row per company, children (service areas, yearly statistics,
 * custom values) kept as JSON text on the row.
 */
class StandardAgencyProjection extends StandardRecordProjection implements AgencyProjection
{
    public const TABLE = 'sso_agency_profiles';

    public function table(): string
    {
        return self::TABLE;
    }

    public function nameColumn(): string
    {
        return 'state_agency_id';
    }

    public function matchColumns(): array
    {
        return [];
    }

    protected function fields(): array
    {
        return [
            'state' => 'state',
            'state_agency_id' => 'state_agency_id',
            'agency_number' => 'agency_number',
            'npi' => 'npi',
            'tax_id' => 'tax_id',
            'tax_status' => 'tax_status',
            'license_number' => 'license_number',
            'is_licensed_agency' => 'is_licensed_agency',
            'primary_service_type' => 'primary_service_type',
            'other_service_types' => 'other_service_types',
            'level_of_service' => 'level_of_service',
            'organization_status' => 'organization_status',
            'organization_type' => 'organization_type',
            'fire_department_id' => 'fire_department_id',
            'neris_department_id' => 'neris_department_id',
            'statistical_year' => 'statistical_year',
            'total_calls' => 'total_calls',
            'address' => 'address',
            'mailing' => 'mailing',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'phone' => 'phone',
            'fax' => 'fax',
            'email' => 'email',
            'website' => 'website',
            'service_areas' => 'service_areas',
            'year_stats' => 'year_stats',
            'custom_values' => 'custom_values',
        ];
    }

    protected function booleanColumns(): array
    {
        return ['is_licensed_agency'];
    }

    protected function jsonColumns(): array
    {
        return ['other_service_types', 'address', 'mailing', 'service_areas', 'year_stats', 'custom_values'];
    }
}

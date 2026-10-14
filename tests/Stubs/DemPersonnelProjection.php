<?php

namespace Unified\SsoClient\Tests\Stubs;

use Unified\SsoClient\MasterData\Personnel\PersonnelProjection;

/**
 * CloudPCR's shape, as CloudPcrPersonnelWriter would bind it (design §3c):
 * `dem_personnel` with the NEMSIS columns the editor writes today
 * (dpersonnel_01..40, `mailing_city`, `dpersonnel_04b`, soft deletes) and
 * five child tables updated in place: `dem_personnel_certifications`
 * (.22-.27), `dem_personnel_cert_levels` (.38/.39), `dem_personnel_phones`,
 * `dem_personnel_emails`, `dem_personnel_immunizations` (.18/.19).
 *
 * Blank nillable recommended elements get `_nv = 7701003` (Not Recorded,
 * design D12) and lose it when a value arrives; nothing else gets an NV.
 * The flat `dpersonnel_09`/`_10` stay filled from the sign-in phone and
 * email (the match key and legacy readers). A starting point for the real
 * one, which also turns the sign-in email/phone into primary contact rows.
 */
class DemPersonnelProjection implements PersonnelProjection
{
    /** Snapshot path => column. */
    private const SCALARS = [
        'user.last_name' => 'dpersonnel_01',
        'user.first_name' => 'dpersonnel_02',
        'user.phone_number' => 'dpersonnel_09',
        'user.email' => 'dpersonnel_10',
        'profile.middle_name' => 'dpersonnel_03',
        'profile.address.street' => 'dpersonnel_04',
        'profile.address.street2' => 'dpersonnel_04b',
        'profile.address.city_gnis' => 'dpersonnel_05',
        'profile.address.city_name' => 'mailing_city',
        'profile.address.state' => 'dpersonnel_06',
        'profile.address.zip' => 'dpersonnel_07',
        'profile.address.country' => 'dpersonnel_08',
        'profile.date_of_birth' => 'dpersonnel_11',
        'profile.citizenship' => 'dpersonnel_14',
        'profile.highest_degree' => 'dpersonnel_15',
        'profile.sex' => 'dpersonnel_40',
        'membership.agency_personnel_id' => 'dpersonnel_21',
        'membership.employment_status' => 'dpersonnel_31',
        'membership.employment_status_date' => 'dpersonnel_32',
        'membership.hire_date' => 'dpersonnel_33',
        'membership.primary_job_role' => 'dpersonnel_34',
        'membership.total_service_years' => 'dpersonnel_36',
        'membership.total_service_documented_on' => 'dpersonnel_37',
    ];

    /** Snapshot path => JSON column. */
    private const LISTS = [
        'profile.race' => 'dpersonnel_13',
        'profile.degree_fields' => 'dpersonnel_16',
        'profile.vehicle_license_types' => 'dpersonnel_17',
        'profile.languages' => 'dpersonnel_20',
        'membership.other_job_roles' => 'dpersonnel_35',
    ];

    /** Nillable recommended elements (XsdElementFacts::NILLABLE, usage E). */
    private const NOT_RECORDED = ['dpersonnel_01', 'dpersonnel_02', 'dpersonnel_03', 'dpersonnel_11', 'dpersonnel_13', 'dpersonnel_31', 'dpersonnel_32', 'dpersonnel_34', 'dpersonnel_35', 'dpersonnel_40'];

    public function table(): string
    {
        return 'dem_personnel';
    }

    public function activeColumn(): string
    {
        return 'is_active';
    }

    public function userColumn(): ?string
    {
        return 'user_id';
    }

    public function emailColumn(): ?string
    {
        return 'dpersonnel_10';
    }

    public function toColumns(int $localCompanyId, array $snapshot): array
    {
        $columns = [];

        foreach (self::SCALARS as $path => $column) {
            if ($this->present($snapshot, $path)) {
                $value = data_get($snapshot, $path);
                $columns[$column] = $value === null || $value === '' ? null : (string) $value;
            }
        }

        foreach (self::LISTS as $path => $column) {
            if ($this->present($snapshot, $path)) {
                $value = data_get($snapshot, $path);
                $columns[$column] = is_array($value) && $value !== [] ? json_encode(array_values($value)) : null;
            }
        }

        if ($this->present($snapshot, 'profile.national_registry')) {
            $registry = $snapshot['profile']['national_registry'];
            $columns['dpersonnel_28'] = is_array($registry) ? ($registry['number'] ?? null) : null;
            $columns['dpersonnel_29'] = is_array($registry) ? ($registry['level'] ?? null) : null;
            $columns['dpersonnel_30'] = is_array($registry) ? ($registry['expires_on'] ?? null) : null;
        }

        if ($this->present($snapshot, 'membership.is_ems_personnel')) {
            $columns['is_ems_personnel'] = (bool) $snapshot['membership']['is_ems_personnel'];
        }

        foreach (self::NOT_RECORDED as $column) {
            if (array_key_exists($column, $columns)) {
                $columns[$column.'_nv'] = $columns[$column] === null ? '7701003' : null;
            }
        }

        // SSO's off switch is is_active; a mirrored person is never soft deleted.
        $columns['deleted_at'] = null;

        return $columns;
    }

    public function written(int $localCompanyId, int $rowId, array $snapshot): void {}

    public function pushColumns(): array
    {
        return [...array_values(self::SCALARS), ...array_values(self::LISTS), 'dpersonnel_28', 'dpersonnel_29', 'dpersonnel_30', 'is_ems_personnel'];
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        $person = [];

        foreach (self::SCALARS as $path => $column) {
            if ($row->{$column} !== null) {
                data_set($person, $path, $row->{$column});
            }
        }

        foreach (self::LISTS as $path => $column) {
            $list = is_string($row->{$column}) ? json_decode($row->{$column}, true) : null;

            if (is_array($list) && $list !== []) {
                data_set($person, $path, $list);
            }
        }

        if ($row->dpersonnel_28 !== null) {
            $person['profile']['national_registry'] = ['number' => $row->dpersonnel_28, 'level' => $row->dpersonnel_29, 'expires_on' => $row->dpersonnel_30];
        }

        $person['membership']['is_ems_personnel'] = (bool) $row->is_ems_personnel;

        return $person;
    }

    public function childTables(): array
    {
        return [
            'licenses' => new MappedPersonnelChildTable('dem_personnel_certifications', 'dem_personnel_id', [
                'state' => 'dpersonnel_22',
                'number' => 'dpersonnel_23',
                'level' => 'dpersonnel_24',
                'current_since' => 'dpersonnel_25',
                'first_issued_on' => 'dpersonnel_26',
                'expires_on' => 'dpersonnel_27',
            ], ['dpersonnel_22']),
            'practice_levels' => new MappedPersonnelChildTable('dem_personnel_cert_levels', 'dem_personnel_id', [
                'level' => 'dpersonnel_38',
                'since' => 'dpersonnel_39',
            ], ['dpersonnel_38']),
            'phones' => new MappedPersonnelChildTable('dem_personnel_phones', 'dem_personnel_id', [
                'number' => 'phone_number',
                'type' => 'phone_type',
            ], ['phone_number']),
            'emails' => new MappedPersonnelChildTable('dem_personnel_emails', 'dem_personnel_id', [
                'address' => 'email_address',
                'type' => 'email_type',
            ], ['email_address']),
            'immunizations' => new MappedPersonnelChildTable('dem_personnel_immunizations', 'dem_personnel_id', [
                'type' => 'dpersonnel_18',
                'year' => 'dpersonnel_19',
            ], ['dpersonnel_18', 'dpersonnel_19']),
        ];
    }

    /**
     * Whether the snapshot carries the path's key (an absent key is an older
     * SSO; leave the column alone).
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function present(array $snapshot, string $path): bool
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);
        $parent = $segments === [] ? $snapshot : data_get($snapshot, implode('.', $segments));

        return is_array($parent) && array_key_exists($last, $parent);
    }
}

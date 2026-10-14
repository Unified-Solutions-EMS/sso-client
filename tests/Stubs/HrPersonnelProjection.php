<?php

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\Personnel\PersonnelProjection;
use Unified\SsoClient\MasterData\Support\ChildRowPolicy;

/**
 * HR's shape, as HrPersonnelWriter would bind it (design §3e):
 * `personnel_profiles` (one per user per company) holds the core columns HR
 * shows; rank, job title, department, notes, emergency contact and
 * `current_status` are HR's own and never written from SSO. The name lives
 * on HR's `users.name`, refreshed in written(). Children: `personnel_phones`,
 * `personnel_emails` and `personnel_licenses`, where only `license_type =
 * 'EMS'` rows are SSO licences: HR's driver's licences share the table, stay
 * local and are never pushed or touched. HR keeps immunizations in another
 * shape (vaccine names, dates) and does not mirror them.
 *
 * Every child table declares `unlink`: the rows are HR's own (a licence row
 * carries HR's approval status, reviewer and card images), so a child SSO
 * removed keeps its row, loses its SSO link and is stamped `sso_removed_at`,
 * which keeps it out of the push. An app mapping SSO children onto its own
 * tables must choose `unlink` or `deactivate`, never `delete`.
 */
class HrPersonnelProjection implements PersonnelProjection
{
    /** Snapshot path => column. */
    private const SCALARS = [
        'user.phone_number' => 'phone',
        'profile.date_of_birth' => 'date_of_birth',
        'profile.address.street' => 'address_line1',
        'profile.address.street2' => 'address_line2',
        'profile.address.city_name' => 'city',
        'profile.address.state' => 'state',
        'profile.address.zip' => 'postal_code',
        'profile.citizenship' => 'citizenship_code',
        'membership.agency_personnel_id' => 'employee_number',
        'membership.hire_date' => 'hire_date',
        'membership.employment_status_date' => 'status_effective_date',
    ];

    public function table(): string
    {
        return 'personnel_profiles';
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
        return null;
    }

    public function toColumns(int $localCompanyId, array $snapshot): array
    {
        $columns = [];

        foreach (self::SCALARS as $path => $column) {
            $segments = explode('.', $path);
            $last = array_pop($segments);
            $parent = data_get($snapshot, implode('.', $segments));

            if (is_array($parent) && array_key_exists($last, $parent)) {
                $columns[$column] = $parent[$last] === '' ? null : $parent[$last];
            }
        }

        if (is_array($snapshot['profile'] ?? null) && array_key_exists('race', $snapshot['profile'])) {
            $columns['race'] = $snapshot['profile']['race'] === [] ? null : json_encode(array_values((array) $snapshot['profile']['race']));
        }

        if (is_array($snapshot['profile'] ?? null) && array_key_exists('national_registry', $snapshot['profile'])) {
            $registry = $snapshot['profile']['national_registry'];
            $columns['national_registry_number'] = is_array($registry) ? ($registry['number'] ?? null) : null;
            $columns['national_registry_level'] = is_array($registry) ? ($registry['level'] ?? null) : null;
            $columns['national_registry_expiration'] = is_array($registry) ? ($registry['expires_on'] ?? null) : null;
        }

        if (is_array($snapshot['membership'] ?? null) && array_key_exists('is_ems_personnel', $snapshot['membership'])) {
            $columns['is_ems_personnel'] = (bool) $snapshot['membership']['is_ems_personnel'];
        }

        return $columns;
    }

    public function written(int $localCompanyId, int $rowId, array $snapshot): void
    {
        $userId = $snapshot['user']['local_id'] ?? null;
        $name = trim(((string) ($snapshot['user']['first_name'] ?? '')).' '.((string) ($snapshot['user']['last_name'] ?? '')));

        if ($userId !== null && $name !== '') {
            DB::table('users')->where('id', $userId)->where('name', '!=', $name)->update(['name' => $name]);
        }
    }

    public function pushColumns(): array
    {
        return [...array_values(self::SCALARS), 'race', 'national_registry_number', 'national_registry_level', 'national_registry_expiration', 'is_ems_personnel', 'gender'];
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        $person = [];

        foreach (self::SCALARS as $path => $column) {
            if ($row->{$column} !== null) {
                data_set($person, $path, $row->{$column});
            }
        }

        $race = is_string($row->race) ? json_decode($row->race, true) : null;

        if (is_array($race) && $race !== []) {
            $person['profile']['race'] = $race;
        }

        if ($row->national_registry_number !== null) {
            $person['profile']['national_registry'] = ['number' => $row->national_registry_number, 'level' => $row->national_registry_level, 'expires_on' => $row->national_registry_expiration];
        }

        if ($row->gender !== null) {
            // Free text in HR; SSO maps what it can to a sex code and keeps the rest as pending.
            $person['profile']['sex'] = $row->gender;
        }

        $person['membership']['is_ems_personnel'] = (bool) $row->is_ems_personnel;

        return $person;
    }

    public function childTables(): array
    {
        return [
            'phones' => new MappedPersonnelChildTable('personnel_phones', 'personnel_profile_id', [
                'number' => 'phone_number',
                'type' => 'phone_type',
                'is_primary' => 'is_primary',
            ], ['phone_number'], onRemoved: ChildRowPolicy::unlink()),
            'emails' => new MappedPersonnelChildTable('personnel_emails', 'personnel_profile_id', [
                'address' => 'email_address',
                'type' => 'email_type',
                'is_primary' => 'is_primary',
            ], ['email_address'], onRemoved: ChildRowPolicy::unlink()),
            'licenses' => new MappedPersonnelChildTable('personnel_licenses', 'personnel_profile_id', [
                'state' => 'state',
                'number' => 'number',
                'first_issued_on' => 'issue_date',
                'expires_on' => 'expiration_date',
                'level' => 'level',
            ], ['license_type', 'state'], ['license_type' => 'EMS'], ChildRowPolicy::unlink()),
        ];
    }
}

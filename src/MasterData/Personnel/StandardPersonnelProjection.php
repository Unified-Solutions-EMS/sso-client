<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Personnel;

use Illuminate\Support\Facades\Schema;

/**
 * The default projection: a package-owned `sso_personnel` read model (one
 * row per person per company) for apps that only look people up (crew
 * pickers, credential checks) and keep no NEMSIS tables of their own. The
 * mirror migration creates it. Licences and practice levels are kept as
 * JSON text on the row; there are no child tables. Only columns the table
 * has are read or written.
 *
 * CloudPCR and HR keep the NEMSIS fields in their own tables and bind their
 * own projection instead (see PersonnelProjection).
 */
class StandardPersonnelProjection implements PersonnelProjection
{
    public const TABLE = 'sso_personnel';

    /**
     * Snapshot path => column, scalar values.
     */
    public const FIELDS = [
        'user.first_name' => 'first_name',
        'user.last_name' => 'last_name',
        'user.email' => 'email',
        'user.phone_number' => 'phone_number',
        'profile.middle_name' => 'middle_name',
        'profile.date_of_birth' => 'date_of_birth',
        'profile.sex' => 'sex',
        'membership.is_ems_personnel' => 'is_ems_personnel',
        'membership.agency_personnel_id' => 'agency_personnel_id',
        'membership.employment_status' => 'employment_status',
        'membership.hire_date' => 'hire_date',
        'membership.primary_job_role' => 'primary_job_role',
    ];

    /**
     * Snapshot path => column, lists kept as JSON text.
     */
    public const LISTS = [
        'licenses' => 'licenses',
        'membership.practice_levels' => 'practice_levels',
    ];

    /**
     * @var array<string, true>|null
     */
    private ?array $columns = null;

    public function table(): string
    {
        return self::TABLE;
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
        return $this->has('email') ? 'email' : null;
    }

    public function toColumns(int $localCompanyId, array $snapshot): array
    {
        $columns = [];

        foreach (self::FIELDS as $path => $column) {
            [$section] = explode('.', $path);

            if ($this->has($column) && is_array($snapshot[$section] ?? null) && array_key_exists(substr($path, strlen($section) + 1), $snapshot[$section])) {
                $value = data_get($snapshot, $path);
                $columns[$column] = is_bool($value) ? $value : ($value === null || $value === '' ? null : (string) $value);
            }
        }

        if ($this->has('division_id') && is_array($snapshot['membership'] ?? null) && array_key_exists('local_division_id', $snapshot['membership'])) {
            $columns['division_id'] = $snapshot['membership']['local_division_id'];
        }

        foreach (self::LISTS as $path => $column) {
            $list = data_get($snapshot, $path);

            if ($this->has($column) && is_array($list)) {
                $columns[$column] = json_encode(array_values(array_map(
                    fn (mixed $item): mixed => is_array($item) ? array_diff_key($item, ['id' => true]) : $item,
                    $list,
                )));
            }
        }

        return $columns;
    }

    public function written(int $localCompanyId, int $rowId, array $snapshot): void {}

    public function pushColumns(): array
    {
        return array_values(array_filter([...array_values(self::FIELDS), ...array_values(self::LISTS)], fn (string $column): bool => $this->has($column)));
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        $person = [];

        foreach (self::FIELDS as $path => $column) {
            if (property_exists($row, $column) && $row->{$column} !== null) {
                data_set($person, $path, $column === 'is_ems_personnel' ? (bool) $row->{$column} : $row->{$column});
            }
        }

        foreach (self::LISTS as $path => $column) {
            $list = property_exists($row, $column) && is_string($row->{$column}) ? json_decode($row->{$column}, true) : null;

            if (is_array($list) && $list !== []) {
                data_set($person, $path, $list);
            }
        }

        return $person;
    }

    public function childTables(): array
    {
        return [];
    }

    private function has(string $column): bool
    {
        $this->columns ??= Schema::hasTable(self::TABLE) ? array_fill_keys(Schema::getColumnListing(self::TABLE), true) : [];

        return isset($this->columns[$column]);
    }
}

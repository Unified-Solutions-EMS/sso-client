<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contacts;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The default projection: the package's own `sso_agency_contacts` table
 * (company_id and the contract's column names, lists as JSON text), created
 * by the mirror migration. Only the columns the table has are read or
 * written, so a table that shares the names but not all of them works.
 */
class StandardContactProjection implements ContactProjection
{
    public const TABLE = 'sso_agency_contacts';

    /**
     * Local column => where it is read from an SSO contact. An address
     * part falls back to the flat `address_*` key when the record does not
     * nest it.
     */
    public const FIELDS = [
        'name' => 'name',
        'type' => 'type',
        'is_primary' => 'is_primary',
        'last_name' => 'last_name',
        'first_name' => 'first_name',
        'middle_name' => 'middle_name',
        'title' => 'title',
        'phones' => 'phones',
        'emails' => 'emails',
        'address_street' => 'address.street',
        'address_street2' => 'address.street2',
        'address_city_gnis' => 'address.city_gnis',
        'address_city_name' => 'address.city_name',
        'address_state' => 'address.state',
        'address_zip' => 'address.zip',
        'address_county' => 'address.county',
        'address_country' => 'address.country',
        'website' => 'website',
        'md_degree' => 'md_degree',
        'md_board_certifications' => 'md_board_certifications',
        'md_compensation' => 'md_compensation',
        'md_board_certified' => 'md_board_certified',
        'sso_user_id' => 'sso_user_id',
    ];

    public const JSON_FIELDS = ['phones', 'emails', 'md_board_certifications'];

    public const BOOLEAN_FIELDS = ['is_primary', 'md_board_certified'];

    /**
     * @var array<string, true>|null
     */
    private ?array $columns = null;

    public function table(): string
    {
        return self::TABLE;
    }

    public function nameColumn(): string
    {
        return 'name';
    }

    public function typeColumn(): string
    {
        return 'type';
    }

    public function lastNameColumn(): string
    {
        return 'last_name';
    }

    public function firstNameColumn(): string
    {
        return 'first_name';
    }

    public function scope(Builder $query, int $localCompanyId): Builder
    {
        return $query->where($this->table().'.company_id', $localCompanyId);
    }

    public function insertColumns(int $localCompanyId): array
    {
        return ['company_id' => $localCompanyId];
    }

    public function toColumns(int $localCompanyId, array $contact): array
    {
        $columns = [];

        foreach ($this->present() as $column) {
            $path = self::FIELDS[$column];
            $value = data_get($contact, $path) ?? (str_contains($path, '.') ? ($contact[str_replace('.', '_', $path)] ?? null) : null);

            if (in_array($column, self::JSON_FIELDS, true)) {
                $value = is_array($value) && $value !== [] ? json_encode(array_values($value)) : null;
            } elseif (in_array($column, self::BOOLEAN_FIELDS, true)) {
                $value = $value === null ? ($column === 'is_primary' ? false : null) : (bool) $value;
            } elseif ($column === 'sso_user_id' && $value !== null) {
                $value = (string) $value;
            }

            $columns[$column] = $value === '' ? null : $value;
        }

        return $columns;
    }

    public function pushColumns(): array
    {
        return $this->present();
    }

    public function toImport(int $localCompanyId, object $row): array
    {
        $fields = [];

        foreach ($this->present() as $column) {
            if ($column === 'name' || $column === 'sso_user_id') {
                continue;
            }

            $value = $row->{$column} ?? null;

            if (in_array($column, self::JSON_FIELDS, true)) {
                $value = is_string($value) ? json_decode($value, true) : $value;
                $value = is_array($value) && $value !== [] ? array_values($value) : null;
            } elseif (in_array($column, self::BOOLEAN_FIELDS, true) && $value !== null) {
                $value = (bool) $value;
            }

            if ($value === null || $value === '') {
                continue;
            }

            data_set($fields, self::FIELDS[$column], $value);
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    protected function present(): array
    {
        $this->columns ??= array_fill_keys(Schema::getColumnListing($this->table()), true);

        return array_values(array_filter(array_keys(self::FIELDS), fn (string $column): bool => isset($this->columns[$column])));
    }
}

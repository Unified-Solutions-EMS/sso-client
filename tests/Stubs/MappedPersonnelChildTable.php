<?php

namespace Unified\SsoClient\Tests\Stubs;

use Unified\SsoClient\MasterData\Personnel\PersonnelChildTable;

/**
 * A personnel child table whose columns map one-to-one onto SSO child
 * fields ({sso_field: local_column}), plus fixed columns written on every
 * row (HR's `license_type = 'EMS'`) and a filter for rows the push leaves
 * out. Enough for the CloudPCR and HR templates.
 */
class MappedPersonnelChildTable implements PersonnelChildTable
{
    /**
     * @param  array<string, string>  $map  SSO field => local column
     * @param  list<string>  $keyColumns
     * @param  array<string, mixed>  $fixed  local column => value written on every SSO child
     */
    public function __construct(
        private readonly string $table,
        private readonly string $parentColumn,
        private readonly array $map,
        private readonly array $keyColumns,
        private readonly array $fixed = [],
    ) {}

    public function table(): string
    {
        return $this->table;
    }

    public function parentColumn(): string
    {
        return $this->parentColumn;
    }

    public function keyColumns(): array
    {
        return $this->keyColumns;
    }

    public function toColumns(array $child): array
    {
        $columns = $this->fixed;

        foreach ($this->map as $field => $column) {
            $value = $child[$field] ?? null;
            $columns[$column] = is_bool($value) ? $value : ($value === null ? null : (string) $value);
        }

        return $columns;
    }

    public function pushColumns(): array
    {
        return array_values(array_unique([...array_values($this->map), ...array_keys($this->fixed)]));
    }

    public function toImport(object $row): array
    {
        foreach ($this->fixed as $column => $value) {
            if (($row->{$column} ?? null) !== $value) {
                return [];
            }
        }

        $import = [];

        foreach ($this->map as $field => $column) {
            $import[$field] = $row->{$column} ?? null;
        }

        return $import;
    }
}

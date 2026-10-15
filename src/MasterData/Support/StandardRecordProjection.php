<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\Contracts\RecordProjection;

/**
 * The package's default read model for a projected entity: a package-owned
 * `sso_*` table with company_id and the contract's own field names as
 * columns (nested fields flattened with `_`: `address.zip` is
 * `address_zip` unless the subclass maps it to one JSON column). Lists and
 * objects are kept as JSON text, instants (`last_seen_at`) as UTC
 * 'Y-m-d H:i:s', and every top-level `<field>_nv` SSO sends (NEMSIS "not
 * value" codes) in one `not_values` JSON column. Only columns the table
 * actually has are read or written, so a table that lacks some still works.
 *
 * A pure mirror: no child tables, nothing of the app's own, so an app that
 * keeps its own shape (CloudPCR's dem_* tables) binds its own projection.
 */
abstract class StandardRecordProjection implements RecordProjection
{
    public const NOT_VALUES_COLUMN = 'not_values';

    /**
     * @var array<string, true>|null
     */
    private ?array $columns = null;

    /**
     * Local column => SSO field path.
     *
     * @return array<string, string>
     */
    abstract protected function fields(): array;

    /**
     * Columns holding a list or an object as JSON text.
     *
     * @return list<string>
     */
    protected function jsonColumns(): array
    {
        return [];
    }

    /**
     * Columns holding a yes/no, sent back as a boolean.
     *
     * @return list<string>
     */
    protected function booleanColumns(): array
    {
        return [];
    }

    /**
     * Columns holding an instant, stored as UTC 'Y-m-d H:i:s' and sent back
     * as ISO 8601.
     *
     * @return list<string>
     */
    protected function timestampColumns(): array
    {
        return [];
    }

    public function scope(Builder $query, int $localCompanyId): Builder
    {
        return $query->where('company_id', $localCompanyId);
    }

    public function insertColumns(int $localCompanyId): array
    {
        return ['company_id' => $localCompanyId];
    }

    public function fromSso(int $localCompanyId, array $record): array
    {
        $columns = [];

        foreach ($this->present() as $column) {
            $columns[$column] = $this->toStored($column, data_get($record, $this->fields()[$column]));
        }

        if ($this->column(self::NOT_VALUES_COLUMN) !== null) {
            $notValues = [];

            foreach ($record as $key => $value) {
                if (is_string($key) && str_ends_with($key, '_nv') && is_scalar($value) && (string) $value !== '') {
                    $notValues[$key] = (string) $value;
                }
            }

            ksort($notValues);
            $columns[self::NOT_VALUES_COLUMN] = $notValues === [] ? null : json_encode($notValues);
        }

        return $columns;
    }

    public function written(int $localCompanyId, int $localId, array $record): void {}

    public function pushColumns(): array
    {
        return [...$this->present(), ...($this->column(self::NOT_VALUES_COLUMN) !== null ? [self::NOT_VALUES_COLUMN] : [])];
    }

    public function toSso(int $localCompanyId, object $row): array
    {
        $fields = [];

        foreach ($this->present() as $column) {
            $value = $this->fromStored($column, $row->{$column} ?? null);

            if ($value !== null && $value !== '') {
                data_set($fields, $this->fields()[$column], $value);
            }
        }

        $notValues = $this->column(self::NOT_VALUES_COLUMN) !== null ? json_decode((string) ($row->{self::NOT_VALUES_COLUMN} ?? ''), true) : null;

        return $fields + (is_array($notValues) ? $notValues : []);
    }

    protected function toStored(string $column, mixed $value): mixed
    {
        if (in_array($column, $this->jsonColumns(), true)) {
            return is_array($value) && $value !== [] ? json_encode($value) : null;
        }

        if (in_array($column, $this->timestampColumns(), true)) {
            return self::utc($value);
        }

        return $value === '' || is_array($value) ? null : $value;
    }

    protected function fromStored(string $column, mixed $value): mixed
    {
        if (in_array($column, $this->jsonColumns(), true)) {
            $decoded = is_string($value) ? json_decode($value, true) : null;

            return is_array($decoded) && $decoded !== [] ? $decoded : null;
        }

        if (in_array($column, $this->booleanColumns(), true)) {
            return $value === null ? null : (bool) $value;
        }

        if (in_array($column, $this->timestampColumns(), true)) {
            $utc = self::utc($value);

            return $utc === null ? null : CarbonImmutable::parse($utc, 'UTC')->toIso8601String();
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    protected function present(): array
    {
        return array_values(array_filter(array_keys($this->fields()), fn (string $column): bool => $this->column($column) !== null));
    }

    protected function column(string $column): ?string
    {
        $this->columns ??= array_fill_keys(Schema::getColumnListing($this->table()), true);

        return isset($this->columns[$column]) ? $column : null;
    }

    private static function utc(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Database\Query\Builder;
use Unified\SsoClient\MasterData\Units\UnitProjection;
use Unified\SsoClient\MasterData\Units\UnitSeatTable;

/**
 * A CAD-shaped unit table for the tests: uuid primary keys, the
 * Unit/Resource in `call_sign`, a display `name`, soft deletes, one-off
 * event units that never mirror, and no seats. The push leaves out a
 * company whose only units are the sample "Medic 1" and "Medic 2".
 */
class UuidUnitProjection implements UnitProjection
{
    public function table(): string
    {
        return 'cad_units';
    }

    public function nameColumn(): string
    {
        return 'call_sign';
    }

    public function matchColumns(): array
    {
        return ['call_sign'];
    }

    public function scope(Builder $query, int $localCompanyId): Builder
    {
        return $query->where('company_id', $localCompanyId)->whereNull('deleted_at')->whereNull('one_off_date');
    }

    public function insertColumns(int $localCompanyId): array
    {
        return ['company_id' => $localCompanyId, 'id' => '00000000-0000-4000-8000-'.str_pad((string) random_int(1, 999999999), 12, '0', STR_PAD_LEFT)];
    }

    public function fromSso(int $localCompanyId, array $record): array
    {
        $name = trim((string) ($record['name'] ?? ''));

        return [
            'call_sign' => $name,
            'name' => trim((string) ($record['display_name'] ?? '')) ?: $name,
            'level_of_care' => $record['level_of_care'] ?? null,
        ];
    }

    public function written(int $localCompanyId, int|string $localId, array $record): void {}

    public function pushColumns(): array
    {
        return ['name', 'call_sign', 'level_of_care'];
    }

    public function toSso(int $localCompanyId, object $row): array
    {
        $callSign = trim((string) $row->call_sign);

        return array_filter([
            'name' => $callSign !== '' ? $callSign : (string) $row->name,
            'display_name' => $callSign !== '' && $row->name !== $callSign ? $row->name : null,
            'level_of_care' => $row->level_of_care,
            'uuid' => $row->id,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function vehicleColumn(): ?string
    {
        return null;
    }

    public function locationColumn(): ?string
    {
        return null;
    }

    public function divisionColumn(): ?string
    {
        return null;
    }

    public function seatTable(): ?UnitSeatTable
    {
        return null;
    }

    public function accepts(int $localCompanyId, array $record): bool
    {
        return ($record['unit_type'] ?? null) !== 'engine';
    }

    public function pushable(int $localCompanyId, array $rows): array
    {
        $names = array_map(fn (object $row): string => (string) $row->name, $rows);
        sort($names);

        return $names === ['Medic 1', 'Medic 2'] ? [] : $rows;
    }
}

<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Units;

/**
 * The package-owned `sso_unit_seats` table under `sso_units`: position,
 * label and the local qualification id. Declares no removal policy, so a
 * seat SSO removed is unlinked and stamped, never deleted.
 */
class StandardUnitSeatTable implements UnitSeatTable
{
    public const TABLE = 'sso_unit_seats';

    public function table(): string
    {
        return self::TABLE;
    }

    public function parentColumn(): string
    {
        return 'unit_id';
    }

    public function positionColumn(): string
    {
        return 'position';
    }

    public function qualificationColumn(): ?string
    {
        return 'qualification_id';
    }

    public function toColumns(array $seat): array
    {
        return [
            'position' => isset($seat['position']) ? (int) $seat['position'] : null,
            'label' => trim((string) ($seat['label'] ?? '')),
            'sort_order' => isset($seat['sort_order']) ? max(0, (int) $seat['sort_order']) : 0,
        ];
    }

    public function pushColumns(): array
    {
        return ['position', 'label'];
    }

    public function toImport(object $row): array
    {
        return array_filter([
            'position' => $row->position === null ? null : (int) $row->position,
            'label' => (string) $row->label,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}

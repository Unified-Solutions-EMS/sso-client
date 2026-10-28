<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Rotations;

use Unified\SsoClient\MasterData\Support\StandardRecordProjection;

/**
 * The default projection: the package-owned `sso_rotations` read model with
 * the contract's field names as columns. Pushes nothing (the rows are SSO's
 * own); an app with rotations of its own to send up (Crew Scheduling's
 * template patterns) extends this and overrides pushRotations() and
 * linkPushed().
 */
class StandardRotationProjection extends StandardRecordProjection implements RotationProjection
{
    public const TABLE = 'sso_rotations';

    public function table(): string
    {
        return self::TABLE;
    }

    public function nameColumn(): string
    {
        return 'name';
    }

    public function matchColumns(): array
    {
        return ['name'];
    }

    public function pushRotations(int $localCompanyId): array
    {
        return [];
    }

    public function linkPushed(int $localCompanyId, int|string $localId, int $ssoId, array $assignments): ?string
    {
        return 'this app keeps no rotations of its own';
    }

    protected function fields(): array
    {
        return [
            'name' => 'name',
            'description' => 'description',
            'cycle_days' => 'cycle_days',
            'anchor_date' => 'anchor_date',
            'pattern' => 'pattern',
            'source_app' => 'source_app',
            'color' => 'color',
            'show_on_calendar' => 'show_on_calendar',
        ];
    }

    protected function booleanColumns(): array
    {
        return ['show_on_calendar'];
    }

    /**
     * `show_on_calendar` is NOT NULL: an SSO that does not send it yet
     * means off. A color that is not a `#rrggbb` hex is dropped rather than
     * cut to fit the column.
     */
    protected function toStored(string $column, mixed $value): mixed
    {
        return match ($column) {
            'show_on_calendar' => (bool) $value,
            'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : null,
            default => parent::toStored($column, $value),
        };
    }
}

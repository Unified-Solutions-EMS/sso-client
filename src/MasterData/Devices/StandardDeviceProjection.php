<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Devices;

use Unified\SsoClient\MasterData\Support\StandardRecordProjection;

/**
 * The default projection: the package-owned `sso_devices` read model,
 * device types as JSON text, `last_seen_at` in UTC, the local vehicle id in
 * `vehicle_id`.
 */
class StandardDeviceProjection extends StandardRecordProjection implements DeviceProjection
{
    public const TABLE = 'sso_devices';

    public function table(): string
    {
        return self::TABLE;
    }

    public function nameColumn(): string
    {
        return 'serial';
    }

    public function matchColumns(): array
    {
        return ['serial'];
    }

    public function vehicleColumn(): ?string
    {
        return $this->column('vehicle_id');
    }

    protected function fields(): array
    {
        return [
            'serial' => 'serial',
            'name' => 'name',
            'device_types' => 'device_types',
            'manufacturer' => 'manufacturer',
            'model' => 'model',
            'purchase_date' => 'purchase_date',
            'source' => 'source',
            'last_seen_at' => 'last_seen_at',
        ];
    }

    protected function jsonColumns(): array
    {
        return ['device_types'];
    }

    protected function timestampColumns(): array
    {
        return ['last_seen_at'];
    }
}

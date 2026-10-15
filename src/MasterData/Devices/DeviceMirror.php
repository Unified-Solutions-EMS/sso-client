<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Devices;

use Unified\SsoClient\MasterData\CatalogMirror;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\ProjectedMirror;

/**
 * Mirrors SSO's devices (NEMSIS dDevice) into the app's device table
 * through the bound DeviceProjection. ProjectedMirror has the shared rules:
 * link by `sso_device_id`, adopt an unlinked local device by serial
 * (ignoring case), stale guard, kept values, turn off never delete (an
 * integration device can be turned off in SSO, never deleted),
 * `device.created|updated|deactivated|batch_updated` and the push.
 *
 * The vehicle: SSO sends its own `vehicle_id`, translated through the
 * vehicles mirror's links the way a location's division is. SSO saying "no
 * vehicle" clears it; a vehicle this app has not linked yet leaves the
 * local value as it is (a new row gets none) until the vehicles mirror
 * catches up. The push sends `vehicle_sso_id` when the local vehicle's link
 * is confirmed, otherwise `vehicle_local_id` (SSO maps it through the links
 * its vehicles import recorded), so push vehicles before devices.
 *
 * CloudPCR also writes single devices up as its monitors report in
 * (DeviceWriteThrough).
 */
class DeviceMirror extends ProjectedMirror
{
    public const SSO_ID_COLUMN = 'sso_device_id';

    /**
     * Local vehicle id keyed by SSO vehicle id, per local company, for this
     * mirror instance (one webhook or one command run).
     *
     * @var array<int, array<int, int>>
     */
    private array $vehicleLinks = [];

    public function __construct(
        LocalTenantResolver $tenants,
        MasterDataClient $client,
        DeviceProjection $projection,
        protected readonly MasterDataRegistry $registry,
    ) {
        parent::__construct($tenants, $client, $projection);
    }

    public function entity(): string
    {
        return 'devices';
    }

    public static function events(): array
    {
        return [
            'device.created',
            'device.updated',
            'device.deactivated',
            'device.batch_updated',
        ];
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }

    protected function recordKey(): string
    {
        return 'device';
    }

    protected function recordName(array $record): string
    {
        $name = trim((string) ($record['name'] ?? ''));

        return $name !== '' ? $name : trim((string) ($record['serial'] ?? ''));
    }

    public function vehicleColumn(): ?string
    {
        $projection = $this->projection;

        return $projection instanceof DeviceProjection ? $projection->vehicleColumn() : null;
    }

    /**
     * The import field naming a local vehicle: `vehicle_sso_id` when this
     * app's link to it is confirmed, otherwise `vehicle_local_id`; nothing
     * for no vehicle.
     *
     * @return array{vehicle_sso_id?: int, vehicle_local_id?: int}
     */
    public function vehicleReference(int $localCompanyId, ?int $localVehicleId): array
    {
        if ($localVehicleId === null || $localVehicleId <= 0) {
            return [];
        }

        $ssoId = array_search($localVehicleId, $this->vehicleLinkMap($localCompanyId, confirmedOnly: true), true);

        return $ssoId === false ? ['vehicle_local_id' => $localVehicleId] : ['vehicle_sso_id' => (int) $ssoId];
    }

    protected function referenceColumns(int $localCompanyId, array $record): array
    {
        $column = $this->vehicleColumn();

        if ($column === null || ! array_key_exists('vehicle_id', $record)) {
            return [];
        }

        if ($record['vehicle_id'] === null) {
            return [$column => null];
        }

        $local = $this->vehicleLinkMap($localCompanyId)[(int) $record['vehicle_id']] ?? null;

        return $local === null ? [] : [$column => $local];
    }

    protected function pushSelect(): array
    {
        $column = $this->vehicleColumn();

        return $column === null ? parent::pushSelect() : [...parent::pushSelect(), $column.' as mirror_vehicle_id'];
    }

    protected function importRow(int $localCompanyId, object $row): array
    {
        $vehicleId = $this->vehicleColumn() === null || $row->mirror_vehicle_id === null ? null : (int) $row->mirror_vehicle_id;

        return parent::importRow($localCompanyId, $row) + $this->vehicleReference($localCompanyId, $vehicleId);
    }

    /**
     * Local vehicle id keyed by SSO vehicle id from this app's vehicles
     * mirror; empty when that mirror is not installed here.
     *
     * @return array<int, int>
     */
    private function vehicleLinkMap(int $localCompanyId, bool $confirmedOnly = false): array
    {
        if (! $confirmedOnly && isset($this->vehicleLinks[$localCompanyId])) {
            return $this->vehicleLinks[$localCompanyId];
        }

        $vehicles = $this->registry->knows('vehicles') ? $this->registry->mirror('vehicles') : null;
        $map = $vehicles instanceof CatalogMirror && $vehicles->isInstalled()
            ? $vehicles->linkedMap($localCompanyId, confirmedOnly: $confirmedOnly)
            : [];

        return $confirmedOnly ? $map : $this->vehicleLinks[$localCompanyId] = $map;
    }
}

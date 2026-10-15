<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Devices;

use Unified\SsoClient\MasterData\Devices\Jobs\WriteThroughDevice;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Writes ONE device up to SSO from the app that discovered it (CloudPCR's
 * Integrations page: a Stryker or ZOLL monitor registering on its first
 * transmission, or an edit of a monitor), so the agency's device list fills
 * itself instead of being typed by hand.
 *
 *     DeviceWriteThrough::send($companyId, [
 *         'local_id' => $monitor->id,
 *         'serial' => $monitor->serial_number,
 *         'name' => $monitor->name,
 *         'device_types' => ['4708001'],
 *         'manufacturer' => 'Stryker',
 *         'model' => $monitor->model,
 *         'source' => 'integration',
 *         'last_seen_at' => now()->toIso8601String(),
 *         'vehicle_local_id' => $monitor->vehicle_id,
 *     ]);
 *
 * The row goes to `POST .../devices/import` alone (SSO matches it on
 * serial and fills only its empty fields; the `device.*` webhook that
 * follows brings the mirror row). A `vehicle_local_id` this app's vehicles
 * mirror has a confirmed link for is sent as `vehicle_sso_id`. Queued
 * after the caller's transaction commits and fail-silent: the job logs
 * and swallows every failure, nothing is returned, and a transmission is
 * never held up or failed by it. Does nothing while the devices mirror is
 * off (before the cutover the push carries the app's devices).
 */
final class DeviceWriteThrough
{
    /**
     * @param  array<string, mixed>  $device  one import row
     */
    public static function send(int $localCompanyId, array $device): void
    {
        if (! app(MasterDataRegistry::class)->enabled('devices')) {
            return;
        }

        WriteThroughDevice::dispatch($localCompanyId, $device)->afterCommit();
    }
}

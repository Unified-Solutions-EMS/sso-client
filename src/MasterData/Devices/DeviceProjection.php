<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Devices;

use Unified\SsoClient\MasterData\Contracts\RecordProjection;

/**
 * How one app stores SSO's devices (Settings > Agency lists > Devices,
 * NEMSIS dDevice: cardiac monitors, ventilators and the other medical
 * devices crews use). DeviceMirror owns the link, stale guard, pending
 * adoption, kept values, turn off never delete, the vehicle translation and
 * the push mapping; the projection owns the app's shape.
 *
 * fromSso() reads one device: `serial`, `name`, `device_types` (codes from
 * the state's device type list), `manufacturer`, `model`, `purchase_date`
 * (Y-m-d), `source` (`integration` | `manual`), `last_seen_at` (ISO 8601
 * instant), `<field>_nv`. It must not write the vehicle: the mirror
 * translates SSO's `vehicle_id` through this app's vehicle links into
 * vehicleColumn(). matchColumns() should name the serial column: SSO
 * matches devices on serial, ignoring case. toSso() sends the same fields
 * (without the vehicle, which the mirror adds as `vehicle_sso_id` or
 * `vehicle_local_id`) for `POST .../devices/import`.
 *
 * The package binds StandardDeviceProjection (`sso_devices`). CloudPCR
 * binds one over `dem_devices` (ddevice_01..06).
 */
interface DeviceProjection extends RecordProjection
{
    /** The local column holding the local vehicle id, or null when devices have no vehicle here. */
    public function vehicleColumn(): ?string;
}

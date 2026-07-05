<?php

namespace Unified\SsoClient\Device;

use Illuminate\Support\Facades\Session;

/**
 * Tracks a successful device handshake for the active session. A binding is
 * scoped to one SSO company and expires after device.session_ttl, so a revoked
 * device is forced back through verification within that window and a device
 * authorized for company A never satisfies a request scoped to company B.
 */
class DeviceSessionState
{
    public const KEY_BINDING = 'sso_device_binding';

    public function bind(int|string $ssoCompanyId, int|string $deviceId, int $ttlSeconds): void
    {
        Session::put(self::KEY_BINDING, [
            'sso_company_id' => (string) $ssoCompanyId,
            'device_id' => $deviceId,
            'expires_at' => now()->addSeconds($ttlSeconds)->timestamp,
        ]);
    }

    public function isVerifiedFor(int|string $ssoCompanyId): bool
    {
        return $this->boundDeviceIdFor($ssoCompanyId) !== null;
    }

    /**
     * The device id this session is bound to for the given company, or null
     * when there is no live binding (absent, expired, or for another company).
     */
    public function boundDeviceIdFor(int|string $ssoCompanyId): int|string|null
    {
        $binding = Session::get(self::KEY_BINDING);

        if (! is_array($binding)) {
            return null;
        }

        if (($binding['sso_company_id'] ?? null) !== (string) $ssoCompanyId) {
            return null;
        }

        $expiresAt = $binding['expires_at'] ?? 0;

        if (! is_int($expiresAt) || now()->timestamp >= $expiresAt) {
            return null;
        }

        return $binding['device_id'] ?? null;
    }

    public function clear(): void
    {
        Session::forget(self::KEY_BINDING);
    }
}

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
        $binding = Session::get(self::KEY_BINDING);

        if (! is_array($binding)) {
            return false;
        }

        if (($binding['sso_company_id'] ?? null) !== (string) $ssoCompanyId) {
            return false;
        }

        $expiresAt = $binding['expires_at'] ?? 0;

        return is_int($expiresAt) && now()->timestamp < $expiresAt;
    }

    public function clear(): void
    {
        Session::forget(self::KEY_BINDING);
    }
}

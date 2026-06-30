<?php

namespace Unified\SsoClient\Device;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Unified\SsoClient\SsoClient;

/**
 * Decision helper for the device-lock middleware. Wraps the (cached) company
 * policy lookup and the pure "does this policy lock this app right now"
 * questions, keeping the middleware thin.
 */
class DeviceGuard
{
    public function __construct(private readonly SsoClient $client) {}

    /**
     * The company's device policy, cached for device.policy_cache_ttl seconds.
     * Returns null when SSO can't be reached (never caches a failure).
     *
     * @return array{mode: string, locked_app_slugs: array<int, string>, grace_until: ?string}|null
     */
    public function policyFor(int|string $ssoCompanyId): ?array
    {
        $ttl = (int) config('sso.device.policy_cache_ttl', 300);
        $key = "sso_device_policy:{$ssoCompanyId}";

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $policy = $this->client->fetchDevicePolicy($ssoCompanyId);

        if ($policy !== null && $ttl > 0) {
            Cache::put($key, $policy, $ttl);
        }

        return $policy;
    }

    /**
     * @param  array{mode: string, locked_app_slugs: array<int, string>, grace_until: ?string}  $policy
     */
    public function appIsLocked(array $policy, string $appSlug): bool
    {
        return match ($policy['mode'] ?? 'off') {
            'all' => true,
            'selected' => in_array($appSlug, $policy['locked_app_slugs'] ?? [], true),
            default => false,
        };
    }

    /**
     * @param  array{mode: string, locked_app_slugs: array<int, string>, grace_until: ?string}  $policy
     */
    public function inGracePeriod(array $policy): bool
    {
        $graceUntil = $policy['grace_until'] ?? null;

        return $graceUntil !== null && Carbon::parse($graceUntil)->isFuture();
    }

    public function forgetPolicy(int|string $ssoCompanyId): void
    {
        Cache::forget("sso_device_policy:{$ssoCompanyId}");
    }
}

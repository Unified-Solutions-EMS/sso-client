<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Support;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One master-data delivery at a time per company and entity, across every
 * app instance (the shared cache, like SsoSingleFlight).
 *
 * SSO fans a bulk change out as several `{entity}.batch_updated` events (200
 * rows each), and a facility import is followed seconds later by a second
 * wave announcing the resolved state codes. Its queue workers POST them in
 * parallel, so the app used to apply four to eight batches for one company at
 * once: CloudPCR's facility phones are replaced with a delete then an insert
 * in one transaction, and two new entries' empty-range deletes take the same
 * gap lock, so the inserts deadlocked and five of eight deliveries answered
 * 500 (UNI-1668). Serializing them removes the contention; the per-row
 * deadlock retry in CatalogMirror covers whatever still overlaps.
 *
 * Fails OPEN. A cache that cannot be reached, or a lock still held after the
 * wait, lets the delivery run unserialized rather than refusing it: SSO waits
 * 30 seconds for an answer, and a 5xx is never sent again.
 */
class MirrorApplyLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $apply
     * @return T
     */
    public function run(string $entity, int $localCompanyId, Closure $apply): mixed
    {
        $lock = $this->acquire($entity, $localCompanyId);

        try {
            return $apply();
        } finally {
            $this->release($lock, $entity, $localCompanyId);
        }
    }

    public static function key(string $entity, int $localCompanyId): string
    {
        return "sso-master-data-apply:{$entity}:{$localCompanyId}";
    }

    private function acquire(string $entity, int $localCompanyId): ?Lock
    {
        $wait = (int) config('sso.master_data.apply_lock_wait_seconds', 20);
        $ttl = (int) config('sso.master_data.apply_lock_ttl_seconds', 120);

        try {
            $lock = Cache::store(config('sso.coordination_store'))->lock(self::key($entity, $localCompanyId), $ttl);
            $lock->block($wait);

            return $lock;
        } catch (LockTimeoutException) {
            Log::info('SSO master data: another delivery still holds the list after the wait, applying alongside it', [
                'entity' => $entity,
                'company_id' => $localCompanyId,
                'waited_seconds' => $wait,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SSO master data: could not take the apply lock, applying without it', [
                'entity' => $entity,
                'company_id' => $localCompanyId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function release(?Lock $lock, string $entity, int $localCompanyId): void
    {
        if ($lock === null) {
            return;
        }

        try {
            $lock->release();
        } catch (\Throwable $e) {
            Log::warning('SSO master data: could not release the apply lock, it expires on its own', [
                'entity' => $entity,
                'company_id' => $localCompanyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

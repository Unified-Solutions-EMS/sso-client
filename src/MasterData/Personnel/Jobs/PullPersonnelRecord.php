<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Personnel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Personnel\PersonnelMirror;

/**
 * Pulls one person's snapshot after a login whose `/api/user` said SSO's
 * copy is newer than this app's (`companies[].personnel_updated_at`), so a
 * dropped webhook heals the next time the person signs in instead of at the
 * nightly resync. Queued after the login transaction commits; on a sync
 * queue it runs inline right after it. Best effort: every failure is
 * logged and swallowed, a login never fails because of it.
 */
class PullPersonnelRecord implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $localCompanyId,
        public int|string $ssoCompanyId,
        public int $ssoUserId,
    ) {}

    public function handle(MasterDataRegistry $registry): void
    {
        try {
            if (! $registry->enabled('personnel')) {
                return;
            }

            $mirror = $registry->mirror('personnel');

            if (! $mirror instanceof PersonnelMirror || ! $mirror->isInstalled()) {
                return;
            }

            $mirror->pull($this->localCompanyId, $this->ssoCompanyId, $this->ssoUserId);
        } catch (\Throwable $e) {
            Log::warning('SSO master data: personnel pull after login failed; the next delivery or resync heals it', [
                'company_id' => $this->localCompanyId,
                'sso_user_id' => $this->ssoUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Trial\AppTrialJobs;
use Unified\SsoClient\Trial\TrialMasterDataSync;

/**
 * `trial.seed_data` from an SSO that seeds the shared lists: mirror them
 * first, then hand over to the app's seeder. Until SSO's lists are here
 * (not ready, SSO unreachable, a list short of SSO's count) the job throws
 * and the queue retries it; after the last try the seeder never runs, so an
 * app whose list is mirrored never invents its own (fail closed).
 *
 * On a sync queue there is no retry: the throw reaches the webhook, which
 * answers 500, and SSO records the delivery as failed.
 */
class SeedTrialAfterMasterDataSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    /** @var list<int> about 15 minutes in all */
    public array $backoff = [30, 60, 120, 240, 480];

    /**
     * @param  array<string>  $userSsoIds
     * @param  array<string, mixed>  $masterData  the payload's `master_data`
     */
    public function __construct(
        public mixed $company,
        public array $userSsoIds,
        public string $adminSsoId,
        public array $masterData,
    ) {}

    public function handle(TrialMasterDataSync $sync, AppTrialJobs $app): void
    {
        $mirrored = $sync->sync((int) $this->company->getKey(), $this->company->sso_company_id, $this->masterData);

        Log::info('SSO trial seed: shared lists mirrored, running the app seeder', [
            'sso_company_id' => $this->company->sso_company_id,
            'mirrored' => $mirrored,
        ]);

        $app->seed($this->company, $this->userSsoIds, $this->adminSsoId);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SSO trial seed: gave up waiting for the shared lists; the trial was not seeded', [
            'sso_company_id' => $this->company->sso_company_id ?? null,
            'error' => $e->getMessage(),
        ]);

        report(new \RuntimeException("Trial seed for SSO company {$this->company->sso_company_id} gave up: {$e->getMessage()}", 0, $e));
    }
}

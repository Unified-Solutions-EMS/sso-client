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
use Unified\SsoClient\Trial\TrialMirrorCleaner;

/**
 * `trial.purge_data` naming the sample lists SSO deleted: the app's purger
 * runs first (in this process, so its extension rows are gone), then the
 * cleaner removes the mirrored rows. A purger that throws stops the job
 * before the cleaner, so the mirror rows stay.
 */
class PurgeTrialThenCleanMirrors implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $seeded  `master_data.seeded`: SSO ids per entity
     */
    public function __construct(
        public mixed $company,
        public string $adminSsoId,
        public array $seeded,
    ) {}

    public function handle(AppTrialJobs $app, TrialMirrorCleaner $cleaner): void
    {
        $app->purgeNow($this->company, $this->adminSsoId);

        $report = $cleaner->clean((int) $this->company->getKey(), $this->seeded);

        Log::info('SSO trial purge: sample list mirror rows cleaned', [
            'sso_company_id' => $this->company->sso_company_id,
            'report' => $report,
        ]);
    }
}

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
use Unified\SsoClient\Trial\Exceptions\TrialMasterDataNotReadyException;
use Unified\SsoClient\Trial\TrialContext;
use Unified\SsoClient\Trial\TrialMasterDataSync;
use Unified\SsoClient\Trial\TrialSeedStatusReporter;

/**
 * `trial.seed_data` from an SSO that seeds the shared lists: mirror them
 * first, then run the app's seeder here, in this job, so it starts without
 * a second trip through the queue. Until SSO's lists are here
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

    /** The app's seeder runs inside this job, and some take minutes. */
    public int $timeout = 300;

    /** @var list<int> about 15 minutes in all */
    /**
     * SSO sends the seed after its lists are complete, so a shortfall is
     * usually a mirror that needs one more pass, not SSO still writing: the
     * first retries are quick (CloudPCR lost 32 s to a 30 s first wait on
     * 2026-11-14), the later ones back off for a real outage.
     */
    public array $backoff = [5, 15, 60, 120, 480];

    /**
     * @param  array<string>  $userSsoIds
     * @param  array<string, mixed>  $masterData  the payload's `master_data`, kept raw so the
     *                                            trial context is built when the job runs
     */
    public function __construct(
        public mixed $company,
        public array $userSsoIds,
        public string $adminSsoId,
        public array $masterData,
    ) {}

    public function handle(TrialMasterDataSync $sync, AppTrialJobs $app): void
    {
        try {
            $mirrored = $sync->sync((int) $this->company->getKey(), $this->company->sso_company_id, $this->masterData);
        } catch (TrialMasterDataNotReadyException $e) {
            Log::warning('SSO trial seed: shared lists not mirrored yet, trying again', [
                'sso_company_id' => $this->company->sso_company_id,
                'attempt' => $this->attempts(),
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('SSO trial seed: shared lists mirrored, running the app seeder', [
            'sso_company_id' => $this->company->sso_company_id,
            'mirrored' => $mirrored,
        ]);

        try {
            $app->seedNow($this->company, $this->userSsoIds, $this->adminSsoId, TrialContext::fromPayload($this->masterData));
        } catch (\Throwable $e) {
            if ($this->job === null) {
                throw $e;
            }

            // The seeder failed, not the lists: retrying would mirror and seed
            // again on top of a half-seeded trial, so this job stops here.
            $this->fail($e);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SSO trial seed: gave up; the trial was not seeded', [
            'sso_company_id' => $this->company->sso_company_id ?? null,
            'error' => $e->getMessage(),
        ]);

        report(new \RuntimeException("Trial seed for SSO company {$this->company->sso_company_id} gave up: {$e->getMessage()}", 0, $e));

        if (isset($this->company->sso_company_id)) {
            app(TrialSeedStatusReporter::class)->report($this->company->sso_company_id, TrialSeedStatusReporter::FAILED);
        }
    }
}

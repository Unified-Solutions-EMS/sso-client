<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Unified\SsoClient\Trial\TrialSeedStatusReporter;

/**
 * Chained after the app's `RunTrialSeeder`, so SSO hears "seeded" only once
 * that job has actually finished. The reporter never throws, so this job
 * never fails the chain it closes.
 */
class ReportTrialSeedStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public int|string $ssoCompanyId,
        public string $status,
    ) {}

    public function handle(TrialSeedStatusReporter $reporter): void
    {
        $reporter->report($this->ssoCompanyId, $this->status);
    }
}

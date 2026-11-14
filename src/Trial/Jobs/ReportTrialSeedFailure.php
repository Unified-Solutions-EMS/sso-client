<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial\Jobs;

use Unified\SsoClient\Trial\TrialSeedStatusReporter;

/**
 * The seed chain's failure handler as a plain object, not a closure. A closure
 * in a chain is serialized with the app key's signature, and an app whose
 * queue worker holds a different key than the web process (Account Manager,
 * 2026-11-14: "Your serialized closure's signature is invalid") could neither
 * run the chain nor report the failure, so SSO's setup bar waited on it until
 * the timeout. An invokable object serializes without a signature.
 */
class ReportTrialSeedFailure
{
    public function __construct(public int|string $ssoCompanyId) {}

    public function __invoke(\Throwable $e): void
    {
        app(TrialSeedStatusReporter::class)->report($this->ssoCompanyId, TrialSeedStatusReporter::FAILED);
    }
}

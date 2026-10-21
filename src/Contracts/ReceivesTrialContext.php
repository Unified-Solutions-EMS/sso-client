<?php

declare(strict_types=1);

namespace Unified\SsoClient\Contracts;

use Unified\SsoClient\Trial\TrialContext;

/**
 * Opt-in for an app's trial seeder (`App\Services\TrialDataSeeder`): the
 * package hands it the trial's place and SSO's seeded names just before
 * `seed()` runs, through the app's `RunTrialSeeder` job as well. Null means
 * SSO sent no context (an older SSO); seed from the app's own defaults then.
 */
interface ReceivesTrialContext
{
    public function withTrialContext(?TrialContext $context): void;
}

<?php

declare(strict_types=1);

namespace Unified\SsoClient\Contracts;

use Unified\SsoClient\Trial\TrialContext;

/**
 * Opt-in for an app's trial seeder (`App\Services\TrialDataSeeder`): the
 * package hands it the trial's place and SSO's seeded names just before
 * `seed()` runs. An app with `App\Jobs\RunTrialSeeder` makes the call
 * itself: the job takes `?TrialContext $context = null` as a fourth
 * constructor argument and calls withTrialContext() before seed(). Null
 * means SSO sent no context (an older SSO); seed from the app's own
 * defaults then.
 */
interface ReceivesTrialContext
{
    public function withTrialContext(?TrialContext $context): void;
}

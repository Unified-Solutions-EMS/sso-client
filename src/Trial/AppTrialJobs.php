<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial;

use Unified\SsoClient\Contracts\ReceivesTrialContext;

/**
 * Runs the app's own trial seeder and purger the way the webhook always has:
 * through `App\Jobs\RunTrialSeeder` / `RunTrialPurger` when the app has
 * them, otherwise straight through `App\Services\TrialDataSeeder` /
 * `TrialDataPurger`.
 *
 * A seeder implementing {@see ReceivesTrialContext} is handed the trial
 * context first. The job is dispatched with the context as a fourth
 * argument: a `RunTrialSeeder` whose constructor takes three ignores it
 * (PHP drops extra arguments), one with a fourth `?TrialContext $context`
 * parameter passes it on to the seeder itself.
 */
class AppTrialJobs
{
    public const SEEDER = 'App\\Services\\TrialDataSeeder';

    public const SEEDER_JOB = 'App\\Jobs\\RunTrialSeeder';

    public const PURGER = 'App\\Services\\TrialDataPurger';

    public const PURGER_JOB = 'App\\Jobs\\RunTrialPurger';

    /**
     * Queued, so the app's job keeps its own retry settings.
     *
     * @param  array<string>  $userSsoIds
     */
    public function seed(mixed $company, array $userSsoIds, string $adminSsoId, ?TrialContext $context = null): void
    {
        if (class_exists(self::SEEDER_JOB)) {
            (self::SEEDER_JOB)::dispatch($company, $userSsoIds, $adminSsoId, $context);

            return;
        }

        $seeder = app(self::SEEDER);

        if ($seeder instanceof ReceivesTrialContext) {
            $seeder->withTrialContext($context);
        }

        $seeder->seed($company, $userSsoIds, $adminSsoId);
    }

    public function purge(mixed $company, string $adminSsoId): void
    {
        if (class_exists(self::PURGER_JOB)) {
            (self::PURGER_JOB)::dispatch($company, $adminSsoId);

            return;
        }

        app(self::PURGER)->purge($company, $adminSsoId);
    }

    /**
     * In this process, so whatever runs next can rely on the purge being done.
     */
    public function purgeNow(mixed $company, string $adminSsoId): void
    {
        if (class_exists(self::PURGER_JOB)) {
            dispatch_sync(new (self::PURGER_JOB)($company, $adminSsoId));

            return;
        }

        app(self::PURGER)->purge($company, $adminSsoId);
    }
}

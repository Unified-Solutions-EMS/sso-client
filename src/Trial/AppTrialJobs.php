<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial;

use Illuminate\Support\Facades\Bus;
use Unified\SsoClient\Contracts\ReceivesTrialContext;
use Unified\SsoClient\Trial\Jobs\ReportTrialSeedStatus;

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
 *
 * SSO's trial welcome modal shows a setup bar until every app has seeded,
 * so `seed()` also tells SSO how it went ({@see TrialSeedStatusReporter}):
 * a queued job is chained with a "seeded" report and a "failed" one if the
 * chain breaks; a seeder run in this process is reported as soon as it
 * returns or throws.
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
     * @return string `pending` when the seeder was queued (SSO hears the outcome
     *                later), `seeded` when it ran here
     */
    public function seed(mixed $company, array $userSsoIds, string $adminSsoId, ?TrialContext $context = null): string
    {
        $ssoCompanyId = $company->sso_company_id ?? null;

        if (class_exists(self::SEEDER_JOB)) {
            $job = new (self::SEEDER_JOB)($company, $userSsoIds, $adminSsoId, $context);

            if ($ssoCompanyId === null || ! method_exists($job, 'chain')) {
                dispatch($job);

                return 'pending';
            }

            Bus::chain([$job, new ReportTrialSeedStatus($ssoCompanyId, TrialSeedStatusReporter::SEEDED)])
                ->catch(function () use ($ssoCompanyId): void {
                    app(TrialSeedStatusReporter::class)->report($ssoCompanyId, TrialSeedStatusReporter::FAILED);
                })
                ->dispatch();

            return 'pending';
        }

        $seeder = app(self::SEEDER);

        if ($seeder instanceof ReceivesTrialContext) {
            $seeder->withTrialContext($context);
        }

        try {
            $seeder->seed($company, $userSsoIds, $adminSsoId);
        } catch (\Throwable $e) {
            $this->reportIfLinked($ssoCompanyId, TrialSeedStatusReporter::FAILED);

            throw $e;
        }

        $this->reportIfLinked($ssoCompanyId, TrialSeedStatusReporter::SEEDED);

        return 'seeded';
    }

    /**
     * The same seeder, run in this process: for a caller that is already a
     * queued job (the master-data sync), so the seeder starts without a
     * second trip through the queue. SSO hears "seeded" once it returns and
     * "failed" if it throws; the throw is passed on.
     *
     * @param  array<string>  $userSsoIds
     */
    public function seedNow(mixed $company, array $userSsoIds, string $adminSsoId, ?TrialContext $context = null): void
    {
        if (! class_exists(self::SEEDER_JOB)) {
            $this->seed($company, $userSsoIds, $adminSsoId, $context);

            return;
        }

        $ssoCompanyId = $company->sso_company_id ?? null;

        try {
            dispatch_sync(new (self::SEEDER_JOB)($company, $userSsoIds, $adminSsoId, $context));
        } catch (\Throwable $e) {
            $this->reportIfLinked($ssoCompanyId, TrialSeedStatusReporter::FAILED);

            throw $e;
        }

        $this->reportIfLinked($ssoCompanyId, TrialSeedStatusReporter::SEEDED);
    }

    private function reportIfLinked(int|string|null $ssoCompanyId, string $status): void
    {
        if ($ssoCompanyId !== null) {
            app(TrialSeedStatusReporter::class)->report($ssoCompanyId, $status);
        }
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

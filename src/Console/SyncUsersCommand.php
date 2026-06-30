<?php

namespace Unified\SsoClient\Console;

use Illuminate\Console\Command;
use Unified\SsoClient\Contracts\SsoUserSynchronizerContract;
use Unified\SsoClient\SsoClient;

class SyncUsersCommand extends Command
{
    protected $signature = 'sso:sync-users {--company= : Limit to a single local company id}';

    protected $description = "Reconcile this app's user roster from SSO so every member exists locally, even those who have never logged in.";

    public function handle(SsoClient $client, SsoUserSynchronizerContract $synchronizer): int
    {
        $slug = (string) config('sso.app_slug');

        if ($slug === '') {
            $this->error('sso.app_slug is not configured; cannot resolve app roles.');

            return self::FAILURE;
        }

        /** @var class-string $companyModel */
        $companyModel = (string) config('sso.company_model', 'App\\Models\\Company');

        $query = $companyModel::query()->whereNotNull('sso_company_id');

        if ($companyId = $this->option('company')) {
            $query->whereKey($companyId);
        }

        $companies = $query->get();

        if ($companies->isEmpty()) {
            $this->info('No companies with an sso_company_id to reconcile.');

            return self::SUCCESS;
        }

        $synced = 0;
        $failures = 0;

        foreach ($companies as $company) {
            try {
                $roster = $client->fetchCompanyRoster($company->sso_company_id, $slug);
            } catch (\Throwable $e) {
                $failures++;
                $this->warn("Roster fetch failed for company {$company->getKey()} (sso {$company->sso_company_id}): {$e->getMessage()}");

                continue;
            }

            foreach ($roster as $payload) {
                [$user] = $synchronizer->synchronize($payload);

                if ($user) {
                    $synced++;
                }
            }
        }

        $this->info("Reconciled {$synced} memberships across {$companies->count()} companies".($failures ? " ({$failures} fetch failures)" : '').'.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\MasterData\Contracts\EntityMirror;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Shared guards for the master-data commands: the entity must be known, its
 * mirror migration applied, and (except for the pre-enable push) enabled in
 * `sso.master_data`.
 */
abstract class MasterDataCommand extends Command
{
    protected function resolveMirror(MasterDataRegistry $registry, string $entity, bool $requireEnabled = true): ?EntityMirror
    {
        if (! $registry->knows($entity)) {
            $this->error("Unknown entity [{$entity}]. Known: ".implode(', ', $registry->entities()).'.');

            return null;
        }

        if ($requireEnabled && ! $registry->enabled($entity)) {
            $this->error("sso.master_data.{$entity} is disabled. Enable it first.");

            return null;
        }

        $mirror = $registry->mirror($entity);

        if (! $mirror->isInstalled()) {
            $this->error("The {$entity} mirror migration has not run. Publish it with `php artisan vendor:publish --tag={$mirror->publishTag()}` and migrate.");

            return null;
        }

        return $mirror;
    }

    /**
     * @return array<int, int|string>|null SSO company id keyed by local company id
     */
    protected function targetCompanies(LocalTenantResolver $tenants): ?array
    {
        $only = $this->option('company');

        if ($only === null || $only === '') {
            return $tenants->linkedCompanies();
        }

        $localCompanyId = $tenants->companyId((string) $only);

        if ($localCompanyId === null) {
            $this->error("No local company is linked to SSO company {$only}.");

            return null;
        }

        return [$localCompanyId => (string) $only];
    }

    /**
     * Ends a run: one warning (console and log) naming every company SSO no
     * longer has, so an operator can clear its stale sso_company_id, then the
     * counts. Skips alone exit 0; only real failures fail the run.
     *
     * @param  array<int, int|string>  $notInSso  SSO company id keyed by local company id
     */
    protected function summarize(int $companies, int $failed, array $notInSso = []): int
    {
        if ($notInSso !== []) {
            $pairs = implode(', ', array_map(
                fn (int|string $local, int|string $sso): string => "local {$local} (SSO {$sso})",
                array_keys($notInSso),
                $notInSso,
            ));

            $this->warn("Skipped, company not in SSO (clear or fix their sso_company_id): {$pairs}");
            Log::warning("{$this->getName()} {$this->argument('entity')}: skipped companies not in SSO", [
                'local_company_ids' => array_keys($notInSso),
                'sso_company_ids' => array_values($notInSso),
            ]);
        }

        $this->info(sprintf(
            '%d compan%s processed, %d failed%s.',
            $companies,
            $companies === 1 ? 'y' : 'ies',
            $failed,
            $notInSso === [] ? '' : sprintf(', %d skipped (not in SSO)', count($notInSso)),
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

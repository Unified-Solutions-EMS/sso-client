<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Console;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\Contracts\EntityMirror;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Healer for a master-data mirror: pulls the full set from SSO for each
 * company and reconciles it locally. Safe to run any number of times.
 *
 * With --link-by-name it instead links existing local rows to SSO rows by
 * name (the per-app cutover step) and prints what still needs a human.
 */
class ResyncMasterDataCommand extends MasterDataCommand
{
    protected $signature = 'sso:resync-master-data
        {entity : Master-data entity to reconcile, e.g. qualifications}
        {--company= : Only this SSO company id}
        {--link-by-name : Link unlinked local rows to SSO rows by name instead of reconciling}';

    protected $description = 'Pull a master-data entity from SSO and reconcile the local mirror';

    public function handle(MasterDataRegistry $registry, MasterDataClient $client, LocalTenantResolver $tenants): int
    {
        $entity = (string) $this->argument('entity');

        $mirror = $this->resolveMirror($registry, $entity);

        if ($mirror === null) {
            return self::FAILURE;
        }

        $companies = $this->targetCompanies($tenants);

        if ($companies === null) {
            return self::FAILURE;
        }

        $failed = 0;

        foreach ($companies as $localCompanyId => $ssoCompanyId) {
            try {
                $snapshot = $client->fetch($entity, $ssoCompanyId);

                $this->option('link-by-name')
                    ? $this->linkByName($mirror, $localCompanyId, $ssoCompanyId, $snapshot)
                    : $this->resync($mirror, $localCompanyId, $ssoCompanyId, $snapshot);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Company {$ssoCompanyId}: {$e->getMessage()}");
                report($e);
            }
        }

        return $this->summarize(count($companies), $failed);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function resync(EntityMirror $mirror, int $localCompanyId, int|string $ssoCompanyId, array $snapshot): void
    {
        $counts = DB::transaction(fn (): array => $mirror->resync($localCompanyId, $snapshot));

        $this->line("Company {$ssoCompanyId} (local {$localCompanyId}): ".collect($counts)
            ->map(fn (int $count, string $key): string => "{$key}={$count}")
            ->implode(' '));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function linkByName(EntityMirror $mirror, int $localCompanyId, int|string $ssoCompanyId, array $snapshot): void
    {
        $result = DB::transaction(fn (): array => $mirror->linkByName($localCompanyId, $snapshot));

        $this->line("Company {$ssoCompanyId} (local {$localCompanyId}): linked ".count($result['linked'])
            .', ambiguous '.count($result['ambiguous'])
            .', local only '.count($result['unmatched_local'])
            .', SSO only '.count($result['unmatched_sso']));

        $review = [];
        foreach ($result['ambiguous'] as $row) {
            $review[] = ['ambiguous', $row['name'], implode(',', $row['local_ids']), implode(',', $row['sso_ids'])];
        }
        foreach ($result['unmatched_local'] as $row) {
            $review[] = ['local only', $row['name'], (string) $row['id'], ''];
        }
        foreach ($result['unmatched_sso'] as $row) {
            $review[] = ['SSO only', $row['name'], '', (string) $row['id']];
        }

        if ($review !== []) {
            $this->table(['Review', 'Name', 'Local ids', 'SSO ids'], $review);
        }
    }
}

<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Console;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\Contracts\SeedsSso;
use Unified\SsoClient\MasterData\Exceptions\MasterDataSyncException;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * One-time upward seed for the per-app cutover, run with the entity still
 * disabled (only the mirror migration is required): sends each company's local
 * catalog and assignments to SSO's import endpoint and links the local rows
 * to the SSO ids in the response mapping, so no name re-match is needed.
 *
 * SSO matches by name on its side, so rerunning creates nothing new there and
 * re-applies the same mapping here.
 */
class PushMasterDataCommand extends MasterDataCommand
{
    protected $signature = 'sso:push-master-data
        {entity : Master-data entity to seed into SSO, e.g. qualifications}
        {--company= : Only this SSO company id}
        {--dry-run : Build and summarize the payload without sending it}';

    protected $description = 'Seed SSO with this app\'s local master data and link the local rows to SSO';

    public function handle(MasterDataRegistry $registry, MasterDataClient $client, LocalTenantResolver $tenants): int
    {
        $entity = (string) $this->argument('entity');

        // The push runs BEFORE the entity is enabled: while the mirror is
        // live, webhooks and logins would already be replacing this app's
        // assignments with SSO's set, which does not include them yet.
        $mirror = $this->resolveMirror($registry, $entity, requireEnabled: false);

        if ($mirror === null) {
            return self::FAILURE;
        }

        if (! $mirror instanceof SeedsSso) {
            $this->error("The {$entity} mirror does not support seeding SSO.");

            return self::FAILURE;
        }

        $companies = $this->targetCompanies($tenants);

        if ($companies === null) {
            return self::FAILURE;
        }

        $failed = 0;

        foreach ($companies as $localCompanyId => $ssoCompanyId) {
            try {
                $this->pushCompany($mirror, $client, $entity, $localCompanyId, $ssoCompanyId);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Company {$ssoCompanyId}: {$e->getMessage()}");
                report($e);
            }
        }

        return $this->summarize(count($companies), $failed);
    }

    private function pushCompany(SeedsSso $mirror, MasterDataClient $client, string $entity, int $localCompanyId, int|string $ssoCompanyId): void
    {
        ['payload' => $payload, 'skipped_users' => $skipped] = $mirror->buildImportPayload($localCompanyId);
        $label = "Company {$ssoCompanyId} (local {$localCompanyId})";

        if ($this->option('dry-run')) {
            $this->line(sprintf(
                '%s [dry run]: would send %d %s and %d users\' assignments; skipped local users without sso_id=%d',
                $label,
                count($payload[$entity] ?? []),
                $entity,
                count($payload['assignments'] ?? []),
                $skipped,
            ));

            return;
        }

        $response = $client->import($entity, $ssoCompanyId, $payload);
        $mapping = $response['mapping'] ?? null;

        if (! is_array($mapping)) {
            throw new MasterDataSyncException('SSO import response has no mapping; nothing was linked.');
        }

        $result = DB::transaction(fn (): array => $mirror->applyImportMapping($localCompanyId, $mapping));
        $conflicts = is_array($response['conflicts'] ?? null) ? $response['conflicts'] : [];
        $unknownUsers = $response['unknown_users'] ?? 0;

        $this->line(sprintf(
            '%s: created=%d matched=%d assignments_added=%d conflicts=%d unknown_users=%d skipped_local_users_without_sso_id=%d linked=%d link_collisions=%d',
            $label,
            (int) ($response['created'] ?? 0),
            (int) ($response['matched'] ?? 0),
            (int) ($response['assignments_added'] ?? 0),
            count($conflicts),
            is_array($unknownUsers) ? count($unknownUsers) : (int) $unknownUsers,
            $skipped,
            $result['applied'],
            count($result['collisions']),
        ));

        if ($conflicts !== []) {
            $this->table(['Conflict', 'Local description', 'SSO description'], array_map(fn ($conflict): array => [
                (string) ($conflict['name'] ?? ''),
                (string) ($conflict['local_description'] ?? ''),
                (string) ($conflict['sso_description'] ?? ''),
            ], array_filter($conflicts, 'is_array')));
        }

        if ($result['collisions'] !== []) {
            $this->table(['Local id', 'SSO id', 'Not linked because'], array_map(fn (array $collision): array => [
                (string) $collision['local_id'],
                (string) $collision['sso_id'],
                $collision['reason'],
            ], $result['collisions']));
        }
    }
}

<?php

declare(strict_types=1);

namespace Unified\SsoClient\Trial;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\Contracts\EntityMirror;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\Trial\Exceptions\TrialMasterDataNotReadyException;

/**
 * Pulls a new trial's shared lists from SSO into this app's mirrors before
 * the app's trial seeder runs, so a seeder whose list is mirrored adopts
 * SSO's rows instead of inventing its own.
 *
 * SSO seeds the lists, then sends `trial.seed_data` with
 * `master_data: {ready, counts: {entity: n}}`. Every entity this app mirrors
 * (flag on, migration run) is resynced for the company, then each mirrored
 * entity SSO counted must hold at least that many active linked rows. Not
 * ready, SSO unreachable, or a short list throws, and the job that called
 * this retries: the app must never seed a list SSO owns.
 */
class TrialMasterDataSync
{
    public function __construct(
        private readonly MasterDataRegistry $registry,
        private readonly MasterDataClient $client,
    ) {}

    /**
     * The mirrors this app has turned on and migrated, keyed by entity.
     *
     * @return array<string, EntityMirror>
     */
    public function mirrors(): array
    {
        $mirrors = [];

        foreach ($this->registry->entities() as $entity) {
            if (! $this->registry->enabled($entity)) {
                continue;
            }

            $mirror = $this->registry->mirror($entity);

            if ($mirror->isInstalled()) {
                $mirrors[$entity] = $mirror;
            }
        }

        return $mirrors;
    }

    /**
     * @param  array<string, mixed>  $masterData  the payload's `master_data`
     * @return array<string, int> active linked rows per resynced entity
     *
     * @throws TrialMasterDataNotReadyException
     */
    public function sync(int $localCompanyId, int|string $ssoCompanyId, array $masterData): array
    {
        if (($masterData['ready'] ?? false) !== true) {
            throw new TrialMasterDataNotReadyException("SSO has not finished seeding the lists of company {$ssoCompanyId}.");
        }

        $mirrored = [];

        foreach ($this->mirrors() as $entity => $mirror) {
            try {
                $snapshot = $this->client->fetch($entity, $ssoCompanyId);
                DB::transaction(fn (): array => $mirror->resync($localCompanyId, $snapshot));
            } catch (\Throwable $e) {
                throw new TrialMasterDataNotReadyException("Could not resync {$entity} for company {$ssoCompanyId}: {$e->getMessage()}", 0, $e);
            }

            $mirrored[$entity] = count($mirror->linkedMap($localCompanyId, activeOnly: true));
        }

        $counts = is_array($masterData['counts'] ?? null) ? $masterData['counts'] : [];
        $short = [];

        foreach ($mirrored as $entity => $count) {
            $expected = (int) ($counts[$entity] ?? 0);

            if ($count < $expected) {
                $short[] = "{$entity} {$count}/{$expected}";
            }
        }

        if ($short !== []) {
            throw new TrialMasterDataNotReadyException("Mirrored lists of company {$ssoCompanyId} are short of what SSO seeded: ".implode(', ', $short).'.');
        }

        return $mirrored;
    }
}

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
        ['payload' => $payload, 'skipped_users' => $skipped, 'truncated_names' => $truncatedNames] = $mirror->buildImportPayload($localCompanyId);
        $label = "Company {$ssoCompanyId} (local {$localCompanyId})";

        foreach ($truncatedNames as $truncated) {
            $this->warn("{$label}: name of local row {$truncated['local_id']} is longer than SSO allows and is sent cut to its first 255 characters: \"{$truncated['name']}\"");
        }

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
            '%s: created=%d matched=%d assignments_added=%d conflicts=%d truncated_descriptions=%d unknown_users=%d skipped_local_users_without_sso_id=%d linked=%d link_collisions=%d',
            $label,
            (int) ($response['created'] ?? 0),
            (int) ($response['matched'] ?? 0),
            (int) ($response['assignments_added'] ?? 0),
            count($conflicts),
            count(array_filter($conflicts, fn ($conflict): bool => is_array($conflict) && (bool) ($conflict['truncated'] ?? false))),
            is_array($unknownUsers) ? count($unknownUsers) : (int) $unknownUsers,
            $skipped,
            $result['applied'],
            count($result['collisions']),
        ).$this->extraCounts($response));

        $this->conflictTable(array_values(array_filter($conflicts, 'is_array')));
        $this->assignmentConflictTable($response['assignment_conflicts'] ?? null);
        $this->invalidTable($response['invalid'] ?? null);

        if ($result['collisions'] !== []) {
            $this->table(['Local id', 'SSO id', 'Not linked because'], array_map(fn (array $collision): array => [
                (string) $collision['local_id'],
                (string) $collision['sso_id'],
                $collision['reason'],
            ], $result['collisions']));
        }
    }

    /**
     * Counts only some entities report (divisions: a person SSO already
     * places elsewhere, rows SSO refused to create).
     *
     * @param  array<string, mixed>  $response
     */
    private function extraCounts(array $response): string
    {
        $extra = '';

        foreach (['assignment_conflicts', 'invalid'] as $key) {
            if (is_array($response[$key] ?? null)) {
                $extra .= sprintf(' %s=%d', $key, count($response[$key]));
            }
        }

        return $extra;
    }

    /**
     * Qualification conflicts compare descriptions; other entities name a
     * reason (divisions: `turned_off_in_sso`, the agency turned the matched
     * row off in SSO while this app still uses it).
     *
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function conflictTable(array $conflicts): void
    {
        if ($conflicts === []) {
            return;
        }

        if (! array_key_exists('local_description', $conflicts[0])) {
            $this->table(['Conflict', 'Reason'], array_map(fn (array $conflict): array => [
                (string) ($conflict['name'] ?? ''),
                (string) ($conflict['reason'] ?? ''),
            ], $conflicts));

            return;
        }

        $this->table(['Conflict', 'Local description', 'SSO description', 'Truncated'], array_map(fn (array $conflict): array => [
            (string) ($conflict['name'] ?? ''),
            (string) ($conflict['local_description'] ?? ''),
            (string) ($conflict['sso_description'] ?? ''),
            ($conflict['truncated'] ?? false) ? 'yes' : 'no',
        ], $conflicts));
    }

    /**
     * People SSO already places in a different single-valued entry (a
     * division). SSO kept its value; after the mirror is enabled the resync
     * applies it here too, so settle these in SSO before enabling.
     */
    private function assignmentConflictTable(mixed $conflicts): void
    {
        if (! is_array($conflicts) || $conflicts === []) {
            return;
        }

        $this->warn('SSO already has a different value for these people and kept it. Settle them in SSO before enabling the mirror:');
        $this->table(['SSO user', 'SSO has', 'This app had'], array_map(fn (mixed $conflict): array => [
            (string) (is_array($conflict) ? ($conflict['user_sso_id'] ?? '') : ''),
            (string) (is_array($conflict) ? ($conflict['sso_division_id'] ?? '') : ''),
            (string) (is_array($conflict) ? ($conflict['incoming_division_id'] ?? '') : ''),
        ], array_values($conflicts)));
    }

    private function invalidTable(mixed $invalid): void
    {
        if (! is_array($invalid) || $invalid === []) {
            return;
        }

        $this->table(['Local id', 'Name', 'Not created in SSO because'], array_map(fn (mixed $row): array => [
            (string) (is_array($row) ? ($row['local_id'] ?? '') : ''),
            (string) (is_array($row) ? ($row['name'] ?? '') : ''),
            (string) (is_array($row) ? ($row['message'] ?? '') : ''),
        ], array_values($invalid)));
    }
}

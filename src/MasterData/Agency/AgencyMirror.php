<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Agency;

use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\ProjectedMirror;

/**
 * Mirrors SSO's agency information (Settings > Agency, NEMSIS dAgency: one
 * profile per company, with its service areas, yearly statistics and
 * state-specific custom values) into the app's agency row through the bound
 * AgencyProjection. ProjectedMirror has the shared rules; a singleton
 * differs in four ways:
 *
 * - SSO's snapshot holds one object (`{company, agency: {...} | null}`, null
 *   until the agency saves one), not a list; null changes nothing here;
 * - adoption takes the company's only local row when it is unlinked, by no
 *   key at all (a second agency row in the company means nothing is adopted
 *   and the push reports it);
 * - there is no on/off switch: nothing is ever turned off or deleted;
 * - the push sends one object, `{app_slug, agency: {local_id, ...}}`, and
 *   SSO fills only its empty fields, reporting the rest in `conflicts`.
 *
 * `agency.updated` carries the full profile (one event per save) and is
 * applied whole; `agency.batch_updated` (an import) is applied the same way.
 */
class AgencyMirror extends ProjectedMirror
{
    public const SSO_ID_COLUMN = 'sso_agency_id';

    public function __construct(LocalTenantResolver $tenants, MasterDataClient $client, AgencyProjection $projection)
    {
        parent::__construct($tenants, $client, $projection);
    }

    public function entity(): string
    {
        return 'agency';
    }

    public static function events(): array
    {
        return [
            'agency.created',
            'agency.updated',
            'agency.batch_updated',
        ];
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }

    protected function recordKey(): string
    {
        return 'agency';
    }

    protected function hasActiveColumn(): bool
    {
        return false;
    }

    protected function requiredColumns(): array
    {
        return [$this->ssoIdColumn(), self::SSO_UPDATED_AT_COLUMN, self::LINK_PENDING_COLUMN];
    }

    protected function recordName(array $record): string
    {
        $company = is_array($record['company'] ?? null) ? $record['company'] : [];

        return (string) ($record['name'] ?? $company['name'] ?? '') ?: 'Agency';
    }

    public function applyWebhook(string $event, int $localCompanyId, array $payload): array
    {
        if (! in_array($event, static::events(), true)) {
            return ['status' => 'ignored', 'reason' => 'unknown_event'];
        }

        $records = $this->records($payload);

        if ($event === 'agency.batch_updated') {
            return $this->applyBatch($localCompanyId, $records);
        }

        return $records === [] ? ['status' => 'ignored', 'reason' => 'no_agency'] : $this->applyUpsertWebhook($event, $localCompanyId, $records[0]);
    }

    /**
     * Applies SSO's profile; nothing is turned off.
     *
     * @return array{created: int, updated: int, linked: int, unchanged: int, deactivated: int}
     */
    public function resync(int $localCompanyId, array $snapshot): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'linked' => 0, 'unchanged' => 0, 'deactivated' => 0];

        foreach ($this->snapshotRecords($snapshot) as $record) {
            $counts[$this->upsert($localCompanyId, $record)]++;
        }

        return $counts;
    }

    /**
     * There is no on/off column: every linked row counts as active.
     */
    public function linkedMap(int $localCompanyId, bool $activeOnly = false, bool $confirmedOnly = false): array
    {
        return parent::linkedMap($localCompanyId, false, $confirmedOnly);
    }

    /**
     * Cutover helper: links the company's only unlinked row to SSO's
     * profile. Never creates, merges or deletes.
     */
    public function linkByName(int $localCompanyId, array $snapshot): array
    {
        $result = ['linked' => [], 'ambiguous' => [], 'unmatched_local' => [], 'unmatched_sso' => []];
        $records = $this->snapshotRecords($snapshot);
        $rows = $this->scoped($localCompanyId)->orderBy('id')->get();
        $unlinked = $rows->whereNull($this->ssoIdColumn())->values();

        if ($records === [] || $rows->whereNotNull($this->ssoIdColumn())->isNotEmpty()) {
            return $result;
        }

        $record = $records[0];

        if ($unlinked->count() !== 1) {
            $result[$unlinked->isEmpty() ? 'unmatched_sso' : 'ambiguous'][] = $unlinked->isEmpty()
                ? ['id' => (int) $record['id'], 'name' => $record['name']]
                : ['name' => $record['name'], 'local_ids' => $unlinked->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(), 'sso_ids' => [(int) $record['id']]];

            return $result;
        }

        $this->confirmLink($localCompanyId, (int) $unlinked[0]->id, (int) $record['id']);
        $result['linked'][] = ['local_id' => (int) $unlinked[0]->id, 'sso_id' => (int) $record['id'], 'name' => $record['name']];

        return $result;
    }

    /**
     * `{app_slug, agency: {local_id, ...}}` from the company's first agency
     * row, or `agency: null` when it has none (the push then sends
     * nothing).
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        $row = $this->scoped($localCompanyId)->orderBy('id')->first(array_values(array_unique(['id', ...$this->projection->pushColumns()])));

        return [
            'payload' => [
                'app_slug' => (string) config('sso.app_slug'),
                'agency' => $row === null ? null : ['local_id' => (int) $row->id] + $this->projection->toSso($localCompanyId, $row),
            ],
            'skipped_users' => 0,
            'truncated_names' => [],
        ];
    }

    /**
     * The company's only row, when it is unlinked.
     */
    protected function findAdoptableFor(int $localCompanyId, array $record): ?object
    {
        $rows = $this->scoped($localCompanyId)->limit(2)->get();

        return $rows->count() === 1 && $rows[0]->{$this->ssoIdColumn()} === null ? $rows[0] : null;
    }

    /**
     * The snapshot's profile (or a batch's rows) as a list, each carrying
     * the company the payload named.
     */
    protected function snapshotRecords(array $snapshot): array
    {
        return parent::snapshotRecords([$this->entity() => $this->records($snapshot)]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function records(array $payload): array
    {
        $agency = $payload['agency'] ?? null;

        if (! is_array($agency) || $agency === []) {
            return [];
        }

        $company = is_array($payload['company'] ?? null) ? $payload['company'] : null;
        $records = array_is_list($agency) ? $agency : [$agency];

        return array_values(array_map(
            fn (array $record): array => $company === null || isset($record['company']) ? $record : $record + ['company' => $company],
            array_filter($records, 'is_array'),
        ));
    }
}

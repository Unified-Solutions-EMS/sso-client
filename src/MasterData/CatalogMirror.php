<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Unified\SsoClient\MasterData\Contracts\EntityMirror;

/**
 * The catalog half every master-data mirror shares: a company-scoped local
 * table whose rows are linked to SSO rows by an SSO id column. Subclasses
 * (QualificationMirror, DivisionMirror) say which columns the entity has and
 * own their assignment rules; this class owns linking, adoption, the stale
 * guard, deactivation and the push mapping, so every entity behaves the same.
 *
 * Every query is the query builder (no Eloquent global scopes) and carries
 * `where('company_id', $localCompanyId)`, where the company id was resolved
 * from an authoritative SSO id by the caller (DEV_GUIDELINES §4a).
 *
 * Rows are never deleted. SSO deleting or turning a row off, or a resync no
 * longer listing it, sets `is_active = false`: apps keep foreign keys to
 * these rows (Crew's locations cascade on delete), so a delete would destroy
 * data the agency still has.
 */
abstract class CatalogMirror implements EntityMirror
{
    public const SSO_UPDATED_AT_COLUMN = 'sso_updated_at';

    /**
     * True on a local row that a webhook or login linked by name before the
     * app pushed its data to SSO. Until the push mapping, --link-by-name or a
     * full resync confirms it, deliveries leave the row's fields and its
     * assignments alone: SSO's view of it does not yet include this app's
     * data, so replacing from SSO would delete it.
     */
    public const LINK_PENDING_COLUMN = 'sso_link_pending';

    public function __construct(
        protected readonly LocalTenantResolver $tenants,
        protected readonly MasterDataClient $client,
    ) {}

    /**
     * The key of one record in a webhook payload (`qualification`, `division`).
     */
    abstract protected function recordKey(): string;

    /**
     * SSO's name limit for the entity; longer local names are cut on push.
     */
    abstract public function maxNameLength(): int;

    /**
     * Normalize an SSO record into the entity's attributes. Must include
     * `name`, `is_active` and `sso_updated_at` (UTC 'Y-m-d H:i:s' or null).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    abstract protected function attributes(array $record): array;

    /**
     * The local columns for a set of attributes. A null `sso_updated_at`
     * (the login payload carries none) keeps the stored one.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    abstract protected function toColumns(array $attributes): array;

    /**
     * Whether a stored row already holds exactly these attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    abstract protected function matches(object $row, array $attributes): bool;

    public function publishTag(): string
    {
        return 'sso-master-data-'.$this->entity();
    }

    /**
     * Insert or update one catalog row from an SSO record.
     *
     * A record with no linked local row adopts an unlinked local row of the
     * same name (case-insensitive) when exactly one exists, so the mirror never
     * creates a duplicate of a row the agency already had.
     *
     * $delivery marks a webhook or login (as opposed to a resync, which is
     * authoritative and current by definition):
     * - a record older than the stored sso_updated_at is ignored ('stale');
     *   SSO queues deliveries, so an older one can arrive after a newer one;
     * - adoption only links the row and marks it pending; the local fields
     *   stay as the agency had them;
     * - a pending row is left untouched ('pending') until confirmed.
     *
     * @param  array<string, mixed>  $record
     * @return 'created'|'updated'|'linked'|'unchanged'|'stale'|'pending'
     */
    public function upsert(int $localCompanyId, array $record, bool $delivery = false): string
    {
        $ssoId = (int) ($record['id'] ?? 0);
        $name = trim((string) ($record['name'] ?? ''));

        if ($ssoId <= 0 || $name === '') {
            throw new InvalidArgumentException("A {$this->recordKey()} record needs an id and a name.");
        }

        $attributes = ['name' => $name] + $this->attributes($record);

        $row = $this->findLinked($localCompanyId, $ssoId);

        if ($delivery && $row !== null) {
            if ((bool) $row->{self::LINK_PENDING_COLUMN}) {
                return 'pending';
            }

            if ($this->isStale($row, $attributes['sso_updated_at'])) {
                return 'stale';
            }
        }

        if ($row === null) {
            $candidate = $this->findAdoptable($localCompanyId, $name);

            if ($candidate === null) {
                $this->insert($localCompanyId, $ssoId, $attributes);

                return 'created';
            }

            $link = $delivery
                ? [$this->ssoIdColumn() => $ssoId, self::LINK_PENDING_COLUMN => true]
                : $this->toColumns($attributes) + [$this->ssoIdColumn() => $ssoId, self::LINK_PENDING_COLUMN => false];

            $this->scoped($localCompanyId)
                ->where('id', $candidate->id)
                ->whereNull($this->ssoIdColumn())
                ->update($link + ['updated_at' => now()]);

            return 'linked';
        }

        if (! (bool) $row->{self::LINK_PENDING_COLUMN} && $this->matches($row, $attributes)) {
            return 'unchanged';
        }

        $this->scoped($localCompanyId)
            ->where('id', $row->id)
            ->update($this->toColumns($attributes) + [self::LINK_PENDING_COLUMN => false, 'updated_at' => now()]);

        return 'updated';
    }

    /**
     * SSO deleted or dropped the row. It is deactivated, never removed. A
     * pending (name-adopted, unconfirmed) row is left alone until confirmed.
     *
     * @return 'deactivated'|'not_found'|'pending'
     */
    public function deactivate(int $localCompanyId, int $ssoId): string
    {
        $row = $ssoId > 0 ? $this->findLinked($localCompanyId, $ssoId) : null;

        if ($row === null) {
            return 'not_found';
        }

        if ((bool) $row->{self::LINK_PENDING_COLUMN}) {
            return 'pending';
        }

        if ((bool) $row->is_active) {
            $this->scoped($localCompanyId)
                ->where('id', $row->id)
                ->update(['is_active' => false, 'updated_at' => now()]);
        }

        return 'deactivated';
    }

    /**
     * Make sure each row named in a login payload exists locally. Existing
     * rows are left as they are and an adopted local row is only linked
     * (pending): the login payload carries only id and name, so it must never
     * overwrite the other fields.
     *
     * @param  array<int, mixed>  $items  [{id, name}, ...]
     * @return list<int> the SSO ids that are now linked locally
     */
    public function ensureFromLoginPayload(int $localCompanyId, array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $ssoId = (int) ($item['id'] ?? 0);
            $name = trim((string) ($item['name'] ?? ''));

            if ($ssoId <= 0) {
                continue;
            }

            if ($this->findLinked($localCompanyId, $ssoId) === null && $name !== '') {
                $this->upsert($localCompanyId, ['id' => $ssoId, 'name' => $name], delivery: true);
            }

            $ids[] = $ssoId;
        }

        return $ids;
    }

    public function linkByName(int $localCompanyId, array $snapshot): array
    {
        $records = $this->snapshotRecords($snapshot);
        $linkedSsoIds = array_flip(array_keys($this->linkedMap($localCompanyId)));

        $ssoByName = [];
        foreach ($records as $record) {
            $ssoId = (int) $record['id'];
            if (isset($linkedSsoIds[$ssoId])) {
                continue;
            }
            $ssoByName[$this->normalizeName((string) $record['name'])][] = ['id' => $ssoId, 'name' => trim((string) $record['name'])];
        }

        $localByName = [];
        foreach ($this->scoped($localCompanyId)->whereNull($this->ssoIdColumn())->orderBy('id')->get(['id', 'name']) as $row) {
            $localByName[$this->normalizeName((string) $row->name)][] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        $result = ['linked' => [], 'ambiguous' => [], 'unmatched_local' => [], 'unmatched_sso' => []];

        foreach ($ssoByName as $key => $ssoRows) {
            $localRows = $localByName[$key] ?? [];
            unset($localByName[$key]);

            if ($localRows === []) {
                array_push($result['unmatched_sso'], ...$ssoRows);

                continue;
            }

            if (count($ssoRows) > 1 || count($localRows) > 1) {
                $result['ambiguous'][] = [
                    'name' => $ssoRows[0]['name'],
                    'local_ids' => array_column($localRows, 'id'),
                    'sso_ids' => array_column($ssoRows, 'id'),
                ];

                continue;
            }

            $this->scoped($localCompanyId)
                ->where('id', $localRows[0]['id'])
                ->whereNull($this->ssoIdColumn())
                ->update([$this->ssoIdColumn() => $ssoRows[0]['id'], self::LINK_PENDING_COLUMN => false, 'updated_at' => now()]);

            $result['linked'][] = ['local_id' => $localRows[0]['id'], 'sso_id' => $ssoRows[0]['id'], 'name' => $localRows[0]['name']];
        }

        foreach ($localByName as $localRows) {
            array_push($result['unmatched_local'], ...$localRows);
        }

        return $result;
    }

    /**
     * Link local rows to the SSO ids the import assigned them. A local row
     * already linked to a different SSO id, or an SSO id another local row of
     * the company already holds (two local spellings SSO matched to one row),
     * is reported and left alone rather than overwritten.
     *
     * @param  array<int|string, int|string|array{sso_id: int|string, updated_at?: string|null}>  $mapping
     * @return array{applied: int, collisions: list<array{local_id: int, sso_id: int, reason: string}>}
     */
    public function applyImportMapping(int $localCompanyId, array $mapping): array
    {
        $pairs = [];
        foreach ($mapping as $localId => $entry) {
            $ssoId = (int) (is_array($entry) ? ($entry['sso_id'] ?? 0) : $entry);

            if ((int) $localId > 0 && $ssoId > 0) {
                $pairs[(int) $localId] = [
                    'sso_id' => $ssoId,
                    // SSO's own timestamp, so the webhooks this same import
                    // dispatched are not judged stale against our clock. The
                    // bare-int form carries none: leave it null so the next
                    // delivery always applies.
                    'updated_at' => is_array($entry) ? $this->normalizeTimestamp($entry['updated_at'] ?? null) : null,
                ];
            }
        }
        ksort($pairs);

        $applied = 0;
        $collisions = [];

        foreach ($pairs as $localId => ['sso_id' => $ssoId, 'updated_at' => $ssoUpdatedAt]) {
            $row = $this->scoped($localCompanyId)->where('id', $localId)->first();

            $reason = match (true) {
                $row === null => 'local_row_missing',
                $row->{$this->ssoIdColumn()} !== null && (int) $row->{$this->ssoIdColumn()} !== $ssoId => 'local_row_linked_to_another_sso_id',
                $this->scoped($localCompanyId)->where($this->ssoIdColumn(), $ssoId)->where('id', '!=', $localId)->exists() => 'sso_id_held_by_another_local_row',
                default => null,
            };

            if ($reason !== null) {
                $collisions[] = ['local_id' => $localId, 'sso_id' => $ssoId, 'reason' => $reason];

                continue;
            }

            $this->scoped($localCompanyId)
                ->where('id', $localId)
                ->update([
                    $this->ssoIdColumn() => $ssoId,
                    self::SSO_UPDATED_AT_COLUMN => $ssoUpdatedAt,
                    self::LINK_PENDING_COLUMN => false,
                ]);
            $applied++;
        }

        return ['applied' => $applied, 'collisions' => $collisions];
    }

    /**
     * Local id keyed by SSO id, for the company's linked rows.
     *
     * @return array<int, int>
     */
    public function linkedMap(int $localCompanyId, bool $activeOnly = false, bool $confirmedOnly = false): array
    {
        $map = [];
        $rows = $this->scoped($localCompanyId)
            ->whereNotNull($this->ssoIdColumn())
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->when($confirmedOnly, fn (Builder $query) => $query->where(self::LINK_PENDING_COLUMN, false))
            ->get(['id', $this->ssoIdColumn()]);

        foreach ($rows as $row) {
            $map[(int) $row->{$this->ssoIdColumn()}] = (int) $row->id;
        }

        return $map;
    }

    /**
     * Upsert every snapshot record and deactivate linked rows SSO no longer
     * lists. Returns the catalog counts a resync reports.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array{created: int, updated: int, linked: int, unchanged: int, deactivated: int}
     */
    protected function reconcileCatalog(int $localCompanyId, array $records): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'linked' => 0, 'unchanged' => 0, 'deactivated' => 0];

        $seen = [];
        foreach ($records as $record) {
            $counts[$this->upsert($localCompanyId, $record)]++;
            $seen[] = (int) $record['id'];
        }

        $counts['deactivated'] = $this->scoped($localCompanyId)
            ->whereNotNull($this->ssoIdColumn())
            ->when($seen !== [], fn ($query) => $query->whereNotIn($this->ssoIdColumn(), $seen))
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function applyUpsertWebhook(string $event, int $localCompanyId, array $record): array
    {
        $result = $this->upsert($localCompanyId, $record, delivery: true);

        if ($result === 'stale') {
            Log::info("SSO master data: stale {$this->recordKey()} delivery ignored", [
                'event' => $event,
                'company_id' => $localCompanyId,
                "sso_{$this->recordKey()}_id" => $record['id'] ?? null,
                'updated_at' => $record['updated_at'] ?? null,
            ]);

            return ['status' => 'stale', 'result' => 'stale'];
        }

        return ['status' => 'ok', 'result' => $result];
    }

    /**
     * SSO does not order webhook deliveries, so an assignment event can arrive
     * before the catalog event it depends on. Pull the company's catalog once
     * and insert the rows we lack, so the assignment is not dropped until the
     * next resync. Existing rows are not touched (their own webhooks carry the
     * stale-delivery guard).
     *
     * @return array<int, int>|null the refreshed linked map, or null when SSO could not be reached
     */
    protected function fetchMissingCatalogRows(int $localCompanyId, mixed $ssoCompanyId): ?array
    {
        if (! is_int($ssoCompanyId) && ! is_string($ssoCompanyId)) {
            return null;
        }

        try {
            $records = $this->snapshotRecords($this->client->fetch($this->entity(), $ssoCompanyId));
        } catch (\Throwable $e) {
            Log::warning('SSO master data: catalog fetch for an early assignment failed, unknown ids skipped', [
                'entity' => $this->entity(),
                'company_id' => $localCompanyId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $linkedMap = $this->linkedMap($localCompanyId);
        foreach ($records as $record) {
            if (! isset($linkedMap[(int) $record['id']])) {
                $this->upsert($localCompanyId, $record, delivery: true);
            }
        }

        return $this->linkedMap($localCompanyId);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    protected function snapshotRecords(array $snapshot): array
    {
        $records = $snapshot[$this->entity()] ?? null;

        if (! is_array($records)) {
            throw new InvalidArgumentException("SSO snapshot is missing the {$this->entity()} list.");
        }

        return array_values(array_filter(
            $records,
            fn ($record): bool => is_array($record) && (int) ($record['id'] ?? 0) > 0 && trim((string) ($record['name'] ?? '')) !== '',
        ));
    }

    /**
     * The company's local rows for the push, each name cut to SSO's limit.
     *
     * @param  list<string>  $columns  extra columns to read besides id and name
     * @return array{rows: list<object>, truncated_names: list<array{local_id: int, name: string}>}
     */
    protected function rowsForPush(int $localCompanyId, array $columns): array
    {
        $rows = [];
        $truncated = [];

        foreach ($this->scoped($localCompanyId)->orderBy('id')->get(['id', 'name', ...$columns]) as $row) {
            $name = (string) $row->name;

            if (mb_strlen($name) > $this->maxNameLength()) {
                $truncated[] = ['local_id' => (int) $row->id, 'name' => $name];
                $row->name = mb_substr($name, 0, $this->maxNameLength());
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'truncated_names' => $truncated];
    }

    protected function scoped(int $localCompanyId): Builder
    {
        return DB::table($this->table())->where('company_id', $localCompanyId);
    }

    protected function findLinked(int $localCompanyId, int $ssoId): ?object
    {
        return $this->scoped($localCompanyId)->where($this->ssoIdColumn(), $ssoId)->first();
    }

    protected function findAdoptable(int $localCompanyId, string $name): ?object
    {
        $key = $this->normalizeName($name);

        $candidates = $this->scoped($localCompanyId)
            ->whereNull($this->ssoIdColumn())
            ->get()
            ->filter(fn (object $row): bool => $this->normalizeName((string) $row->name) === $key);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function insert(int $localCompanyId, int $ssoId, array $attributes): void
    {
        $now = now();

        DB::table($this->table())->insert($this->toColumns($attributes) + [
            'company_id' => $localCompanyId,
            $this->ssoIdColumn() => $ssoId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function isStale(object $row, ?string $incoming): bool
    {
        $stored = $this->normalizeTimestamp($row->{self::SSO_UPDATED_AT_COLUMN});

        return $stored !== null && $incoming !== null && $incoming < $stored;
    }

    /**
     * SSO sends ISO 8601 with an offset; store and compare as UTC
     * 'Y-m-d H:i:s', which also sorts correctly as a string.
     */
    protected function normalizeTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}

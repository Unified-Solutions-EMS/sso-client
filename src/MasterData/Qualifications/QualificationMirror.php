<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Qualifications;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Unified\SsoClient\MasterData\Contracts\EntityMirror;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;

/**
 * Mirrors SSO's qualifications catalog into the app's existing
 * `qualifications` table and the per-company assignments into
 * `company_user_qualifications`.
 *
 * Every query below is the query builder (no Eloquent global scopes) and every
 * one carries `where('company_id', $localCompanyId)`, where the company id was
 * resolved from an authoritative SSO id by the caller (DEV_GUIDELINES §4a).
 *
 * Assignment replacement only touches qualifications that are linked to SSO.
 * Assignments to a local row that has not been linked yet (pre-cutover data)
 * are left alone, so enabling the mirror before `--link-by-name` cannot wipe
 * an agency's existing assignments. Once every row is linked the replacement
 * is total, which is the "full set" semantics of user.qualifications_changed.
 */
class QualificationMirror implements EntityMirror
{
    public const TABLE = 'qualifications';

    public const ASSIGNMENT_TABLE = 'company_user_qualifications';

    public const SSO_ID_COLUMN = 'sso_qualification_id';

    public const SSO_UPDATED_AT_COLUMN = 'sso_updated_at';

    public function __construct(
        private readonly LocalTenantResolver $tenants,
        private readonly MasterDataClient $client,
    ) {}

    public function entity(): string
    {
        return 'qualifications';
    }

    public static function events(): array
    {
        return [
            'qualification.created',
            'qualification.updated',
            'qualification.deleted',
            'user.qualifications_changed',
        ];
    }

    public function table(): string
    {
        return self::TABLE;
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }

    public function isInstalled(): bool
    {
        return Schema::hasTable(self::TABLE)
            && Schema::hasTable(self::ASSIGNMENT_TABLE)
            && Schema::hasColumns(self::TABLE, [self::SSO_ID_COLUMN, 'applies_to', 'is_active', self::SSO_UPDATED_AT_COLUMN]);
    }

    public function applyWebhook(string $event, int $localCompanyId, array $payload): array
    {
        return match ($event) {
            'qualification.created', 'qualification.updated' => $this->applyUpsertWebhook($event, $localCompanyId, (array) ($payload['qualification'] ?? [])),
            'qualification.deleted' => [
                'status' => 'ok',
                'result' => $this->deactivate($localCompanyId, (int) ($payload['qualification']['id'] ?? 0)) ? 'deactivated' : 'not_found',
            ],
            'user.qualifications_changed' => $this->applyAssignmentWebhook($localCompanyId, $payload),
            default => ['status' => 'ignored', 'reason' => 'unknown_event'],
        };
    }

    /**
     * Insert or update one catalog row from an SSO qualification record.
     *
     * A record with no linked local row adopts an unlinked local row of the
     * same name (case-insensitive) when exactly one exists, so the mirror never
     * creates a duplicate of a qualification the agency already had.
     *
     * With $rejectStale (webhook deliveries), a record whose updated_at is
     * older than the stored sso_updated_at is ignored and 'stale' returned:
     * SSO queues deliveries, so an older one can arrive after a newer one.
     * A resync passes false because the snapshot is current by definition.
     *
     * @param  array<string, mixed>  $record
     * @return 'created'|'updated'|'linked'|'unchanged'|'stale'
     */
    public function upsert(int $localCompanyId, array $record, bool $rejectStale = false): string
    {
        $ssoId = (int) ($record['id'] ?? 0);
        $name = trim((string) ($record['name'] ?? ''));

        if ($ssoId <= 0 || $name === '') {
            throw new InvalidArgumentException('A qualification record needs an id and a name.');
        }

        $attributes = [
            'name' => $name,
            'description' => isset($record['description']) && $record['description'] !== '' ? (string) $record['description'] : null,
            'applies_to' => $this->normalizeAppliesTo($record['applies_to'] ?? []),
            'is_active' => array_key_exists('is_active', $record) ? (bool) $record['is_active'] : true,
            'sso_updated_at' => $this->normalizeTimestamp($record['updated_at'] ?? null),
        ];

        $row = $this->findLinked($localCompanyId, $ssoId);

        if ($rejectStale && $row !== null && $this->isStale($row, $attributes['sso_updated_at'])) {
            return 'stale';
        }

        if ($row === null) {
            $candidate = $this->findAdoptable($localCompanyId, $name);

            if ($candidate === null) {
                $this->insert($localCompanyId, $ssoId, $attributes);

                return 'created';
            }

            $this->scoped($localCompanyId)
                ->where('id', $candidate->id)
                ->whereNull(self::SSO_ID_COLUMN)
                ->update($this->toColumns($attributes) + [self::SSO_ID_COLUMN => $ssoId, 'updated_at' => now()]);

            return 'linked';
        }

        if ($this->matches($row, $attributes)) {
            return 'unchanged';
        }

        $this->scoped($localCompanyId)
            ->where('id', $row->id)
            ->update($this->toColumns($attributes) + ['updated_at' => now()]);

        return 'updated';
    }

    /**
     * SSO deleted the qualification. The row is deactivated, never removed:
     * shifts, slots and gates in the app may still reference it.
     */
    public function deactivate(int $localCompanyId, int $ssoId): bool
    {
        if ($ssoId <= 0) {
            return false;
        }

        $row = $this->findLinked($localCompanyId, $ssoId);

        if ($row === null) {
            return false;
        }

        if ((bool) $row->is_active) {
            $this->scoped($localCompanyId)
                ->where('id', $row->id)
                ->update(['is_active' => false, 'updated_at' => now()]);
        }

        return true;
    }

    /**
     * Make sure every qualification named in the /api/user payload exists
     * locally. Existing rows are left as they are (the login payload carries
     * only id and name, so it must not overwrite the catalog fields).
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
                $this->upsert($localCompanyId, ['id' => $ssoId, 'name' => $name]);
            }

            $ids[] = $ssoId;
        }

        return $ids;
    }

    /**
     * Replace one user's linked assignments in one company with exactly the
     * given SSO qualification ids.
     *
     * With $activeOnly the given ids are the user's ACTIVE set (the /api/user
     * and roster payloads carry only active qualifications), so only
     * assignments to active local rows are eligible for removal. Assignments
     * to inactive rows are left for the webhook/resync path, which carries the
     * full set; a login therefore never undoes a webhook.
     *
     * @param  array<int, int|string>  $ssoQualificationIds
     * @param  array<int, int>|null  $linkedMap  local id keyed by SSO id, to skip the lookup in bulk runs
     * @return array{added: int, removed: int, unknown: list<int>}
     */
    public function replaceUserAssignments(int $localCompanyId, int $localUserId, array $ssoQualificationIds, ?array $linkedMap = null, bool $activeOnly = false): array
    {
        $linkedMap ??= $this->linkedMap($localCompanyId);
        $managed = $activeOnly ? $this->linkedMap($localCompanyId, activeOnly: true) : $linkedMap;

        $desired = [];
        $unknown = [];
        foreach ($ssoQualificationIds as $ssoId) {
            $ssoId = (int) $ssoId;
            if (isset($linkedMap[$ssoId])) {
                $desired[$linkedMap[$ssoId]] = true;
            } elseif ($ssoId > 0) {
                $unknown[] = $ssoId;
            }
        }

        if ($linkedMap === []) {
            return ['added' => 0, 'removed' => 0, 'unknown' => $unknown];
        }

        $current = DB::table(self::ASSIGNMENT_TABLE)
            ->where('company_id', $localCompanyId)
            ->where('user_id', $localUserId)
            ->whereIn('qualification_id', array_values($linkedMap))
            ->pluck('qualification_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $remove = array_values(array_intersect(array_diff($current, array_keys($desired)), array_values($managed)));
        $add = array_values(array_diff(array_keys($desired), $current));

        if ($remove !== []) {
            DB::table(self::ASSIGNMENT_TABLE)
                ->where('company_id', $localCompanyId)
                ->where('user_id', $localUserId)
                ->whereIn('qualification_id', $remove)
                ->delete();
        }

        if ($add !== []) {
            $now = now();
            DB::table(self::ASSIGNMENT_TABLE)->insert(array_map(fn (int $qualificationId): array => [
                'user_id' => $localUserId,
                'company_id' => $localCompanyId,
                'qualification_id' => $qualificationId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $add));
        }

        if ($unknown !== []) {
            Log::info('SSO master data: assignment names qualifications not mirrored yet', [
                'company_id' => $localCompanyId,
                'user_id' => $localUserId,
                'sso_qualification_ids' => $unknown,
            ]);
        }

        return ['added' => count($add), 'removed' => count($remove), 'unknown' => $unknown];
    }

    public function resync(int $localCompanyId, array $snapshot): array
    {
        $records = $this->snapshotRecords($snapshot);
        $assignments = $snapshot['assignments'] ?? [];

        if (! is_array($assignments)) {
            throw new InvalidArgumentException('SSO snapshot is missing the assignments list.');
        }

        $counts = [
            'created' => 0,
            'updated' => 0,
            'linked' => 0,
            'unchanged' => 0,
            'deactivated' => 0,
            'assignments_added' => 0,
            'assignments_removed' => 0,
            'unknown_users' => 0,
        ];

        $seen = [];
        foreach ($records as $record) {
            $counts[$this->upsert($localCompanyId, $record)]++;
            $seen[] = (int) $record['id'];
        }

        $counts['deactivated'] = $this->scoped($localCompanyId)
            ->whereNotNull(self::SSO_ID_COLUMN)
            ->when($seen !== [], fn ($query) => $query->whereNotIn(self::SSO_ID_COLUMN, $seen))
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        $linkedMap = $this->linkedMap($localCompanyId);

        $desiredByUser = [];
        foreach ($assignments as $assignment) {
            if (! is_array($assignment) || ! isset($assignment['user_id'])) {
                continue;
            }
            $desiredByUser[(string) $assignment['user_id']] = array_values((array) ($assignment['qualification_ids'] ?? []));
        }

        $localUsers = $this->tenants->userIds(array_keys($desiredByUser));
        $counts['unknown_users'] = count($desiredByUser) - count($localUsers);

        $targets = [];
        foreach ($localUsers as $ssoUserId => $localUserId) {
            $targets[$localUserId] = $desiredByUser[$ssoUserId];
        }

        // A user absent from the snapshot holds no SSO qualification in this
        // company, so their linked assignments are cleared too.
        if ($linkedMap !== []) {
            $holders = DB::table(self::ASSIGNMENT_TABLE)
                ->where('company_id', $localCompanyId)
                ->whereIn('qualification_id', array_values($linkedMap))
                ->distinct()
                ->pluck('user_id');

            foreach ($holders as $holder) {
                $targets[(int) $holder] ??= [];
            }
        }

        foreach ($targets as $localUserId => $ssoQualificationIds) {
            $result = $this->replaceUserAssignments($localCompanyId, $localUserId, $ssoQualificationIds, $linkedMap);
            $counts['assignments_added'] += $result['added'];
            $counts['assignments_removed'] += $result['removed'];
        }

        return $counts;
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
        foreach ($this->scoped($localCompanyId)->whereNull(self::SSO_ID_COLUMN)->orderBy('id')->get(['id', 'name']) as $row) {
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
                ->whereNull(self::SSO_ID_COLUMN)
                ->update([self::SSO_ID_COLUMN => $ssoRows[0]['id'], 'updated_at' => now()]);

            $result['linked'][] = ['local_id' => $localRows[0]['id'], 'sso_id' => $ssoRows[0]['id'], 'name' => $localRows[0]['name']];
        }

        foreach ($localByName as $localRows) {
            array_push($result['unmatched_local'], ...$localRows);
        }

        return $result;
    }

    /**
     * Local qualification id keyed by SSO id, for the company's linked rows.
     *
     * @return array<int, int>
     */
    public function linkedMap(int $localCompanyId, bool $activeOnly = false): array
    {
        $map = [];
        $rows = $this->scoped($localCompanyId)
            ->whereNotNull(self::SSO_ID_COLUMN)
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->get(['id', self::SSO_ID_COLUMN]);

        foreach ($rows as $row) {
            $map[(int) $row->{self::SSO_ID_COLUMN}] = (int) $row->id;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function applyUpsertWebhook(string $event, int $localCompanyId, array $record): array
    {
        $result = $this->upsert($localCompanyId, $record, rejectStale: true);

        if ($result === 'stale') {
            Log::info('SSO master data: stale qualification delivery ignored', [
                'event' => $event,
                'company_id' => $localCompanyId,
                'sso_qualification_id' => $record['id'] ?? null,
                'updated_at' => $record['updated_at'] ?? null,
            ]);

            return ['status' => 'stale', 'result' => 'stale'];
        }

        return ['status' => 'ok', 'result' => $result];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyAssignmentWebhook(int $localCompanyId, array $payload): array
    {
        $localUserId = $this->tenants->userId($payload['user']['id'] ?? null);

        if ($localUserId === null) {
            return ['status' => 'skipped', 'reason' => 'user_not_found'];
        }

        $ssoQualificationIds = array_values((array) ($payload['qualification_ids'] ?? []));
        $linkedMap = $this->linkedMap($localCompanyId);
        $missing = array_filter($ssoQualificationIds, fn ($id): bool => (int) $id > 0 && ! isset($linkedMap[(int) $id]));

        if ($missing !== []) {
            $linkedMap = $this->fetchMissingCatalogRows($localCompanyId, $payload['company']['id'] ?? null) ?? $linkedMap;
        }

        $result = $this->replaceUserAssignments($localCompanyId, $localUserId, $ssoQualificationIds, $linkedMap);

        return ['status' => 'ok'] + $result;
    }

    /**
     * SSO does not order webhook deliveries, so user.qualifications_changed
     * can arrive before the qualification.created it depends on. Pull the
     * company's catalog once and insert the rows we lack, so the assignment
     * is not dropped until the next resync. Existing rows are not touched
     * (their own webhooks carry the stale-delivery guard).
     *
     * @return array<int, int>|null the refreshed linked map, or null when SSO could not be reached
     */
    private function fetchMissingCatalogRows(int $localCompanyId, mixed $ssoCompanyId): ?array
    {
        if (! is_int($ssoCompanyId) && ! is_string($ssoCompanyId)) {
            return null;
        }

        try {
            $records = $this->snapshotRecords($this->client->fetch($this->entity(), $ssoCompanyId));
        } catch (\Throwable $e) {
            Log::warning('SSO master data: catalog fetch for an early assignment failed, unknown ids skipped', [
                'company_id' => $localCompanyId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $linkedMap = $this->linkedMap($localCompanyId);
        foreach ($records as $record) {
            if (! isset($linkedMap[(int) $record['id']])) {
                $this->upsert($localCompanyId, $record);
            }
        }

        return $this->linkedMap($localCompanyId);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function snapshotRecords(array $snapshot): array
    {
        $records = $snapshot['qualifications'] ?? null;

        if (! is_array($records)) {
            throw new InvalidArgumentException('SSO snapshot is missing the qualifications list.');
        }

        return array_values(array_filter(
            $records,
            fn ($record): bool => is_array($record) && (int) ($record['id'] ?? 0) > 0 && trim((string) ($record['name'] ?? '')) !== '',
        ));
    }

    private function scoped(int $localCompanyId): Builder
    {
        return DB::table(self::TABLE)->where('company_id', $localCompanyId);
    }

    private function findLinked(int $localCompanyId, int $ssoId): ?object
    {
        return $this->scoped($localCompanyId)->where(self::SSO_ID_COLUMN, $ssoId)->first();
    }

    private function findAdoptable(int $localCompanyId, string $name): ?object
    {
        $key = $this->normalizeName($name);

        $candidates = $this->scoped($localCompanyId)
            ->whereNull(self::SSO_ID_COLUMN)
            ->get()
            ->filter(fn (object $row): bool => $this->normalizeName((string) $row->name) === $key);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @param  array{name: string, description: ?string, applies_to: list<string>, is_active: bool, sso_updated_at: ?string}  $attributes
     */
    private function insert(int $localCompanyId, int $ssoId, array $attributes): void
    {
        $now = now();

        DB::table(self::TABLE)->insert($this->toColumns($attributes) + [
            'company_id' => $localCompanyId,
            self::SSO_ID_COLUMN => $ssoId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array{name: string, description: ?string, applies_to: list<string>, is_active: bool, sso_updated_at: ?string}  $attributes
     * @return array<string, mixed>
     */
    private function toColumns(array $attributes): array
    {
        $columns = [
            'name' => $attributes['name'],
            'description' => $attributes['description'],
            'applies_to' => json_encode($attributes['applies_to']),
            'is_active' => $attributes['is_active'],
        ];

        // A record without updated_at (the login payload) keeps the stored one.
        if ($attributes['sso_updated_at'] !== null) {
            $columns[self::SSO_UPDATED_AT_COLUMN] = $attributes['sso_updated_at'];
        }

        return $columns;
    }

    /**
     * @param  array{name: string, description: ?string, applies_to: list<string>, is_active: bool, sso_updated_at: ?string}  $attributes
     */
    private function matches(object $row, array $attributes): bool
    {
        $storedAppliesTo = is_string($row->applies_to) ? json_decode($row->applies_to, true) : null;

        return (string) $row->name === $attributes['name']
            && ($row->description === null ? null : (string) $row->description) === $attributes['description']
            && $this->normalizeAppliesTo(is_array($storedAppliesTo) ? $storedAppliesTo : []) === $attributes['applies_to']
            && (bool) $row->is_active === $attributes['is_active']
            && ($attributes['sso_updated_at'] === null || $this->normalizeTimestamp($row->{self::SSO_UPDATED_AT_COLUMN}) === $attributes['sso_updated_at']);
    }

    private function isStale(object $row, ?string $incoming): bool
    {
        $stored = $this->normalizeTimestamp($row->{self::SSO_UPDATED_AT_COLUMN});

        return $stored !== null && $incoming !== null && $incoming < $stored;
    }

    /**
     * SSO sends ISO 8601 with an offset; store and compare as UTC
     * 'Y-m-d H:i:s', which also sorts correctly as a string.
     */
    private function normalizeTimestamp(mixed $value): ?string
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

    /**
     * @return list<string>
     */
    private function normalizeAppliesTo(mixed $appliesTo): array
    {
        $slugs = array_unique(array_filter(
            array_map(fn ($slug): string => trim((string) $slug), is_array($appliesTo) ? $appliesTo : []),
            fn (string $slug): bool => $slug !== '',
        ));
        sort($slugs);

        return array_values($slugs);
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}

<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Qualifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Unified\SsoClient\MasterData\CatalogMirror;
use Unified\SsoClient\MasterData\Contracts\SeedsSso;

/**
 * Mirrors SSO's qualifications catalog into the app's existing
 * `qualifications` table and the per-company assignments into
 * `company_user_qualifications`.
 *
 * Linking, name adoption, the stale guard and the push mapping come from
 * CatalogMirror. Every query is the query builder (no Eloquent global scopes)
 * and carries `where('company_id', $localCompanyId)`, where the company id was
 * resolved from an authoritative SSO id by the caller (DEV_GUIDELINES §4a).
 *
 * Assignment replacement only touches qualifications that are linked to SSO.
 * Assignments to a local row that has not been linked yet (pre-cutover data)
 * are left alone, so enabling the mirror before `--link-by-name` cannot wipe
 * an agency's existing assignments. Once every row is linked the replacement
 * is total, which is the "full set" semantics of user.qualifications_changed.
 */
class QualificationMirror extends CatalogMirror implements SeedsSso
{
    public const TABLE = 'qualifications';

    public const ASSIGNMENT_TABLE = 'company_user_qualifications';

    public const SSO_ID_COLUMN = 'sso_qualification_id';

    /**
     * SSO's qualification name limit; longer local names are cut on push.
     */
    public const MAX_NAME_LENGTH = 255;

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

    public function publishTag(): string
    {
        return 'sso-master-data';
    }

    public function maxNameLength(): int
    {
        return self::MAX_NAME_LENGTH;
    }

    protected function recordKey(): string
    {
        return 'qualification';
    }

    public function isInstalled(): bool
    {
        return Schema::hasTable(self::TABLE)
            && Schema::hasTable(self::ASSIGNMENT_TABLE)
            && Schema::hasColumns(self::TABLE, [self::SSO_ID_COLUMN, 'applies_to', 'is_active', self::SSO_UPDATED_AT_COLUMN, self::LINK_PENDING_COLUMN]);
    }

    public function applyWebhook(string $event, int $localCompanyId, array $payload): array
    {
        return match ($event) {
            'qualification.created', 'qualification.updated' => $this->applyUpsertWebhook($event, $localCompanyId, (array) ($payload['qualification'] ?? [])),
            'qualification.deleted' => [
                'status' => 'ok',
                'result' => $this->deactivate($localCompanyId, (int) ($payload['qualification']['id'] ?? 0)),
            ],
            'user.qualifications_changed' => $this->applyAssignmentWebhook($localCompanyId, $payload),
            default => ['status' => 'ignored', 'reason' => 'unknown_event'],
        };
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
     * Pending rows (name-adopted before this app pushed) are never removed:
     * SSO's set for them does not include this app's holders yet.
     *
     * @param  array<int, int|string>  $ssoQualificationIds
     * @param  array<int, int>|null  $linkedMap  local id keyed by SSO id, to skip the lookup in bulk runs
     * @return array{added: int, removed: int, unknown: list<int>}
     */
    public function replaceUserAssignments(int $localCompanyId, int $localUserId, array $ssoQualificationIds, ?array $linkedMap = null, bool $activeOnly = false): array
    {
        $linkedMap ??= $this->linkedMap($localCompanyId);
        $managed = $this->linkedMap($localCompanyId, activeOnly: $activeOnly, confirmedOnly: true);

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

        $counts = $this->reconcileCatalog($localCompanyId, $records) + [
            'assignments_added' => 0,
            'assignments_removed' => 0,
            'unknown_users' => 0,
        ];

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

    /**
     * The company's whole local catalog and every assignment to it, in the
     * shape of SSO's POST .../qualifications/import. Assignments travel by the
     * user's SSO id; local users without one cannot be represented in SSO and
     * are only counted. Names longer than SSO's 255-character limit are cut
     * and reported in truncated_names.
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        ['rows' => $rows, 'truncated_names' => $truncatedNames] = $this->rowsForPush($localCompanyId, ['description', 'is_active']);
        $qualifications = [];

        foreach ($rows as $row) {
            $qualifications[] = [
                'local_id' => (int) $row->id,
                'name' => (string) $row->name,
                'description' => $row->description === null ? null : (string) $row->description,
                'is_active' => (bool) $row->is_active,
            ];
        }

        $byUser = [];
        $skipped = [];

        if ($qualifications !== []) {
            $rows = DB::table(self::ASSIGNMENT_TABLE)
                ->join('users', 'users.id', '=', self::ASSIGNMENT_TABLE.'.user_id')
                ->where(self::ASSIGNMENT_TABLE.'.company_id', $localCompanyId)
                ->whereIn(self::ASSIGNMENT_TABLE.'.qualification_id', array_column($qualifications, 'local_id'))
                ->orderBy(self::ASSIGNMENT_TABLE.'.user_id')
                ->orderBy(self::ASSIGNMENT_TABLE.'.qualification_id')
                ->get([self::ASSIGNMENT_TABLE.'.user_id', 'users.sso_id', self::ASSIGNMENT_TABLE.'.qualification_id']);

            foreach ($rows as $row) {
                $ssoUserId = trim((string) ($row->sso_id ?? ''));

                if ($ssoUserId === '') {
                    $skipped[(int) $row->user_id] = true;

                    continue;
                }

                $byUser[$ssoUserId][(int) $row->qualification_id] = true;
            }
        }

        $assignments = [];
        foreach ($byUser as $ssoUserId => $qualificationIds) {
            $assignments[] = [
                'user_sso_id' => (string) $ssoUserId,
                'local_qualification_ids' => array_keys($qualificationIds),
            ];
        }

        return [
            'payload' => [
                'app_slug' => (string) config('sso.app_slug'),
                'qualifications' => $qualifications,
                'assignments' => $assignments,
            ],
            'skipped_users' => count($skipped),
            'truncated_names' => $truncatedNames,
        ];
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

    protected function attributes(array $record): array
    {
        return [
            'description' => isset($record['description']) && $record['description'] !== '' ? (string) $record['description'] : null,
            'applies_to' => $this->normalizeAppliesTo($record['applies_to'] ?? []),
            'is_active' => array_key_exists('is_active', $record) ? (bool) $record['is_active'] : true,
            'sso_updated_at' => $this->normalizeTimestamp($record['updated_at'] ?? null),
        ];
    }

    /**
     * @param  array{name: string, description: ?string, applies_to: list<string>, is_active: bool, sso_updated_at: ?string}  $attributes
     * @return array<string, mixed>
     */
    protected function toColumns(array $attributes): array
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
    protected function matches(object $row, array $attributes): bool
    {
        $storedAppliesTo = is_string($row->applies_to) ? json_decode($row->applies_to, true) : null;

        return (string) $row->name === $attributes['name']
            && ($row->description === null ? null : (string) $row->description) === $attributes['description']
            && $this->normalizeAppliesTo(is_array($storedAppliesTo) ? $storedAppliesTo : []) === $attributes['applies_to']
            && (bool) $row->is_active === $attributes['is_active']
            && ($attributes['sso_updated_at'] === null || $this->normalizeTimestamp($row->{self::SSO_UPDATED_AT_COLUMN}) === $attributes['sso_updated_at']);
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
}

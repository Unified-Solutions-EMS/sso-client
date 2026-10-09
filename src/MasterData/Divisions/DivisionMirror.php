<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Divisions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Unified\SsoClient\MasterData\CatalogMirror;
use Unified\SsoClient\MasterData\Contracts\SeedsSso;

/**
 * Mirrors SSO's divisions (an agency list: a group of stations, or whatever
 * the agency already called a division) into a local `divisions` table, and
 * each person's division into a column on the company membership pivot
 * (`company_user.division_id`, one division per person per company).
 *
 * Crew-Scheduling already has both (its divisions are referenced by locations,
 * resources and shift templates); HR gets them from the published migration.
 * An app whose table or column names differ binds a subclass that overrides
 * table(), assignmentTable() or assignmentColumn() (the registry resolves
 * mirrors through the container).
 *
 * Rows are never deleted: SSO turns divisions off, and so does the mirror.
 * Crew's locations and resources cascade-delete with their division, so a
 * delete here would destroy the agency's schedule data.
 *
 * Assignment writes only ever replace a division that is a confirmed SSO-linked
 * row (or none). A person whose local division is still unlinked (pre-cutover)
 * or pending (name-adopted before this app pushed) keeps it until the push or a
 * resync confirms the link, so enabling the mirror early cannot wipe them.
 */
class DivisionMirror extends CatalogMirror implements SeedsSso
{
    public const TABLE = 'divisions';

    public const ASSIGNMENT_TABLE = 'company_user';

    public const ASSIGNMENT_COLUMN = 'division_id';

    public const SSO_ID_COLUMN = 'sso_division_id';

    /**
     * SSO's division name limit; longer local names are cut on push.
     */
    public const MAX_NAME_LENGTH = 100;

    public function entity(): string
    {
        return 'divisions';
    }

    public static function events(): array
    {
        return [
            'division.created',
            'division.updated',
            'division.deactivated',
            'user.division_changed',
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

    /**
     * The company membership pivot (one row per user and company).
     */
    public function assignmentTable(): string
    {
        return self::ASSIGNMENT_TABLE;
    }

    /**
     * The column on the membership pivot holding the local division id.
     */
    public function assignmentColumn(): string
    {
        return self::ASSIGNMENT_COLUMN;
    }

    public function maxNameLength(): int
    {
        return self::MAX_NAME_LENGTH;
    }

    protected function recordKey(): string
    {
        return 'division';
    }

    public function isInstalled(): bool
    {
        return Schema::hasTable($this->table())
            && Schema::hasTable($this->assignmentTable())
            && Schema::hasColumns($this->table(), [$this->ssoIdColumn(), 'code', 'is_active', 'sort_order', self::SSO_UPDATED_AT_COLUMN, self::LINK_PENDING_COLUMN])
            && Schema::hasColumn($this->assignmentTable(), $this->assignmentColumn());
    }

    public function applyWebhook(string $event, int $localCompanyId, array $payload): array
    {
        return match ($event) {
            'division.created', 'division.updated', 'division.deactivated' => $this->applyUpsertWebhook($event, $localCompanyId, (array) ($payload['division'] ?? [])),
            'user.division_changed' => $this->applyAssignmentWebhook($localCompanyId, $payload),
            default => ['status' => 'ignored', 'reason' => 'unknown_event'],
        };
    }

    /**
     * The person's local division id in the company, or null.
     */
    public function currentDivisionId(int $localCompanyId, int $localUserId): ?int
    {
        $id = $this->membership($localCompanyId, $localUserId)?->{$this->assignmentColumn()};

        return $id === null ? null : (int) $id;
    }

    /**
     * Set one person's division in one company to the local row linked to
     * the given SSO division id, or to none for null.
     *
     * The current value is only replaced when it is none or a confirmed
     * SSO-linked row ('kept_unconfirmed' otherwise, see the class doc). An
     * SSO id with no linked local row changes nothing ('unknown_division').
     *
     * @param  array<int, int>|null  $linkedMap  local id keyed by SSO id, to skip the lookup in bulk runs
     * @param  list<int>|null  $confirmedLocalIds  local ids of confirmed linked rows, likewise
     * @return 'set'|'cleared'|'unchanged'|'kept_unconfirmed'|'not_member'|'unknown_division'
     */
    public function replaceUserAssignment(int $localCompanyId, int $localUserId, ?int $ssoDivisionId, ?array $linkedMap = null, ?array $confirmedLocalIds = null): string
    {
        $membership = $this->membership($localCompanyId, $localUserId);

        if ($membership === null) {
            return 'not_member';
        }

        $linkedMap ??= $this->linkedMap($localCompanyId);
        $desired = null;

        if ($ssoDivisionId !== null && $ssoDivisionId > 0) {
            if (! isset($linkedMap[$ssoDivisionId])) {
                Log::info('SSO master data: assignment names a division not mirrored yet', [
                    'company_id' => $localCompanyId,
                    'user_id' => $localUserId,
                    'sso_division_id' => $ssoDivisionId,
                ]);

                return 'unknown_division';
            }

            $desired = $linkedMap[$ssoDivisionId];
        }

        $current = $membership->{$this->assignmentColumn()} === null ? null : (int) $membership->{$this->assignmentColumn()};

        if ($current === $desired) {
            return 'unchanged';
        }

        $confirmedLocalIds ??= array_values($this->linkedMap($localCompanyId, confirmedOnly: true));

        if ($current !== null && ! in_array($current, $confirmedLocalIds, true)) {
            return 'kept_unconfirmed';
        }

        DB::table($this->assignmentTable())
            ->where('company_id', $localCompanyId)
            ->where('user_id', $localUserId)
            ->update([$this->assignmentColumn() => $desired]);

        return $desired === null ? 'cleared' : 'set';
    }

    /**
     * Login path: `companies[].division` from /api/user ({id, name} or null).
     * Makes sure the division exists locally (adopting a same-name local row
     * as pending, like qualifications), then sets the person's division.
     *
     * @param  array<string, mixed>|null  $division
     */
    public function syncFromLoginPayload(int $localCompanyId, int $localUserId, ?array $division): string
    {
        $ssoIds = $division === null ? [] : $this->ensureFromLoginPayload($localCompanyId, [$division]);

        return $this->replaceUserAssignment($localCompanyId, $localUserId, $ssoIds[0] ?? null);
    }

    public function resync(int $localCompanyId, array $snapshot): array
    {
        $records = $this->snapshotRecords($snapshot);
        $assignments = $snapshot['assignments'] ?? [];

        if (! is_array($assignments)) {
            throw new InvalidArgumentException('SSO snapshot is missing the assignments list.');
        }

        $counts = $this->reconcileCatalog($localCompanyId, $records) + [
            'assignments_set' => 0,
            'assignments_cleared' => 0,
            'assignments_kept_unconfirmed' => 0,
            'unknown_users' => 0,
        ];

        $desiredBySsoUser = [];
        foreach ($assignments as $assignment) {
            if (is_array($assignment) && isset($assignment['user_id'])) {
                $divisionId = $assignment['division_id'] ?? null;
                $desiredBySsoUser[(string) $assignment['user_id']] = $divisionId === null ? null : (int) $divisionId;
            }
        }

        $localUsers = $this->tenants->userIds(array_keys($desiredBySsoUser));
        $counts['unknown_users'] = count($desiredBySsoUser) - count($localUsers);

        $desiredByLocalUser = [];
        foreach ($localUsers as $ssoUserId => $localUserId) {
            $desiredByLocalUser[$localUserId] = $desiredBySsoUser[(string) $ssoUserId];
        }

        $linkedMap = $this->linkedMap($localCompanyId);
        $confirmed = array_values($this->linkedMap($localCompanyId, confirmedOnly: true));

        // A member absent from the snapshot has no division in SSO.
        $members = DB::table($this->assignmentTable())->where('company_id', $localCompanyId)->pluck('user_id');

        foreach ($members as $localUserId) {
            $result = $this->replaceUserAssignment($localCompanyId, (int) $localUserId, $desiredByLocalUser[(int) $localUserId] ?? null, $linkedMap, $confirmed);

            match ($result) {
                'set' => $counts['assignments_set']++,
                'cleared' => $counts['assignments_cleared']++,
                'kept_unconfirmed' => $counts['assignments_kept_unconfirmed']++,
                default => null,
            };
        }

        return $counts;
    }

    /**
     * The company's local divisions and every person's division, in the
     * shape of SSO's POST .../divisions/import. Assignments travel by the
     * user's SSO id; local users without one are only counted. Names longer
     * than SSO's 100-character limit are cut and reported in truncated_names.
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        ['rows' => $rows, 'truncated_names' => $truncatedNames] = $this->rowsForPush($localCompanyId, ['code', 'is_active']);

        $divisions = array_map(fn (object $row): array => [
            'local_id' => (int) $row->id,
            'name' => (string) $row->name,
            'code' => $row->code === null || $row->code === '' ? null : mb_substr((string) $row->code, 0, 20),
            'is_active' => (bool) $row->is_active,
        ], $rows);

        $assignments = [];
        $skipped = [];

        if ($divisions !== []) {
            $pivot = $this->assignmentTable();
            $column = $this->assignmentColumn();

            $members = DB::table($pivot)
                ->join('users', 'users.id', '=', "{$pivot}.user_id")
                ->where("{$pivot}.company_id", $localCompanyId)
                ->whereIn("{$pivot}.{$column}", array_column($divisions, 'local_id'))
                ->orderBy("{$pivot}.user_id")
                ->get(["{$pivot}.user_id", 'users.sso_id', "{$pivot}.{$column} as local_division_id"]);

            foreach ($members as $member) {
                $ssoUserId = trim((string) ($member->sso_id ?? ''));

                if ($ssoUserId === '') {
                    $skipped[(int) $member->user_id] = true;

                    continue;
                }

                $assignments[] = ['user_sso_id' => $ssoUserId, 'local_division_id' => (int) $member->local_division_id];
            }
        }

        return [
            'payload' => [
                'app_slug' => (string) config('sso.app_slug'),
                'divisions' => $divisions,
                'assignments' => $assignments,
            ],
            'skipped_users' => count($skipped),
            'truncated_names' => $truncatedNames,
        ];
    }

    protected function attributes(array $record): array
    {
        return [
            'code' => isset($record['code']) && trim((string) $record['code']) !== '' ? trim((string) $record['code']) : null,
            'is_active' => array_key_exists('is_active', $record) ? (bool) $record['is_active'] : true,
            'sort_order' => isset($record['sort_order']) ? max(0, (int) $record['sort_order']) : null,
            'sso_updated_at' => $this->normalizeTimestamp($record['updated_at'] ?? null),
        ];
    }

    /**
     * A record without sort_order (the login payload) keeps the stored one,
     * as does one without updated_at.
     *
     * @param  array{name: string, code: ?string, is_active: bool, sort_order: ?int, sso_updated_at: ?string}  $attributes
     * @return array<string, mixed>
     */
    protected function toColumns(array $attributes): array
    {
        $columns = [
            'name' => $attributes['name'],
            'code' => $attributes['code'],
            'is_active' => $attributes['is_active'],
        ];

        if ($attributes['sort_order'] !== null) {
            $columns['sort_order'] = $attributes['sort_order'];
        }

        if ($attributes['sso_updated_at'] !== null) {
            $columns[self::SSO_UPDATED_AT_COLUMN] = $attributes['sso_updated_at'];
        }

        return $columns;
    }

    /**
     * @param  array{name: string, code: ?string, is_active: bool, sort_order: ?int, sso_updated_at: ?string}  $attributes
     */
    protected function matches(object $row, array $attributes): bool
    {
        return (string) $row->name === $attributes['name']
            && ($row->code === null ? null : (string) $row->code) === $attributes['code']
            && (bool) $row->is_active === $attributes['is_active']
            && ($attributes['sort_order'] === null || (int) $row->sort_order === $attributes['sort_order'])
            && ($attributes['sso_updated_at'] === null || $this->normalizeTimestamp($row->{self::SSO_UPDATED_AT_COLUMN}) === $attributes['sso_updated_at']);
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

        $ssoDivisionId = isset($payload['division_id']) ? (int) $payload['division_id'] : null;
        $linkedMap = $this->linkedMap($localCompanyId);

        if ($ssoDivisionId !== null && ! isset($linkedMap[$ssoDivisionId])) {
            $linkedMap = $this->fetchMissingCatalogRows($localCompanyId, $payload['company']['id'] ?? null) ?? $linkedMap;
        }

        return ['status' => 'ok', 'result' => $this->replaceUserAssignment($localCompanyId, $localUserId, $ssoDivisionId, $linkedMap)];
    }

    private function membership(int $localCompanyId, int $localUserId): ?object
    {
        return DB::table($this->assignmentTable())
            ->where('company_id', $localCompanyId)
            ->where('user_id', $localUserId)
            ->first();
    }
}

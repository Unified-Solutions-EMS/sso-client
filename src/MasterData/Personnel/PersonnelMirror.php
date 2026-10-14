<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Personnel;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Unified\SsoClient\MasterData\CatalogMirror;
use Unified\SsoClient\MasterData\Contracts\EntityMirror;
use Unified\SsoClient\MasterData\Contracts\FetchesSnapshot;
use Unified\SsoClient\MasterData\Contracts\SeedsSso;
use Unified\SsoClient\MasterData\Exceptions\MasterDataSyncException;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Mirrors SSO's personnel (NEMSIS dPersonnel, one record per person per
 * company; design /Sites/docs/design/personnel-design.md §3b) into the
 * app's own table through the bound PersonnelProjection. SSO is the editor;
 * this class owns what every app shares:
 *
 * - one local row per SSO user and company, linked by `sso_user_id`.
 *   A person with no linked row adopts an unlinked local row by the local
 *   user link first (users.sso_id), then by email, exactly one candidate
 *   each, never by name. Two candidates is `ambiguous`: nothing is created
 *   or linked, the push mapping settles it. Adoption by a delivery only
 *   links and marks the row pending; its fields, its children and its
 *   on/off state stay as the agency had them until the push mapping,
 *   --link-by-name or a full resync confirms the link (second-app cutover);
 * - the stale guard: a delivery older than the stored `sso_updated_at` is
 *   acked `stale` (SSO queues deliveries; they arrive out of order);
 * - turned off, never deleted: `person.deactivated`, a snapshot whose
 *   membership is no longer active, or a full resync that no longer lists
 *   a linked person sets the active column false. PCR crew rows, eCrew and
 *   shift members hold these row ids, so the row and its id stay;
 * - child rows (licences, practice levels, phones, emails, immunizations)
 *   reconciled IN PLACE by SSO's stable child ids; a local child SSO never
 *   knew is adopted by key or kept (`unlinked_children`), never deleted;
 * - per-field partial values: a value this app pushed that SSO could not
 *   store comes back in the snapshot's `pending` ({path: {app_slug:
 *   value}}) and is written over SSO's empty value, so a delivery or a
 *   resync never blanks what the app had (FacilityMirror's rule);
 * - a snapshot section SSO left out (an older SSO) is not written: the
 *   projection leaves its columns out and the child list is not touched;
 * - the push (SeedsSso), paged resync (FetchesSnapshot), a single-person
 *   pull for the login staleness hint, and the write-through PATCH.
 *
 * Every query is the query builder (no Eloquent global scopes) pinned to
 * the local company the caller resolved from an authoritative SSO company
 * id (DEV_GUIDELINES §4a); child tables are reached only through a parent
 * row already pinned to that company.
 */
class PersonnelMirror implements EntityMirror, FetchesSnapshot, SeedsSso
{
    public const SSO_ID_COLUMN = 'sso_user_id';

    public const SSO_UPDATED_AT_COLUMN = CatalogMirror::SSO_UPDATED_AT_COLUMN;

    public const LINK_PENDING_COLUMN = CatalogMirror::LINK_PENDING_COLUMN;

    /**
     * The SSO link column the migration adds to each child table, by the
     * SSO list the table holds.
     */
    public const CHILD_SSO_ID_COLUMNS = [
        'phones' => 'sso_phone_id',
        'emails' => 'sso_email_id',
        'immunizations' => 'sso_immunization_id',
        'licenses' => 'sso_license_id',
        'practice_levels' => 'sso_practice_level_id',
    ];

    /**
     * Where each child list sits in the snapshot (and in the push).
     */
    public const CHILD_PATHS = [
        'phones' => 'phones',
        'emails' => 'emails',
        'immunizations' => 'immunizations',
        'licenses' => 'licenses',
        'practice_levels' => 'membership.practice_levels',
    ];

    /**
     * Snapshot paths a `pending` value may be kept for. Child lists are not
     * among them: a child SSO refused stays a local unlinked row, which the
     * mirror never deletes.
     */
    public const PENDING_SECTIONS = ['user', 'profile', 'membership'];

    private const PENDING_EXCLUDED = [
        'user.id',
        'user.email',
        'membership.is_member',
        'membership.is_active',
        'membership.deactivated_at',
        'membership.practice_levels',
    ];

    /** SSO's page size is 200; this bounds a resync at 200k people. */
    public const MAX_PAGES = 1000;

    /**
     * @var array<string, bool>
     */
    private array $columnCache = [];

    /**
     * @var array<int, array<int, int>>
     */
    private array $divisionLinks = [];

    /**
     * @var array<string, int>
     */
    private array $resyncCounts = [];

    public function __construct(
        protected readonly LocalTenantResolver $tenants,
        protected readonly MasterDataClient $client,
        protected readonly PersonnelProjection $projection,
        protected readonly MasterDataRegistry $registry,
    ) {}

    public function entity(): string
    {
        return 'personnel';
    }

    public static function events(): array
    {
        return [
            'person.updated',
            'person.deactivated',
        ];
    }

    public function table(): string
    {
        return $this->projection->table();
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }

    public function activeColumn(): string
    {
        return $this->projection->activeColumn();
    }

    public function publishTag(): string
    {
        return 'sso-master-data-personnel';
    }

    /**
     * @return array<'phones'|'emails'|'immunizations'|'licenses'|'practice_levels', PersonnelChildTable>
     */
    public function childTables(): array
    {
        return $this->projection->childTables();
    }

    public function isInstalled(): bool
    {
        if (! Schema::hasTable($this->table())
            || ! Schema::hasColumns($this->table(), [self::SSO_ID_COLUMN, $this->activeColumn(), self::SSO_UPDATED_AT_COLUMN, self::LINK_PENDING_COLUMN])) {
            return false;
        }

        foreach ($this->childTables() as $kind => $child) {
            if (! Schema::hasTable($child->table()) || ! Schema::hasColumn($child->table(), self::CHILD_SSO_ID_COLUMNS[$kind])) {
                return false;
            }
        }

        return true;
    }

    public function applyWebhook(string $event, int $localCompanyId, array $payload): array
    {
        return match ($event) {
            'person.updated' => $this->applyUpdateWebhook($localCompanyId, (array) ($payload['personnel'] ?? [])),
            'person.deactivated' => $this->applyDeactivationWebhook($localCompanyId, $payload),
            default => ['status' => 'ignored', 'reason' => 'unknown_event'],
        };
    }

    /**
     * Write one SSO snapshot. $delivery marks a webhook, login pull or
     * write-through answer (as opposed to a resync, which is authoritative
     * and current by definition): it gets the stale and pending guards, and
     * an adoption only links the row (pending).
     *
     * @param  array<string, mixed>  $snapshot
     * @return 'created'|'updated'|'linked'|'unchanged'|'stale'|'pending'|'ambiguous'|'user_not_found'|'not_member'
     */
    public function upsert(int $localCompanyId, array $snapshot, bool $delivery = false): string
    {
        $ssoUserId = self::ssoUserIdOf($snapshot);

        if ($ssoUserId <= 0) {
            throw new InvalidArgumentException('A personnel snapshot needs user.id.');
        }

        $snapshot = $this->prepare($localCompanyId, self::withOwnPending($snapshot, (string) config('sso.app_slug')));
        $updatedAt = $this->normalizeTimestamp($snapshot['updated_at'] ?? null);
        $row = $this->findLinked($localCompanyId, $ssoUserId);

        if ($delivery && $row !== null) {
            if ((bool) $row->{self::LINK_PENDING_COLUMN}) {
                return 'pending';
            }

            if ($this->isStale($row, $updatedAt)) {
                return 'stale';
            }
        }

        if ($row !== null) {
            $changed = $this->writeRow($localCompanyId, $row, $snapshot, $updatedAt);
            $changed = $this->reconcileChildren((int) $row->id, $snapshot) || $changed;
            $this->projection->written($localCompanyId, (int) $row->id, $snapshot);

            return $changed ? 'updated' : 'unchanged';
        }

        $candidate = $this->findAdoptable($localCompanyId, $snapshot);

        if ($candidate === 'ambiguous') {
            Log::info('SSO master data: more than one local person matches an SSO person, nothing linked', [
                'company_id' => $localCompanyId,
                'sso_user_id' => $ssoUserId,
            ]);

            return 'ambiguous';
        }

        if ($candidate !== null) {
            return $this->adopt($localCompanyId, $candidate, $snapshot, $updatedAt, $delivery);
        }

        if (! self::isActiveMember($snapshot)) {
            return 'not_member';
        }

        $localUserId = $snapshot['user']['local_id'] ?? null;

        if ($this->projection->userColumn() !== null && $localUserId === null) {
            return 'user_not_found';
        }

        $rowId = (int) DB::table($this->table())->insertGetId(
            $this->rowColumns($localCompanyId, $snapshot, $updatedAt, null) + [
                'company_id' => $localCompanyId,
                self::SSO_ID_COLUMN => $ssoUserId,
                self::LINK_PENDING_COLUMN => false,
            ] + $this->timestamps($this->table(), created: true),
        );

        $this->reconcileChildren($rowId, $snapshot);
        $this->projection->written($localCompanyId, $rowId, $snapshot);

        return 'created';
    }

    /**
     * SSO says the person left the company (`person.deactivated`). The row
     * is turned off, never removed. A pending (adopted, unconfirmed) row is
     * left alone until confirmed.
     *
     * @return 'deactivated'|'not_found'|'pending'|'stale'
     */
    public function deactivate(int $localCompanyId, int $ssoUserId, ?string $updatedAt = null, bool $delivery = true): string
    {
        $row = $ssoUserId > 0 ? $this->findLinked($localCompanyId, $ssoUserId) : null;

        if ($row === null) {
            return 'not_found';
        }

        if ((bool) $row->{self::LINK_PENDING_COLUMN}) {
            return 'pending';
        }

        $updatedAt = $this->normalizeTimestamp($updatedAt);

        if ($delivery && $this->isStale($row, $updatedAt)) {
            return 'stale';
        }

        $columns = array_filter([
            $this->activeColumn() => false,
            self::SSO_UPDATED_AT_COLUMN => $updatedAt,
        ], fn (mixed $value): bool => $value !== null);

        if (! self::sameValue($row->{$this->activeColumn()}, false) || ($updatedAt !== null && $this->normalizeTimestamp($row->{self::SSO_UPDATED_AT_COLUMN}) !== $updatedAt)) {
            $this->scoped($localCompanyId)->where('id', $row->id)->update($columns + $this->timestamps($this->table()));
        }

        return 'deactivated';
    }

    /**
     * Reconcile against the full set (`{personnel: [snapshot...], complete:
     * bool}`). Every listed person is written; a confirmed linked person
     * SSO no longer lists is turned off, but only when the set is complete
     * (fetchSnapshot() walked every page). Local rows SSO never knew are
     * left exactly as they are and counted (`unlinked_local`).
     *
     * @return array<string, int>
     */
    public function resync(int $localCompanyId, array $snapshot): array
    {
        $records = $snapshot[$this->entity()] ?? null;

        if (! is_array($records)) {
            throw new InvalidArgumentException("SSO snapshot is missing the {$this->entity()} list.");
        }

        $this->resyncCounts = ['unlinked_children' => 0];
        $counts = array_fill_keys(['created', 'updated', 'linked', 'unchanged', 'deactivated', 'ambiguous', 'user_not_found', 'not_member'], 0);
        $seen = [];

        foreach ($records as $record) {
            if (! is_array($record) || self::ssoUserIdOf($record) <= 0) {
                continue;
            }

            $counts[$this->upsert($localCompanyId, $record)]++;
            $seen[] = self::ssoUserIdOf($record);
        }

        if (($snapshot['complete'] ?? true) === true) {
            $counts['deactivated'] = $this->scoped($localCompanyId)
                ->whereNotNull(self::SSO_ID_COLUMN)
                ->where(self::LINK_PENDING_COLUMN, false)
                ->when($seen !== [], fn (Builder $query) => $query->whereNotIn(self::SSO_ID_COLUMN, $seen))
                ->where($this->activeColumn(), true)
                ->update([$this->activeColumn() => false] + $this->timestamps($this->table()));
        }

        $counts['unlinked_local'] = $this->scoped($localCompanyId)->whereNull(self::SSO_ID_COLUMN)->count();

        return $counts + $this->resyncCounts;
    }

    /**
     * Every page of `GET .../personnel?cursor=`, as one complete set.
     */
    public function fetchSnapshot(int|string $ssoCompanyId): array
    {
        $people = [];
        $cursor = null;
        $company = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->client->get($this->entity(), $ssoCompanyId, '', ['cursor' => $cursor]);
            $data = $body['data'] ?? null;

            if (! is_array($data)) {
                throw new MasterDataSyncException("SSO personnel page for company {$ssoCompanyId} has no data list.");
            }

            $company ??= $body['company'] ?? null;
            array_push($people, ...array_values(array_filter($data, 'is_array')));
            $next = $body['next_cursor'] ?? null;

            if ($next === null || $next === '') {
                return ['company' => $company, $this->entity() => $people, 'complete' => true];
            }

            if ($cursor !== null && (int) $next <= (int) $cursor) {
                throw new MasterDataSyncException("SSO personnel paging for company {$ssoCompanyId} did not advance past cursor {$cursor}.");
            }

            $cursor = (int) $next;
        }

        throw new MasterDataSyncException('SSO personnel for company '.$ssoCompanyId.' has more than '.self::MAX_PAGES.' pages; nothing was changed locally.');
    }

    /**
     * One person's snapshot (`GET .../personnel/{ssoUserId}`), with the
     * login timeout: the login staleness pull runs while a user waits.
     *
     * @return array<string, mixed>
     */
    public function fetchOne(int|string $ssoCompanyId, int $ssoUserId): array
    {
        $body = $this->client->get($this->entity(), $ssoCompanyId, '/'.$ssoUserId, [], (int) config('sso.timeout', 10));
        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw new MasterDataSyncException("SSO answered personnel {$ssoUserId} of company {$ssoCompanyId} without data.");
        }

        return $data;
    }

    /**
     * Pull one person and apply it like a delivery (stale and pending
     * guards). Used by the login staleness hint.
     *
     * @return 'created'|'updated'|'linked'|'unchanged'|'stale'|'pending'|'ambiguous'|'user_not_found'|'not_member'
     */
    public function pull(int $localCompanyId, int|string $ssoCompanyId, int $ssoUserId): string
    {
        $snapshot = $this->fetchOne($ssoCompanyId, $ssoUserId);

        return DB::transaction(fn (): string => $this->upsert($localCompanyId, $snapshot, delivery: true));
    }

    /**
     * Whether `/api/user`'s `companies[].personnel_updated_at` is newer than
     * this app's copy (or the app has none yet). A pending row waits for its
     * confirmation, so it never asks.
     */
    public function needsPull(int $localCompanyId, int $ssoUserId, mixed $hint): bool
    {
        $hint = $this->normalizeTimestamp($hint);

        if ($hint === null || $ssoUserId <= 0) {
            return false;
        }

        $row = $this->findLinked($localCompanyId, $ssoUserId);

        if ($row === null) {
            return true;
        }

        if ((bool) $row->{self::LINK_PENDING_COLUMN}) {
            return false;
        }

        $stored = $this->normalizeTimestamp($row->{self::SSO_UPDATED_AT_COLUMN});

        return $stored === null || $hint > $stored;
    }

    /**
     * The app's editor writing through to SSO (design §3c/§3e): PATCH
     * `.../personnel/{ssoUserId}` `{patch, source, actor_sso_user_id}`, then
     * apply the snapshot SSO answers with right away, so the editor shows
     * the saved state without waiting for the webhook. `results` is SSO's
     * per-key verdict (`{key: {ok: true} | {ok: false, message}}`); a
     * refused key changed nothing in SSO, and the local row now matches SSO.
     *
     * @param  array<string, mixed>  $patch
     * @return array{results: array<string, mixed>, result: string}
     *
     * @throws MasterDataSyncException when SSO is unreachable or refuses the request itself
     */
    public function writeThrough(int $localCompanyId, int|string $ssoCompanyId, int $ssoUserId, array $patch, ?int $actorSsoUserId = null): array
    {
        $response = $this->client->patch($this->entity(), $ssoCompanyId, '/'.$ssoUserId, array_filter([
            'patch' => $patch,
            'source' => (string) config('sso.app_slug'),
            'actor_sso_user_id' => $actorSsoUserId,
        ], fn (mixed $value): bool => $value !== null), (int) config('sso.timeout', 10));

        $data = $response['data'] ?? null;
        $result = is_array($data) && self::ssoUserIdOf($data) === $ssoUserId
            ? DB::transaction(fn (): string => $this->upsert($localCompanyId, $data, delivery: true))
            : 'unchanged';

        return ['results' => is_array($response['results'] ?? null) ? $response['results'] : [], 'result' => $result];
    }

    /**
     * Cutover helper for an app whose rows were never pushed: links unlinked
     * local rows to SSO people by the local user link, then by email, one
     * candidate each. Never by name; never creates, merges or deletes.
     */
    public function linkByName(int $localCompanyId, array $snapshot): array
    {
        $records = $snapshot[$this->entity()] ?? null;

        if (! is_array($records)) {
            throw new InvalidArgumentException("SSO snapshot is missing the {$this->entity()} list.");
        }

        $linked = $this->linkedMap($localCompanyId);
        $result = ['linked' => [], 'ambiguous' => [], 'unmatched_local' => [], 'unmatched_sso' => []];

        foreach ($records as $record) {
            $ssoUserId = is_array($record) ? self::ssoUserIdOf($record) : 0;

            if ($ssoUserId <= 0 || isset($linked[$ssoUserId])) {
                continue;
            }

            $name = self::nameOf($record);
            $candidate = $this->findAdoptable($localCompanyId, $this->prepare($localCompanyId, $record));

            if ($candidate === 'ambiguous') {
                $result['ambiguous'][] = ['name' => $name, 'local_ids' => [], 'sso_ids' => [$ssoUserId]];

                continue;
            }

            if ($candidate === null) {
                $result['unmatched_sso'][] = ['id' => $ssoUserId, 'name' => $name];

                continue;
            }

            $this->scoped($localCompanyId)
                ->where('id', $candidate->id)
                ->whereNull(self::SSO_ID_COLUMN)
                ->update([self::SSO_ID_COLUMN => $ssoUserId, self::LINK_PENDING_COLUMN => false] + $this->timestamps($this->table()));

            $result['linked'][] = ['local_id' => (int) $candidate->id, 'sso_id' => $ssoUserId, 'name' => $name];
        }

        foreach ($this->scoped($localCompanyId)->whereNull(self::SSO_ID_COLUMN)->orderBy('id')->pluck('id') as $id) {
            $result['unmatched_local'][] = ['id' => (int) $id, 'name' => "local person {$id}"];
        }

        return $result;
    }

    /**
     * Local row id keyed by SSO user id, for the company's linked rows.
     *
     * @return array<int, int>
     */
    public function linkedMap(int $localCompanyId, bool $activeOnly = false, bool $confirmedOnly = false): array
    {
        $map = [];

        $rows = $this->scoped($localCompanyId)
            ->whereNotNull(self::SSO_ID_COLUMN)
            ->when($activeOnly, fn (Builder $query) => $query->where($this->activeColumn(), true))
            ->when($confirmedOnly, fn (Builder $query) => $query->where(self::LINK_PENDING_COLUMN, false))
            ->get(['id', self::SSO_ID_COLUMN]);

        foreach ($rows as $row) {
            $map[(int) $row->{self::SSO_ID_COLUMN}] = (int) $row->id;
        }

        return $map;
    }

    /**
     * The company's people in the shape of SSO's POST .../personnel/import:
     * `{app_slug, personnel: [{local_id, user_sso_id?, is_active, user?,
     * profile?, membership?, phones?, emails?, immunizations?, licenses?}]}`,
     * children with their local ids (practice levels under `membership`).
     * `user_sso_id` is the row's own link when it has one, else its local
     * user's sso_id. Every row is sent, also those SSO cannot match (no
     * user, no email): SSO lists them for review instead of guessing.
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        $userColumn = $this->projection->userColumn();
        $columns = array_values(array_unique([
            'id',
            self::SSO_ID_COLUMN.' as mirror_sso_user_id',
            $this->activeColumn().' as mirror_is_active',
            ...($userColumn !== null ? [$userColumn.' as mirror_user_id'] : []),
            ...$this->projection->pushColumns(),
        ]));

        $rows = $this->scoped($localCompanyId)->orderBy('id')->get($columns)->all();
        $userSsoIds = $userColumn === null ? [] : $this->userSsoIds(array_map(fn (object $row): mixed => $row->mirror_user_id, $rows));
        $children = $this->childrenForPush(array_map(fn (object $row): int => (int) $row->id, $rows));
        $unmatchable = 0;

        $people = array_map(function (object $row) use ($localCompanyId, $userSsoIds, $children, &$unmatchable): array {
            $person = $this->projection->toImport($localCompanyId, $row);
            $userSsoId = $row->mirror_sso_user_id !== null
                ? (int) $row->mirror_sso_user_id
                : ($userSsoIds[(int) ($row->mirror_user_id ?? 0)] ?? null);

            foreach ($children[(int) $row->id] ?? [] as $kind => $list) {
                data_set($person, self::CHILD_PATHS[$kind], $list);
            }

            if ($userSsoId === null && trim((string) data_get($person, 'user.email', '')) === '') {
                $unmatchable++;
            }

            return ['local_id' => (int) $row->id]
                + ($userSsoId !== null ? ['user_sso_id' => $userSsoId] : [])
                + ['is_active' => (bool) $row->mirror_is_active]
                + $person;
        }, $rows);

        return [
            'payload' => [
                'app_slug' => (string) config('sso.app_slug'),
                $this->entity() => $people,
            ],
            'skipped_users' => $unmatchable,
            'truncated_names' => [],
        ];
    }

    /**
     * Links each local row to the SSO user in the mapping (`{local_id:
     * {sso_id, updated_at, phones: {child_local_id: sso_child_id}, emails,
     * immunizations, licenses, practice_levels}}` or a bare SSO user id),
     * confirming it (`sso_link_pending=false`, SSO's own `updated_at`). A
     * row already linked to another person, or a person another local row
     * already holds (two local rows SSO matched to one person), is reported
     * and left alone. Fields are not written here: the full resync after
     * the flag is turned on does that.
     */
    public function applyImportMapping(int $localCompanyId, array $mapping): array
    {
        $applied = 0;
        $collisions = [];
        ksort($mapping);

        foreach ($mapping as $localId => $entry) {
            $localId = (int) $localId;
            $ssoUserId = (int) (is_array($entry) ? ($entry['sso_id'] ?? 0) : $entry);

            if ($localId <= 0 || $ssoUserId <= 0) {
                continue;
            }

            $row = $this->scoped($localCompanyId)->where('id', $localId)->first();

            $reason = match (true) {
                $row === null => 'local_row_missing',
                $row->{self::SSO_ID_COLUMN} !== null && (int) $row->{self::SSO_ID_COLUMN} !== $ssoUserId => 'local_row_linked_to_another_sso_id',
                $this->scoped($localCompanyId)->where(self::SSO_ID_COLUMN, $ssoUserId)->where('id', '!=', $localId)->exists() => 'sso_id_held_by_another_local_row',
                default => null,
            };

            if ($reason !== null) {
                $collisions[] = ['local_id' => $localId, 'sso_id' => $ssoUserId, 'reason' => $reason];

                continue;
            }

            $this->scoped($localCompanyId)->where('id', $localId)->update([
                self::SSO_ID_COLUMN => $ssoUserId,
                // SSO's own clock, so the import's own webhooks are not stale;
                // the bare-id form carries none and the next delivery applies.
                self::SSO_UPDATED_AT_COLUMN => is_array($entry) ? $this->normalizeTimestamp($entry['updated_at'] ?? null) : null,
                self::LINK_PENDING_COLUMN => false,
            ]);
            $applied++;

            if (is_array($entry)) {
                foreach ($this->childTables() as $kind => $child) {
                    if (is_array($entry[$kind] ?? null)) {
                        array_push($collisions, ...$this->applyChildMapping($child, $kind, $localId, $entry[$kind]));
                    }
                }
            }
        }

        return ['applied' => $applied, 'collisions' => $collisions];
    }

    /**
     * The snapshot with this app's kept values (`pending: {path: {app_slug:
     * value}}`) in place of SSO's EMPTY values: a value the app pushed that
     * SSO's rules refused reads as what the app sent until the agency fixes
     * or dismisses it in SSO. SSO's own value always wins once it has one,
     * and another app's kept values are never applied.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function withOwnPending(array $snapshot, string $appSlug): array
    {
        $pending = $snapshot['pending'] ?? null;

        if (! is_array($pending) || $appSlug === '') {
            return $snapshot;
        }

        foreach ($pending as $path => $byApp) {
            $path = (string) $path;
            $section = strstr($path, '.', true);

            if ($section === false
                || ! in_array($section, self::PENDING_SECTIONS, true)
                || in_array($path, self::PENDING_EXCLUDED, true)
                || ! is_array($byApp)
                || ! array_key_exists($appSlug, $byApp)
                || ! is_array($snapshot[$section] ?? null)
                || ! self::isEmptyValue(data_get($snapshot, $path))) {
                continue;
            }

            data_set($snapshot, $path, $byApp[$appSlug]);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function ssoUserIdOf(array $snapshot): int
    {
        $id = $snapshot['user']['id'] ?? null;

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function isActiveMember(array $snapshot): bool
    {
        $membership = is_array($snapshot['membership'] ?? null) ? $snapshot['membership'] : [];

        return (bool) ($membership['is_member'] ?? true) && (bool) ($membership['is_active'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function applyUpdateWebhook(int $localCompanyId, array $snapshot): array
    {
        if (self::ssoUserIdOf($snapshot) <= 0) {
            return ['status' => 'ignored', 'reason' => 'no_user'];
        }

        $result = $this->upsert($localCompanyId, $snapshot, delivery: true);

        if ($result === 'stale') {
            Log::info('SSO master data: stale person delivery ignored', [
                'company_id' => $localCompanyId,
                'sso_user_id' => self::ssoUserIdOf($snapshot),
                'updated_at' => $snapshot['updated_at'] ?? null,
            ]);
        }

        return match ($result) {
            'stale' => ['status' => 'stale', 'result' => 'stale'],
            // The nightly resync heals people this app has not provisioned yet.
            'user_not_found' => ['status' => 'skipped', 'result' => $result, 'reason' => 'user_not_found'],
            default => ['status' => 'ok', 'result' => $result],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyDeactivationWebhook(int $localCompanyId, array $payload): array
    {
        $ssoUserId = self::ssoUserIdOf($payload);
        $updatedAt = $payload['updated_at'] ?? null;
        $result = $this->deactivate($localCompanyId, $ssoUserId, is_string($updatedAt) ? $updatedAt : null);

        return ['status' => $result === 'stale' ? 'stale' : 'ok', 'result' => $result];
    }

    /**
     * The snapshot plus what only this app knows: `user.local_id` and, when
     * the divisions mirror can translate SSO's division, the local one.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function prepare(int $localCompanyId, array $snapshot): array
    {
        $user = is_array($snapshot['user'] ?? null) ? $snapshot['user'] : [];
        $user['local_id'] = $this->tenants->userId(self::ssoUserIdOf($snapshot) ?: null);
        $snapshot['user'] = $user;

        if (is_array($snapshot['membership'] ?? null) && array_key_exists('division_id', $snapshot['membership'])) {
            $ssoDivisionId = $snapshot['membership']['division_id'];
            $links = $this->divisionLinks($localCompanyId);

            if ($ssoDivisionId === null) {
                $snapshot['membership']['local_division_id'] = null;
            } elseif (isset($links[(int) $ssoDivisionId])) {
                $snapshot['membership']['local_division_id'] = $links[(int) $ssoDivisionId];
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function adopt(int $localCompanyId, object $candidate, array $snapshot, ?string $updatedAt, bool $delivery): string
    {
        $ssoUserId = self::ssoUserIdOf($snapshot);

        if ($delivery) {
            $this->scoped($localCompanyId)
                ->where('id', $candidate->id)
                ->whereNull(self::SSO_ID_COLUMN)
                ->update([self::SSO_ID_COLUMN => $ssoUserId, self::LINK_PENDING_COLUMN => true] + $this->timestamps($this->table()));

            return 'linked';
        }

        $this->scoped($localCompanyId)
            ->where('id', $candidate->id)
            ->whereNull(self::SSO_ID_COLUMN)
            ->update([self::SSO_ID_COLUMN => $ssoUserId]);

        $row = $this->findLinked($localCompanyId, $ssoUserId);

        if ($row !== null) {
            $this->writeRow($localCompanyId, $row, $snapshot, $updatedAt);
            $this->reconcileChildren((int) $row->id, $snapshot);
            $this->projection->written($localCompanyId, (int) $row->id, $snapshot);
        }

        return 'linked';
    }

    /**
     * Write the row's columns where they differ, confirming a pending link.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function writeRow(int $localCompanyId, object $row, array $snapshot, ?string $updatedAt): bool
    {
        $columns = $this->rowColumns($localCompanyId, $snapshot, $updatedAt, $row);

        $diff = array_filter($columns, function (mixed $value, string $column) use ($row): bool {
            $stored = $row->{$column} ?? null;

            return $column === self::SSO_UPDATED_AT_COLUMN
                ? $this->normalizeTimestamp($stored) !== $value
                : ! self::sameValue($stored, $value);
        }, ARRAY_FILTER_USE_BOTH);

        if ((bool) $row->{self::LINK_PENDING_COLUMN}) {
            $diff[self::LINK_PENDING_COLUMN] = false;
        }

        if ($diff === []) {
            return false;
        }

        $this->scoped($localCompanyId)->where('id', $row->id)->update($diff + $this->timestamps($this->table()));

        return true;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function rowColumns(int $localCompanyId, array $snapshot, ?string $updatedAt, ?object $row): array
    {
        $columns = $this->projection->toColumns($localCompanyId, $snapshot);
        $columns[$this->activeColumn()] = self::isActiveMember($snapshot);

        $userColumn = $this->projection->userColumn();
        $localUserId = $snapshot['user']['local_id'] ?? null;

        if ($userColumn !== null && $localUserId !== null && (int) ($row->{$userColumn} ?? 0) !== (int) $localUserId) {
            // Another local row of the company may still hold the user (an
            // unlinked duplicate); never steal it, the push mapping settles it.
            $held = $this->scoped($localCompanyId)
                ->where($userColumn, $localUserId)
                ->when($row !== null, fn (Builder $query) => $query->where('id', '!=', $row->id))
                ->exists();

            if (! $held) {
                $columns[$userColumn] = (int) $localUserId;
            }
        }

        if ($updatedAt !== null) {
            $columns[self::SSO_UPDATED_AT_COLUMN] = $updatedAt;
        }

        return $columns;
    }

    /**
     * The unlinked local row an SSO person adopts: by local user link first,
     * then by email (case-insensitive), exactly one candidate each. A
     * candidate already tied to another local user is never taken by email.
     *
     * @param  array<string, mixed>  $snapshot  prepared (carries user.local_id)
     */
    private function findAdoptable(int $localCompanyId, array $snapshot): object|string|null
    {
        $userColumn = $this->projection->userColumn();
        $emailColumn = $this->projection->emailColumn();
        $localUserId = $snapshot['user']['local_id'] ?? null;
        $email = mb_strtolower(trim((string) ($snapshot['user']['email'] ?? '')));

        $unlinked = null;
        $load = function () use (&$unlinked, $localCompanyId): Collection {
            return $unlinked ??= $this->scoped($localCompanyId)->whereNull(self::SSO_ID_COLUMN)->orderBy('id')->get();
        };

        if ($userColumn !== null && $localUserId !== null) {
            $byUser = $load()->filter(fn (object $row): bool => (int) ($row->{$userColumn} ?? 0) === (int) $localUserId);

            if ($byUser->count() > 1) {
                return 'ambiguous';
            }

            if ($byUser->count() === 1) {
                return $byUser->first();
            }
        }

        if ($emailColumn !== null && $email !== '') {
            $byEmail = $load()->filter(fn (object $row): bool => mb_strtolower(trim((string) ($row->{$emailColumn} ?? ''))) === $email
                && ($userColumn === null || $row->{$userColumn} === null || (int) $row->{$userColumn} === (int) $localUserId));

            if ($byEmail->count() > 1) {
                return 'ambiguous';
            }

            if ($byEmail->count() === 1) {
                return $byEmail->first();
            }
        }

        return null;
    }

    /**
     * Updates the person's child rows from SSO's lists in place. A list SSO
     * left out of the snapshot is not touched.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function reconcileChildren(int $rowId, array $snapshot): bool
    {
        $changed = false;

        foreach ($this->childTables() as $kind => $child) {
            $list = data_get($snapshot, self::CHILD_PATHS[$kind]);

            if (! is_array($list)) {
                continue;
            }

            $ssoColumn = self::CHILD_SSO_ID_COLUMNS[$kind];
            $hasOrder = $this->hasColumn($child->table(), 'sort_order');
            $rows = DB::table($child->table())->where($child->parentColumn(), $rowId)->orderBy('id')->get();
            $linked = $rows->filter(fn (object $row): bool => $row->{$ssoColumn} !== null)->keyBy(fn (object $row): int => (int) $row->{$ssoColumn});
            $unlinked = $rows->filter(fn (object $row): bool => $row->{$ssoColumn} === null)->values();
            $seen = [];

            foreach (array_values($list) as $index => $ssoChild) {
                $ssoChildId = is_array($ssoChild) ? (int) ($ssoChild['id'] ?? 0) : 0;

                if ($ssoChildId <= 0) {
                    continue;
                }

                $seen[] = $ssoChildId;
                $columns = $child->toColumns($ssoChild) + ($hasOrder ? ['sort_order' => $index] : []);
                $row = $linked->get($ssoChildId);

                if ($row === null) {
                    $key = self::childKey($child, $columns);
                    $candidates = $unlinked->filter(fn (object $local): bool => $key !== '' && self::childKey($child, (array) $local) === $key);

                    if ($candidates->count() === 1) {
                        $row = $candidates->first();
                        $unlinked = $unlinked->reject(fn (object $local): bool => $local->id === $row->id)->values();
                        $columns[$ssoColumn] = $ssoChildId;
                    }
                }

                if ($row === null) {
                    DB::table($child->table())->insert($columns + [$child->parentColumn() => $rowId, $ssoColumn => $ssoChildId] + $this->timestamps($child->table(), created: true));
                    $changed = true;

                    continue;
                }

                $diff = array_filter($columns, fn (mixed $value, string $column): bool => ! self::sameValue($row->{$column} ?? null, $value), ARRAY_FILTER_USE_BOTH);

                if ($diff !== []) {
                    DB::table($child->table())->where($child->parentColumn(), $rowId)->where('id', $row->id)->update($diff + $this->timestamps($child->table()));
                    $changed = true;
                }
            }

            $dropped = $linked->keys()->diff($seen)->map(fn (int $ssoChildId): int => (int) $linked->get($ssoChildId)->id)->values()->all();

            if ($dropped !== []) {
                DB::table($child->table())->where($child->parentColumn(), $rowId)->whereIn('id', $dropped)->delete();
                $changed = true;
            }

            if ($unlinked->isNotEmpty()) {
                $this->resyncCounts['unlinked_children'] = ($this->resyncCounts['unlinked_children'] ?? 0) + $unlinked->count();
                Log::info('SSO master data: local personnel child rows SSO does not list were kept', [
                    'table' => $child->table(),
                    'parent_id' => $rowId,
                    'child_ids' => $unlinked->pluck('id')->all(),
                ]);
            }
        }

        return $changed;
    }

    /**
     * @param  array<int|string, mixed>  $mapping  {child_local_id: sso_child_id}
     * @return list<array{local_id: int, sso_id: int, reason: string}>
     */
    private function applyChildMapping(PersonnelChildTable $child, string $kind, int $rowId, array $mapping): array
    {
        $ssoColumn = self::CHILD_SSO_ID_COLUMNS[$kind];
        $collisions = [];

        foreach ($mapping as $childLocalId => $ssoChildId) {
            $childLocalId = (int) $childLocalId;
            $ssoChildId = (int) $ssoChildId;

            if ($childLocalId <= 0 || $ssoChildId <= 0) {
                continue;
            }

            $siblings = DB::table($child->table())->where($child->parentColumn(), $rowId);
            $row = (clone $siblings)->where('id', $childLocalId)->first();

            $reason = match (true) {
                $row === null => 'local_row_missing',
                $row->{$ssoColumn} !== null && (int) $row->{$ssoColumn} !== $ssoChildId => 'local_row_linked_to_another_sso_id',
                (clone $siblings)->where($ssoColumn, $ssoChildId)->where('id', '!=', $childLocalId)->exists() => 'sso_id_held_by_another_local_row',
                default => null,
            };

            if ($reason !== null) {
                $collisions[] = ['local_id' => $childLocalId, 'sso_id' => $ssoChildId, 'reason' => "{$kind} of person {$rowId}: {$reason}"];

                continue;
            }

            (clone $siblings)->where('id', $childLocalId)->update([$ssoColumn => $ssoChildId]);
        }

        return $collisions;
    }

    /**
     * The child lists for the push, by local person row id (already pinned
     * to the company by the caller).
     *
     * @param  list<int>  $rowIds
     * @return array<int, array<string, list<array<string, mixed>>>>
     */
    private function childrenForPush(array $rowIds): array
    {
        $children = [];

        foreach ($this->childTables() as $kind => $child) {
            foreach (array_chunk($rowIds, 500) as $chunk) {
                $rows = DB::table($child->table())
                    ->whereIn($child->parentColumn(), $chunk)
                    ->orderBy('id')
                    ->get(array_values(array_unique(['id', $child->parentColumn().' as mirror_parent_id', ...$child->pushColumns()])));

                foreach ($rows as $row) {
                    $import = $child->toImport($row);

                    if ($import !== []) {
                        $children[(int) $row->mirror_parent_id][$kind][] = ['local_id' => (int) $row->id] + $import;
                    }
                }
            }
        }

        return $children;
    }

    /**
     * users.sso_id for the given local user ids, as SSO user ids.
     *
     * @param  array<int, mixed>  $userIds
     * @return array<int, int>
     */
    private function userSsoIds(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $map = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('users')->whereIn('id', $chunk)->whereNotNull('sso_id')->get(['id', 'sso_id']) as $user) {
                if (ctype_digit((string) $user->sso_id)) {
                    $map[(int) $user->id] = (int) $user->sso_id;
                }
            }
        }

        return $map;
    }

    /**
     * Local division id keyed by SSO division id; empty when the divisions
     * mirror is not installed here.
     *
     * @return array<int, int>
     */
    private function divisionLinks(int $localCompanyId): array
    {
        if (isset($this->divisionLinks[$localCompanyId])) {
            return $this->divisionLinks[$localCompanyId];
        }

        $mirror = $this->registry->mirror('divisions');

        return $this->divisionLinks[$localCompanyId] = $mirror instanceof CatalogMirror && $mirror->isInstalled()
            ? $mirror->linkedMap($localCompanyId)
            : [];
    }

    private function scoped(int $localCompanyId): Builder
    {
        return DB::table($this->table())->where('company_id', $localCompanyId);
    }

    private function findLinked(int $localCompanyId, int $ssoUserId): ?object
    {
        return $this->scoped($localCompanyId)->where(self::SSO_ID_COLUMN, $ssoUserId)->first();
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
     * @return array<string, mixed>
     */
    private function timestamps(string $table, bool $created = false): array
    {
        if (! $this->hasColumn($table, 'updated_at')) {
            return [];
        }

        return $created ? ['created_at' => now(), 'updated_at' => now()] : ['updated_at' => now()];
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->columnCache["{$table}.{$column}"] ??= Schema::hasColumn($table, $column);
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private static function childKey(PersonnelChildTable $child, array $columns): string
    {
        $parts = array_map(
            fn (string $column): string => str_replace([' ', '-', '.', '(', ')'], '', mb_strtolower(trim((string) ($columns[$column] ?? '')))),
            $child->keyColumns(),
        );

        return implode('', $parts) === '' ? '' : implode('|', $parts);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function nameOf(array $snapshot): string
    {
        return trim(((string) ($snapshot['user']['first_name'] ?? '')).' '.((string) ($snapshot['user']['last_name'] ?? '')));
    }

    private static function isEmptyValue(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isEmptyValue($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Stored and incoming values are equal for the mirror: numbers by value,
     * booleans against 0/1, JSON by its decoded value (MySQL re-spaces JSON
     * columns), the rest as text.
     */
    private static function sameValue(mixed $stored, mixed $value): bool
    {
        if ($stored === null || $value === null) {
            return $stored === null && $value === null;
        }

        if (is_bool($value)) {
            return (bool) $stored === $value;
        }

        if (is_numeric($stored) && is_numeric($value)) {
            return abs((float) $stored - (float) $value) < 0.0000005;
        }

        $stored = (string) $stored;
        $value = (string) $value;

        if ($stored === $value) {
            return true;
        }

        if (self::looksLikeJson($stored) && self::looksLikeJson($value)) {
            return json_decode($stored, true) === json_decode($value, true);
        }

        return false;
    }

    private static function looksLikeJson(string $value): bool
    {
        $first = ltrim($value)[0] ?? '';

        return ($first === '[' || $first === '{') && json_decode($value) !== null;
    }
}

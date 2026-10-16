<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Rotations;

use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\LocalTenantResolver;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\ProjectedMirror;
use Unified\SsoClient\MasterData\Support\ChildRowPolicy;
use Unified\SsoClient\MasterData\Support\LinkedChildRows;

/**
 * Mirrors SSO's rotations (Settings > Agency lists > Rotations: a repeating
 * day cycle with timed on-duty blocks, assigned to units and people) into
 * the bound RotationProjection's table, `sso_rotations` by default.
 * ProjectedMirror has the shared rules: link by `sso_rotation_id`, adopt an
 * unlinked local row by name, stale guard, pending adoption, turn off
 * never delete, `rotation.created|updated|deactivated|batch_updated`
 * (a block or assignment edit in SSO bumps its rotation and sends
 * `rotation.updated` with the whole row) and the resync. This class adds:
 *
 * - the blocks and assignments, in the package's `sso_rotation_blocks` and
 *   `sso_rotation_assignments` (RotationChildTable): linked by SSO's child
 *   ids, updated in place, and deleted when SSO no longer lists them (pure
 *   mirror tables). An assignment keeps SSO's ids of its unit or person
 *   (`sso_unit_id` / `sso_user_id`); readers match them to the app's own
 *   rows (units mirror link, `users.sso_id`). A row whose `blocks` or
 *   `assignments` SSO left out keeps what it has;
 * - the push from the projection's pushRotations() instead of table(),
 *   linked through linkPushed() (Crew Scheduling's templates).
 *
 * Rotations\MirroredRotations reads the result and expands it with the
 * package's RotationCalendar, in the company's own time zone.
 */
class RotationMirror extends ProjectedMirror
{
    public const SSO_ID_COLUMN = 'sso_rotation_id';

    /** SSO's rotation name limit; longer local names are cut on push. */
    public const MAX_ROTATION_NAME_LENGTH = 100;

    private ?LinkedChildRows $childRows = null;

    public function __construct(
        LocalTenantResolver $tenants,
        MasterDataClient $client,
        RotationProjection $projection,
    ) {
        parent::__construct($tenants, $client, $projection);
    }

    public function entity(): string
    {
        return 'rotations';
    }

    public static function events(): array
    {
        return [
            'rotation.created',
            'rotation.updated',
            'rotation.deactivated',
            'rotation.batch_updated',
        ];
    }

    public function ssoIdColumn(): string
    {
        return self::SSO_ID_COLUMN;
    }

    public function maxNameLength(): int
    {
        return self::MAX_ROTATION_NAME_LENGTH;
    }

    public function importBatchSize(): int
    {
        return 200;
    }

    protected function recordKey(): string
    {
        return 'rotation';
    }

    protected function recordName(array $record): string
    {
        return trim((string) ($record['name'] ?? ''));
    }

    public function rotations(): RotationProjection
    {
        $projection = $this->projection;

        assert($projection instanceof RotationProjection);

        return $projection;
    }

    public function isInstalled(): bool
    {
        return parent::isInstalled()
            && Schema::hasTable(RotationChildTable::BLOCKS)
            && Schema::hasTable(RotationChildTable::ASSIGNMENTS);
    }

    /**
     * Writes the rotation (ProjectedMirror), then its blocks and
     * assignments once the row is SSO's: created, updated or unchanged (a
     * block edit need not change the row), and on the authoritative resync
     * path also linked. A pending adoption and a stale delivery leave them
     * alone. A rotation whose only change was a child reports `updated`.
     *
     * @return 'created'|'updated'|'linked'|'unchanged'|'stale'|'pending'
     */
    public function upsert(int $localCompanyId, array $record, bool $delivery = false): string
    {
        $result = parent::upsert($localCompanyId, $record, $delivery);

        if (! in_array($result, ['created', 'updated', 'unchanged'], true) && ($delivery || $result !== 'linked')) {
            return $result;
        }

        $row = $this->findLinked($localCompanyId, (int) $record['id']);

        if ($row === null) {
            return $result;
        }

        $blocks = $this->reconcileBlocks((int) $row->id, $record);
        $assignments = $this->reconcileAssignments((int) $row->id, $record);

        return $result === 'unchanged' && ($blocks || $assignments) ? 'updated' : $result;
    }

    /**
     * The app's own rotations (RotationProjection::pushRotations()) in the
     * shape of SSO's POST .../rotations/import. Names longer than SSO
     * allows are cut and reported. A company with none sends nothing.
     */
    public function buildImportPayload(int $localCompanyId): array
    {
        $rotations = [];
        $truncated = [];

        foreach ($this->rotations()->pushRotations($localCompanyId) as $rotation) {
            $name = trim((string) ($rotation['name'] ?? ''));

            if (mb_strlen($name) > self::MAX_ROTATION_NAME_LENGTH) {
                $truncated[] = ['local_id' => $rotation['local_id'], 'name' => $name];
                $rotation['name'] = mb_substr($name, 0, self::MAX_ROTATION_NAME_LENGTH);
            }

            $rotations[] = $rotation;
        }

        return [
            'payload' => ['app_slug' => (string) config('sso.app_slug')] + ($rotations === [] ? [] : ['rotations' => $rotations]),
            'skipped_users' => 0,
            'truncated_names' => $truncated,
        ];
    }

    /**
     * Hands each `{local_id: {sso_id, updated_at, assignments}}` of SSO's
     * mapping to the projection. The rotation rows themselves arrive with
     * the resync (or the `rotation.batch_updated` the import sent) once the
     * entity is on.
     */
    public function applyImportMapping(int $localCompanyId, array $mapping): array
    {
        $applied = 0;
        $collisions = [];

        foreach ($mapping as $localId => $entry) {
            $ssoId = (int) (is_array($entry) ? ($entry['sso_id'] ?? 0) : $entry);
            $localId = self::localKey($localId);

            if ($localId === null || $ssoId <= 0) {
                continue;
            }

            $assignments = [];

            foreach ((array) (is_array($entry) ? ($entry['assignments'] ?? []) : []) as $assignmentLocalId => $assignmentSsoId) {
                $assignments[(string) $assignmentLocalId] = (int) $assignmentSsoId;
            }

            $refusal = $this->rotations()->linkPushed($localCompanyId, $localId, $ssoId, $assignments);

            if ($refusal === null) {
                $applied++;

                continue;
            }

            $collisions[] = ['local_id' => $localId, 'sso_id' => $ssoId, 'reason' => $refusal, 'name' => null, 'held_by' => null];
        }

        return ['applied' => $applied, 'collisions' => $collisions];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function reconcileBlocks(int $rotationId, array $record): bool
    {
        if (! is_array($record['blocks'] ?? null)) {
            return false;
        }

        $wanted = [];

        foreach ($record['blocks'] as $block) {
            $ssoId = is_array($block) ? (int) ($block['id'] ?? 0) : 0;

            if ($ssoId <= 0) {
                continue;
            }

            $wanted[] = ['id' => $ssoId, 'key' => '', 'columns' => [
                'uuid' => self::text($block['uuid'] ?? null),
                'day_index' => max(0, (int) ($block['day_index'] ?? 0)),
                'start_time' => substr(trim((string) ($block['start_time'] ?? '00:00')), 0, 5),
                'duration_minutes' => max(0, (int) ($block['duration_minutes'] ?? 0)),
                'label' => self::text($block['label'] ?? null),
                'sort_order' => max(0, (int) ($block['sort_order'] ?? 0)),
            ]];
        }

        return $this->reconcileChildren(RotationChildTable::blocks(), $rotationId, $wanted);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function reconcileAssignments(int $rotationId, array $record): bool
    {
        if (! is_array($record['assignments'] ?? null)) {
            return false;
        }

        $wanted = [];

        foreach ($record['assignments'] as $assignment) {
            $ssoId = is_array($assignment) ? (int) ($assignment['id'] ?? 0) : 0;

            if ($ssoId <= 0) {
                continue;
            }

            $type = ($assignment['assignable_type'] ?? null) === 'user' ? 'user' : 'unit';

            $wanted[] = ['id' => $ssoId, 'key' => '', 'columns' => [
                'uuid' => self::text($assignment['uuid'] ?? null),
                'assignable_type' => $type,
                'sso_unit_id' => $type === 'unit' && isset($assignment['unit_id']) ? (int) $assignment['unit_id'] : null,
                'sso_user_id' => $type === 'user' && isset($assignment['user_id']) ? (int) $assignment['user_id'] : null,
                'name' => self::text($assignment['name'] ?? null),
                'start_day_index' => max(0, (int) ($assignment['start_day_index'] ?? 0)),
                'starts_on' => self::text($assignment['starts_on'] ?? null),
                'ends_on' => self::text($assignment['ends_on'] ?? null),
                'is_active' => array_key_exists('is_active', $assignment) ? (bool) $assignment['is_active'] : true,
            ]];
        }

        return $this->reconcileChildren(RotationChildTable::assignments(), $rotationId, $wanted);
    }

    /**
     * @param  list<array{id: int, key: string, columns: array<string, mixed>}>  $wanted
     */
    private function reconcileChildren(RotationChildTable $child, int $rotationId, array $wanted): bool
    {
        return $this->childRows()->reconcile(
            $child->table,
            RotationChildTable::PARENT_COLUMN,
            $rotationId,
            $child->ssoIdColumn,
            $wanted,
            fn (object $local): string => '',
            self::sameValue(...),
            ChildRowPolicy::for($child),
        )['changed'];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function childRows(): LinkedChildRows
    {
        return $this->childRows ??= new LinkedChildRows;
    }
}

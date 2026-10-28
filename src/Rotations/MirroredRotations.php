<?php

declare(strict_types=1);

namespace Unified\SsoClient\Rotations;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Rotations\RotationChildTable;
use Unified\SsoClient\MasterData\Rotations\RotationMirror;

/**
 * Reads the rotations mirror (RotationMirror) and expands it with
 * RotationCalendar, the same expander and start_day_index convention SSO
 * uses, so an app answers "who is on duty when" without asking SSO.
 *
 * Every read is pinned to one local company (resolved by the caller from an
 * authoritative source: the session's selected company, an HMAC payload)
 * and the rotation's company is checked before any child row is read
 * (§4a). Only active rotations and active assignments produce windows.
 * The time zone is the company's own (`companies.timezone`, synced from
 * SSO); the blocks are naive wall-clock times in it.
 *
 * Windows carry SSO's ids: `rotation_id`, `assignment_id`, `unit_id` /
 * `user_id` (match them to the units mirror's `sso_unit_id` and the app's
 * `users.sso_id`).
 */
class MirroredRotations
{
    public function __construct(private readonly MasterDataRegistry $registry) {}

    /**
     * Whether the app mirrors rotations here (flag on, migration run).
     */
    public function available(): bool
    {
        return $this->registry->enabled('rotations') && $this->mirror()->isInstalled();
    }

    /**
     * The company's rotations for a picker: `[{sso_id, name, pattern,
     * cycle_days, anchor_date, is_active, color, show_on_calendar}]`, by
     * sort order then name. `color` is SSO's hex (`#e60b0b`) or null;
     * `show_on_calendar` says whether the agency wants the rotation's
     * on-duty days tinted on the app's calendars.
     *
     * @return list<array{sso_id: int, name: string, pattern: string|null, cycle_days: int, anchor_date: string, is_active: bool, color: string|null, show_on_calendar: bool}>
     */
    public function options(int $localCompanyId, bool $activeOnly = true): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->rotations($localCompanyId)
            ->whereNotNull(RotationMirror::SSO_ID_COLUMN)
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => [
                'sso_id' => (int) $row->{RotationMirror::SSO_ID_COLUMN},
                'name' => (string) $row->name,
                'pattern' => $row->pattern ?? null,
                'cycle_days' => (int) $row->cycle_days,
                'anchor_date' => self::date($row->anchor_date),
                'is_active' => (bool) $row->is_active,
                'color' => $row->color ?? null,
                'show_on_calendar' => (bool) ($row->show_on_calendar ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * One rotation of the company in RotationCalendar's shape plus its name
     * and assignments, or null (unknown, another company's, not mirrored).
     *
     * @return array{sso_id: int, name: string, pattern: string|null, is_active: bool, cycle_days: int, anchor_date: string, color: string|null, show_on_calendar: bool, blocks: list<array{day_index: int, start_time: string, duration_minutes: int, label: string|null}>, assignments: list<array{sso_id: int, assignable_type: string, unit_id: int|null, user_id: int|null, name: string|null, start_day_index: int, starts_on: string|null, ends_on: string|null, is_active: bool}>}|null
     */
    public function rotation(int $localCompanyId, int $ssoRotationId): ?array
    {
        if (! $this->available() || $ssoRotationId <= 0) {
            return null;
        }

        $row = $this->rotations($localCompanyId)->where(RotationMirror::SSO_ID_COLUMN, $ssoRotationId)->first();

        return $row === null ? null : $this->load([$row])[0];
    }

    /**
     * Every on-duty window overlapping the local dates $from..$to
     * (inclusive), optionally for one unit, one person or one rotation
     * (SSO ids), ordered by start.
     *
     * @return list<array{rotation_id: int, rotation_name: string, assignment_id: int, assignable_type: string, unit_id: int|null, user_id: int|null, name: string|null, date: string, day_index: int, label: string|null, starts_at: string, ends_at: string, scheduled_minutes: int, elapsed_minutes: int}>
     */
    public function windows(int $localCompanyId, string $from, string $to, string $timezone, ?int $ssoUnitId = null, ?int $ssoUserId = null, ?int $ssoRotationId = null): array
    {
        if (! $this->available()) {
            return [];
        }

        $rows = $this->rotations($localCompanyId)
            ->whereNotNull(RotationMirror::SSO_ID_COLUMN)
            ->where('is_active', true)
            ->when($ssoRotationId !== null, fn (Builder $query) => $query->where(RotationMirror::SSO_ID_COLUMN, $ssoRotationId))
            ->get()
            ->all();

        $windows = [];

        foreach ($this->load($rows) as $rotation) {
            foreach ($rotation['assignments'] as $assignment) {
                if (! $assignment['is_active']
                    || ($ssoUnitId !== null && $assignment['unit_id'] !== $ssoUnitId)
                    || ($ssoUserId !== null && $assignment['user_id'] !== $ssoUserId)) {
                    continue;
                }

                foreach (RotationCalendar::windows($rotation, $assignment, $from, $to, $timezone) as $window) {
                    $windows[] = [
                        'rotation_id' => $rotation['sso_id'],
                        'rotation_name' => $rotation['name'],
                        'assignment_id' => $assignment['sso_id'],
                        'assignable_type' => $assignment['assignable_type'],
                        'unit_id' => $assignment['unit_id'],
                        'user_id' => $assignment['user_id'],
                        'name' => $assignment['name'],
                    ] + $window;
                }
            }
        }

        usort($windows, fn (array $a, array $b): int => [$a['starts_at'], $a['rotation_id'], $a['assignment_id']] <=> [$b['starts_at'], $b['rotation_id'], $b['assignment_id']]);

        return $windows;
    }

    /**
     * The windows of one rotation for an assignee that is not one of its
     * SSO assignments (a Crew template run on the rotation directly), or
     * the bare pattern when $startDayIndex is 0 and no dates are given.
     *
     * @return list<array{date: string, day_index: int, label: string|null, starts_at: string, ends_at: string, scheduled_minutes: int, elapsed_minutes: int}>
     */
    public function patternWindows(int $localCompanyId, int $ssoRotationId, int $startDayIndex, string $from, string $to, string $timezone): array
    {
        $rotation = $this->rotation($localCompanyId, $ssoRotationId);

        return $rotation === null ? [] : RotationCalendar::windows($rotation, ['start_day_index' => $startDayIndex], $from, $to, $timezone);
    }

    private function rotations(int $localCompanyId): Builder
    {
        return $this->mirror()->forCompany($localCompanyId);
    }

    private function mirror(): RotationMirror
    {
        $mirror = $this->registry->mirror('rotations');

        assert($mirror instanceof RotationMirror);

        return $mirror;
    }

    /**
     * The rows with their blocks and assignments, read only under rotation
     * ids already pinned to the company.
     *
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function load(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(fn (object $row): int => (int) $row->id, $rows);
        $blocks = DB::table(RotationChildTable::BLOCKS)->whereIn(RotationChildTable::PARENT_COLUMN, $ids)->orderBy('day_index')->orderBy('start_time')->orderBy('id')->get()->groupBy(RotationChildTable::PARENT_COLUMN);
        $assignments = DB::table(RotationChildTable::ASSIGNMENTS)->whereIn(RotationChildTable::PARENT_COLUMN, $ids)->orderBy('id')->get()->groupBy(RotationChildTable::PARENT_COLUMN);

        return array_map(fn (object $row): array => [
            'sso_id' => (int) $row->{RotationMirror::SSO_ID_COLUMN},
            'name' => (string) $row->name,
            'pattern' => $row->pattern ?? null,
            'is_active' => (bool) $row->is_active,
            'cycle_days' => max(1, (int) $row->cycle_days),
            'anchor_date' => self::date($row->anchor_date),
            'color' => $row->color ?? null,
            'show_on_calendar' => (bool) ($row->show_on_calendar ?? false),
            'blocks' => ($blocks->get($row->id) ?? collect())->map(fn (object $block): array => [
                'day_index' => (int) $block->day_index,
                'start_time' => substr((string) $block->start_time, 0, 5),
                'duration_minutes' => (int) $block->duration_minutes,
                'label' => $block->label,
            ])->values()->all(),
            'assignments' => ($assignments->get($row->id) ?? collect())->map(fn (object $assignment): array => [
                'sso_id' => (int) $assignment->sso_rotation_assignment_id,
                'assignable_type' => (string) $assignment->assignable_type,
                'unit_id' => $assignment->sso_unit_id === null ? null : (int) $assignment->sso_unit_id,
                'user_id' => $assignment->sso_user_id === null ? null : (int) $assignment->sso_user_id,
                'name' => $assignment->name,
                'start_day_index' => (int) $assignment->start_day_index,
                'starts_on' => $assignment->starts_on === null ? null : self::date($assignment->starts_on),
                'ends_on' => $assignment->ends_on === null ? null : self::date($assignment->ends_on),
                'is_active' => (bool) $assignment->is_active,
            ])->values()->all(),
        ], $rows);
    }

    private static function date(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}

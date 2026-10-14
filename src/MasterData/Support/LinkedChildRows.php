<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Child rows linked to SSO's stable child ids (a person's licences, a
 * vehicle's crew levels), reconciled in place: a linked child is updated, an
 * unlinked local child with the same key is adopted when exactly one fits,
 * a new child is inserted, and a child SSO stopped listing is handled by the
 * table's ChildRowPolicy (unlink, deactivate or delete). Rows are never
 * deleted and re-created, because CloudPCR derives NEMSIS group ids from
 * the local row id, and never deleted at all unless the policy says so.
 *
 * Child tables carry no company_id; the caller has already pinned the
 * parent row to the company (§4a).
 */
final class LinkedChildRows
{
    /** @var array<string, bool> */
    private array $columns = [];

    /**
     * @param  list<array{id: int, columns: array<string, mixed>, key: string}>  $wanted  SSO's children in order
     * @param  Closure(object): string  $localKey  the adoption key of a local row, '' for none
     * @param  Closure(mixed, mixed): bool  $same  whether a stored value equals a wanted one
     * @return array{changed: bool, unlinked: list<int>} unlinked = local rows SSO never knew, kept
     */
    public function reconcile(
        string $table,
        string $parentColumn,
        int $parentId,
        string $ssoColumn,
        array $wanted,
        Closure $localKey,
        Closure $same,
        ChildRowPolicy $policy,
    ): array {
        $marker = $this->hasColumn($table, ChildRowPolicy::REMOVED_AT_COLUMN);
        $rows = DB::table($table)->where($parentColumn, $parentId)->orderBy('id')->get();
        $linked = $rows->filter(fn (object $row): bool => $row->{$ssoColumn} !== null)->keyBy(fn (object $row): int => (int) $row->{$ssoColumn});
        $unlinked = $rows->filter(fn (object $row): bool => $row->{$ssoColumn} === null)->values();
        $changed = false;
        $seen = [];

        foreach ($wanted as ['id' => $ssoChildId, 'columns' => $columns, 'key' => $key]) {
            $seen[$ssoChildId] = true;
            $row = $linked->get($ssoChildId);

            if ($row === null && $key !== '') {
                $candidates = $unlinked->filter(fn (object $local): bool => $localKey($local) === $key);

                if ($candidates->count() === 1) {
                    $row = $candidates->first();
                    $unlinked = $unlinked->reject(fn (object $local): bool => $local->id === $row->id)->values();
                    $columns[$ssoColumn] = $ssoChildId;
                }
            }

            if ($row === null) {
                DB::table($table)->insert($columns + [$parentColumn => $parentId, $ssoColumn => $ssoChildId] + $this->timestamps($table, created: true));
                $changed = true;

                continue;
            }

            if ($marker) {
                $columns[ChildRowPolicy::REMOVED_AT_COLUMN] = null;
            }

            if ($policy->isInactive($row)) {
                $columns[(string) $policy->column] = $policy->activeValue;
            }

            $diff = array_filter($columns, fn (mixed $value, string $column): bool => ! $same($row->{$column} ?? null, $value), ARRAY_FILTER_USE_BOTH);

            if ($diff !== []) {
                DB::table($table)->where($parentColumn, $parentId)->where('id', $row->id)->update($diff + $this->timestamps($table));
                $changed = true;
            }
        }

        $dropped = $linked->reject(fn (object $row, int $ssoChildId): bool => isset($seen[$ssoChildId]))->values();

        if ($dropped->isNotEmpty() && $this->remove($table, $parentColumn, $parentId, $ssoColumn, $dropped->all(), $policy, $marker)) {
            $changed = true;
        }

        $neverKnown = $unlinked->filter(fn (object $row): bool => ! $marker || $row->{ChildRowPolicy::REMOVED_AT_COLUMN} === null);

        return ['changed' => $changed, 'unlinked' => $neverKnown->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all()];
    }

    /** Whether the table has the `sso_removed_at` stamp (the child-row migration ran). */
    public function hasRemovedMarker(string $table): bool
    {
        return $this->hasColumn($table, ChildRowPolicy::REMOVED_AT_COLUMN);
    }

    /**
     * @param  list<object>  $rows  linked rows whose SSO child is gone
     */
    private function remove(string $table, string $parentColumn, int $parentId, string $ssoColumn, array $rows, ChildRowPolicy $policy, bool $marker): bool
    {
        if ($policy->removal === ChildRemoval::Deactivate) {
            $rows = array_values(array_filter($rows, fn (object $row): bool => ! $policy->isInactive($row) || ($marker && $row->{ChildRowPolicy::REMOVED_AT_COLUMN} === null)));
        }

        if ($rows === []) {
            return false;
        }

        $ids = array_map(fn (object $row): int => (int) $row->id, $rows);
        $query = DB::table($table)->where($parentColumn, $parentId)->whereIn('id', $ids);
        $stamp = $marker ? [ChildRowPolicy::REMOVED_AT_COLUMN => now()] : [];

        match ($policy->removal) {
            ChildRemoval::Delete => $query->delete(),
            ChildRemoval::Unlink => $query->update([$ssoColumn => null] + $stamp + $this->timestamps($table)),
            ChildRemoval::Deactivate => $query->update([(string) $policy->column => $policy->inactiveValue] + $stamp + $this->timestamps($table)),
        };

        if ($policy->removal !== ChildRemoval::Delete) {
            Log::info('SSO master data: child rows SSO removed were kept', [
                'table' => $table,
                'parent_id' => $parentId,
                'child_ids' => $ids,
                'policy' => $policy->removal->value,
            ]);
        }

        return true;
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
        return $this->columns["{$table}.{$column}"] ??= Schema::hasColumn($table, $column);
    }
}

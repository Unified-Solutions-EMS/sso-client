<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Child rows a projection keeps beside a mirrored row (a facility's phones,
 * its designations or NPIs when the app keeps them as rows), written from
 * SSO's list in place: the existing rows are compared with SSO's in order,
 * a row that differs is updated, missing ones are inserted, and extra local
 * rows are handled by `$onRemoved`. Nothing is written when they already
 * match, so a resync that changes nothing touches nothing.
 *
 * Rows are matched by position, not by an SSO child id, so this helper is
 * only for rows that are a pure copy of SSO's list: `delete` (the default)
 * removes the extras. A table that carries the app's own data on its rows
 * passes `ChildRowPolicy::deactivate(...)`: extras are switched off and
 * stamped `sso_removed_at` (when the column exists), inactive rows are never
 * reused for another child, and new children get new rows. `unlink` needs an
 * SSO child id to clear and is refused here. Child rows that other records
 * point at by id need the reconcile by SSO child id instead
 * (LinkedChildRows). The caller has already pinned the parent row to the
 * company (§4a); child tables carry no company_id.
 */
class ChildRows
{
    /**
     * @param  list<array<string, mixed>>  $rows  wanted rows, columns other than the parent column, in order
     * @return bool whether anything changed
     */
    public static function sync(string $table, string $parentColumn, int $parentId, array $rows, ?string $orderColumn = 'sort_order', ?ChildRowPolicy $onRemoved = null): bool
    {
        $onRemoved ??= ChildRowPolicy::delete();

        if ($onRemoved->removal === ChildRemoval::Unlink) {
            throw new LogicException("Child rows in [{$table}] are matched by position and have no SSO link to clear; use delete or deactivate.");
        }

        $existing = DB::table($table)
            ->where($parentColumn, $parentId)
            ->when($orderColumn !== null, fn ($query) => $query->orderBy($orderColumn))
            ->orderBy('id')
            ->get()
            ->reject(fn (object $row): bool => $onRemoved->isInactive($row))
            ->values();

        $timestamps = Schema::hasColumn($table, 'updated_at');
        $changed = false;

        foreach (array_values($rows) as $index => $row) {
            if ($orderColumn !== null) {
                $row[$orderColumn] ??= $index;
            }

            $current = $existing->get($index);

            if ($current === null) {
                $active = $onRemoved->removal === ChildRemoval::Deactivate ? [(string) $onRemoved->column => $onRemoved->activeValue] : [];
                DB::table($table)->insert($row + $active + [$parentColumn => $parentId] + ($timestamps ? ['created_at' => now(), 'updated_at' => now()] : []));
                $changed = true;

                continue;
            }

            $diff = array_filter($row, fn (mixed $value, string $column): bool => ! ChildRowPolicy::same($current->{$column} ?? null, $value), ARRAY_FILTER_USE_BOTH);

            if ($diff !== []) {
                DB::table($table)->where('id', $current->id)->update($diff + ($timestamps ? ['updated_at' => now()] : []));
                $changed = true;
            }
        }

        $extra = $existing->slice(count($rows))->pluck('id')->all();

        if ($extra === []) {
            return $changed;
        }

        $query = DB::table($table)->where($parentColumn, $parentId)->whereIn('id', $extra);

        if ($onRemoved->removal === ChildRemoval::Delete) {
            $query->delete();

            return true;
        }

        $query->update(
            [(string) $onRemoved->column => $onRemoved->inactiveValue]
            + (Schema::hasColumn($table, ChildRowPolicy::REMOVED_AT_COLUMN) ? [ChildRowPolicy::REMOVED_AT_COLUMN => now()] : [])
            + ($timestamps ? ['updated_at' => now()] : []),
        );

        return true;
    }
}

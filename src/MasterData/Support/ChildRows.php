<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Child rows a projection keeps beside a mirrored row (a facility's phones,
 * its designations or NPIs when the app keeps them as rows), written from
 * SSO's list in place: the existing rows are compared with SSO's in order,
 * a row that differs is updated, extra local rows are deleted and missing
 * ones inserted. Nothing is written when they already match, so a resync
 * that changes nothing touches nothing.
 *
 * Child rows that other records point at by id need the vehicle-style
 * reconcile by SSO child id instead (VehicleMirror); this helper is for
 * rows nothing else references. The caller has already pinned the parent
 * row to the company (§4a); child tables carry no company_id.
 */
class ChildRows
{
    /**
     * @param  list<array<string, mixed>>  $rows  wanted rows, columns other than the parent column, in order
     * @return bool whether anything changed
     */
    public static function sync(string $table, string $parentColumn, int $parentId, array $rows, ?string $orderColumn = 'sort_order'): bool
    {
        $existing = DB::table($table)
            ->where($parentColumn, $parentId)
            ->when($orderColumn !== null, fn ($query) => $query->orderBy($orderColumn))
            ->orderBy('id')
            ->get()
            ->values();

        $timestamps = Schema::hasColumn($table, 'updated_at');
        $changed = false;

        foreach (array_values($rows) as $index => $row) {
            if ($orderColumn !== null) {
                $row[$orderColumn] ??= $index;
            }

            $current = $existing->get($index);

            if ($current === null) {
                DB::table($table)->insert($row + [$parentColumn => $parentId] + ($timestamps ? ['created_at' => now(), 'updated_at' => now()] : []));
                $changed = true;

                continue;
            }

            $diff = array_filter($row, fn (mixed $value, string $column): bool => ! self::same($current->{$column} ?? null, $value), ARRAY_FILTER_USE_BOTH);

            if ($diff !== []) {
                DB::table($table)->where('id', $current->id)->update($diff + ($timestamps ? ['updated_at' => now()] : []));
                $changed = true;
            }
        }

        $extra = $existing->slice(count($rows))->pluck('id')->all();

        if ($extra !== []) {
            DB::table($table)->where($parentColumn, $parentId)->whereIn('id', $extra)->delete();
            $changed = true;
        }

        return $changed;
    }

    private static function same(mixed $stored, mixed $value): bool
    {
        if ($stored === null || $value === null) {
            return $stored === null && $value === null;
        }

        if (is_bool($value)) {
            return (bool) $stored === $value;
        }

        return (string) $stored === (string) $value;
    }
}

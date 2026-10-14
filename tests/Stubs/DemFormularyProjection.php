<?php

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CloudPCR's medication / procedure tree, as its projections would handle
 * it: per company one `dem_configurations` row per reporting state
 * (dconfiguration_01 = state FIPS), under it one group per certification
 * level (medications: `dem_configuration_medication_groups.dconfiguration_08`;
 * procedures: `dem_configuration_procedure_groups.dconfiguration_06`), and
 * under each group one item per code (medications:
 * `dem_configuration_medication_items.dconfiguration_09` + name, code_type,
 * is_controlled; procedures: `dem_configuration_procedure_items.dconfiguration_07`
 * + name, code_type). So one code repeats per state and per level.
 *
 * localItems() collapses that to one entry per code: the first non-empty
 * name, the union of the levels of every group it sits in, controlled when
 * any item says so, and "only report in" states when the agency reports to
 * several states and the code is missing from some of them.
 *
 * project() is the other way: for each state configuration, the wanted tree
 * is one group per level (in the order levels first appear in SSO's list)
 * holding the turned-on, chartable codes for that state in SSO's order,
 * named by `label`. A configuration whose tree already matches is left
 * alone; one that differs is rebuilt (charts, exports and report views key
 * on codes, never on these ids). The real projection also translates a
 * primary-state level a secondary state does not know (value-append), and
 * must keep the DEM golden files green.
 */
abstract class DemFormularyProjection
{
    abstract protected function groupsTable(): string;

    abstract protected function itemsTable(): string;

    abstract protected function groupKey(): string;

    abstract protected function levelColumn(): string;

    abstract protected function codeColumn(): string;

    protected function controlled(): bool
    {
        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function localItems(int $localCompanyId): array
    {
        $configs = $this->configs($localCompanyId);
        $states = $configs->pluck('dconfiguration_01')->filter()->unique()->values()->all();
        $entries = [];

        foreach ($this->tree($configs->pluck('id')->all()) as $item) {
            $code = trim((string) $item->code);

            if ($code === '') {
                continue;
            }

            $entry = $entries[$code] ?? ['key' => $code, 'code' => $code, 'label' => '', 'levels' => [], 'states' => [], 'is_controlled' => false];
            $entry['label'] = $entry['label'] !== '' ? $entry['label'] : trim((string) $item->name);
            $entry['levels'] = array_values(array_unique(array_filter([...$entry['levels'], (string) $item->level])));
            $entry['states'] = array_values(array_unique(array_filter([...$entry['states'], (string) $configs->firstWhere('id', $item->config_id)?->dconfiguration_01])));
            $entry['is_controlled'] = $entry['is_controlled'] || (bool) ($item->is_controlled ?? false);
            $entries[$code] = $entry;
        }

        return array_values(array_map(function (array $entry) use ($states): array {
            $limited = count($states) > 1 && array_diff($states, $entry['states']) !== [];
            $item = ['key' => $entry['key'], 'code' => $entry['code'], 'label' => $entry['label'] !== '' ? $entry['label'] : $entry['code'], 'levels' => $entry['levels'], 'only_states' => $limited ? $entry['states'] : null];

            return $this->controlled() ? $item + ['is_controlled' => $entry['is_controlled']] : $item;
        }, $entries));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function project(int $localCompanyId, array $items): void
    {
        foreach ($this->configs($localCompanyId) as $config) {
            $wanted = $this->wanted($items, (string) $config->dconfiguration_01);

            if ($wanted === $this->current((int) $config->id)) {
                continue;
            }

            $groupIds = DB::table($this->groupsTable())->where('dem_configuration_id', $config->id)->pluck('id')->all();
            DB::table($this->itemsTable())->whereIn($this->groupKey(), $groupIds)->delete();
            DB::table($this->groupsTable())->where('dem_configuration_id', $config->id)->delete();

            $groupOrder = 0;
            foreach ($wanted as $level => $codes) {
                $groupId = DB::table($this->groupsTable())->insertGetId([
                    'dem_configuration_id' => $config->id,
                    $this->levelColumn() => $level,
                    'sort_order' => $groupOrder++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach (array_values($codes) as $index => $code) {
                    DB::table($this->itemsTable())->insert([
                        $this->groupKey() => $groupId,
                        $this->codeColumn() => $code['code'],
                        'name' => $code['name'],
                        'code_type' => $code['code_type'],
                        'sort_order' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ] + ($this->controlled() ? ['is_controlled' => $code['is_controlled']] : []));
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, list<array<string, mixed>>>
     */
    private function wanted(array $items, string $state): array
    {
        $tree = [];

        foreach ($items as $item) {
            if (! $item['is_active'] || $item['levels'] === [] || ($item['only_states'] !== [] && ! in_array($state, $item['only_states'], true))) {
                continue;
            }

            foreach ($item['levels'] as $level) {
                $tree[$level][] = ['code' => $item['code'], 'name' => $item['label'], 'code_type' => $item['code_type']]
                    + ($this->controlled() ? ['is_controlled' => (bool) ($item['is_controlled'] ?? false)] : []);
            }
        }

        return $tree;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function current(int $configId): array
    {
        $tree = [];

        foreach ($this->tree([$configId]) as $item) {
            $tree[(string) $item->level][] = ['code' => (string) $item->code, 'name' => (string) $item->name, 'code_type' => $item->code_type]
                + ($this->controlled() ? ['is_controlled' => (bool) $item->is_controlled] : []);
        }

        return $tree;
    }

    /**
     * @param  list<int>  $configIds
     * @return Collection<int, object>
     */
    private function tree(array $configIds): Collection
    {
        $groups = $this->groupsTable();
        $items = $this->itemsTable();

        return DB::table($items)
            ->join($groups, "{$groups}.id", '=', "{$items}.{$this->groupKey()}")
            ->whereIn("{$groups}.dem_configuration_id", $configIds)
            ->orderBy("{$groups}.dem_configuration_id")
            ->orderBy("{$groups}.sort_order")
            ->orderBy("{$groups}.id")
            ->orderBy("{$items}.sort_order")
            ->orderBy("{$items}.id")
            ->get(array_values(array_filter([
                "{$items}.{$this->codeColumn()} as code",
                "{$items}.name",
                "{$items}.code_type",
                $this->controlled() ? "{$items}.is_controlled" : null,
                "{$groups}.{$this->levelColumn()} as level",
                "{$groups}.dem_configuration_id as config_id",
            ])));
    }

    /**
     * @return Collection<int, object>
     */
    private function configs(int $localCompanyId): Collection
    {
        return DB::table('dem_configurations')->where('company_id', $localCompanyId)->whereNull('deleted_at')->orderBy('id')->get(['id', 'dconfiguration_01']);
    }
}

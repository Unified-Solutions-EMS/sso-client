<?php

namespace Unified\SsoClient\Settings;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;

/**
 * Checks a patch against the schema so every app returns the same messages.
 * Produces unknown_key and invalid results; the keys that pass are handed to
 * the provider. Never produces blocked: that is the provider's call.
 *
 * An empty string for a secret setting is dropped as if the key were absent:
 * it is what a form sends back for a masked field it never showed.
 *
 * A table setting's value is `{rows: [{id?, <column>...}]}`. Each row is
 * checked against the declared columns: a row without an id (a new one)
 * against all of them, a row with an id (a change) against the columns it
 * sends. A select column with `options_from_values` is checked against the
 * options the provider's values() gives for this company. The provider gets
 * `{rows: [...]}` with each row's id (an int) and only its declared columns;
 * one bad row turns the whole key invalid and nothing of it is applied.
 */
final class SettingsPatchValidator
{
    public function __construct(private readonly ValidationFactory $validator) {}

    /**
     * @param  array<string|int, mixed>  $patch
     * @param  array<string, mixed>|null  $values  the provider's values() for the company (table select options)
     * @return array{0: array<string, mixed>, 1: SettingsResult}
     */
    public function validate(SettingsSchema $schema, array $patch, ?array $values = null): array
    {
        $accepted = [];
        $result = SettingsResult::make();

        foreach ($patch as $key => $value) {
            $key = (string) $key;
            $setting = $schema->find($key);

            if ($setting === null) {
                $result->unknownKey($key);

                continue;
            }

            if ($setting->isSecret() && $value === '') {
                continue;
            }

            $message = $this->firstError($setting, $value);

            if ($message === null && $setting->type === SettingType::Table) {
                [$value, $message] = $this->tableRows($setting, $value, $values[$key] ?? null);
            }

            if ($message === null) {
                $accepted[$key] = $value;
            } else {
                $result->invalid($key, $message);
            }
        }

        return [$accepted, $result];
    }

    /**
     * Validates under a fixed attribute name because setting keys are dotted
     * and Laravel would read the dots as nesting.
     */
    private function firstError(Setting $setting, mixed $value): ?string
    {
        $rules = ['value' => $setting->valueRules()];
        $itemRules = $setting->itemRules();

        if ($itemRules !== []) {
            $rules['value.*'] = $itemRules;
        }

        $validator = $this->validator->make(
            ['value' => $value],
            $rules,
            [],
            ['value' => $setting->label, 'value.*' => $setting->label],
        );

        return $validator->fails() ? $validator->errors()->first() : null;
    }

    /**
     * @return array{0: array{rows: list<array<string, mixed>>}|null, 1: string|null}
     */
    private function tableRows(Setting $setting, mixed $value, mixed $current): array
    {
        $rows = is_array($value) ? ($value['rows'] ?? null) : null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            return [null, "{$setting->label} needs a list of rows."];
        }

        $options = is_array($current) && is_array($current['options'] ?? null) ? $current['options'] : [];
        $accepted = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $number = $index + 1;

            if (! is_array($row)) {
                return [null, "Row {$number} of {$setting->label} is not a row."];
            }

            $id = $row['id'] ?? null;

            if ($id !== null && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return [null, "Row {$number} of {$setting->label} has an id that is not a whole number."];
            }

            $id = $id === null ? null : (int) $id;

            if ($id !== null && isset($seen[$id])) {
                return [null, "Row {$number} of {$setting->label} repeats a row already in this change."];
            }

            $rules = [];
            $attributes = [];

            foreach ($setting->columns() as $key => $column) {
                $companyOptions = is_array($options[$key] ?? null) ? TableColumn::normalizeOptions($options[$key]) : [];
                $rules[$key] = $column->rules($id !== null, $companyOptions);
                $attributes[$key] = $column->label;
            }

            $data = array_intersect_key($row, $setting->columns());
            $validator = $this->validator->make($data, $rules, [], $attributes);

            if ($validator->fails()) {
                return [null, "Row {$number}: ".$validator->errors()->first()];
            }

            foreach ($data as $key => $cell) {
                $data[$key] = match (true) {
                    $setting->columns()[$key]->type === 'toggle' && $cell !== null => filter_var($cell, FILTER_VALIDATE_BOOLEAN),
                    $cell === '' => null,
                    default => $cell,
                };
            }

            if ($id !== null) {
                $seen[$id] = true;
                $data = ['id' => $id] + $data;
            }

            $accepted[] = $data;
        }

        return [['rows' => $accepted], null];
    }
}

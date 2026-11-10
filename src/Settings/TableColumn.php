<?php

namespace Unified\SsoClient\Settings;

use Closure;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * One column of a table setting (SettingType::Table). Declared through
 * SettingsSchema::columns() as
 * `['key' => 'last_name', 'label' => 'Last name', 'type' => 'text'|'select'|'toggle',
 *   'required' => bool, 'options' => [{value, label}] | 'options_from_values' => true, 'max' => int]`.
 *
 * A select either lists its options here (the same for every agency) or
 * sets `options_from_values`, and the provider's values() sends that
 * agency's options as `{rows, options: {<column key>: [{value, label}]}}`.
 */
final class TableColumn
{
    public const TYPES = ['text', 'select', 'toggle'];

    public const DEFAULT_MAX = 255;

    /**
     * @param  list<array{value: string|int, label: string}>|null  $options
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $required,
        public readonly ?array $options,
        public readonly bool $optionsFromValues,
        public readonly int $max,
    ) {}

    /**
     * @param  array<string, mixed>  $column
     */
    public static function fromArray(string $settingKey, array $column): self
    {
        $key = $column['key'] ?? null;
        $type = $column['type'] ?? 'text';
        $label = $column['label'] ?? null;

        if (! is_string($key) || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1 || $key === 'id') {
            throw new InvalidArgumentException("Table setting [{$settingKey}] has a column without a usable key (letters, digits and _; 'id' is the row's own).");
        }

        if (! is_string($label) || $label === '') {
            throw new InvalidArgumentException("Column [{$key}] of table setting [{$settingKey}] needs a label.");
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Column [{$key}] of table setting [{$settingKey}] has type [".(is_scalar($type) ? $type : gettype($type)).'], expected one of '.implode(', ', self::TYPES).'.');
        }

        $fromValues = (bool) ($column['options_from_values'] ?? false);
        $options = isset($column['options']) && is_array($column['options']) ? self::normalizeOptions($column['options']) : null;

        if ($type === 'select' && ! $fromValues && ($options === null || $options === [])) {
            throw new InvalidArgumentException("Select column [{$key}] of table setting [{$settingKey}] needs options or options_from_values.");
        }

        if ($type !== 'select' && ($fromValues || $options !== null)) {
            throw new InvalidArgumentException("Column [{$key}] of table setting [{$settingKey}] is not a select and cannot have options.");
        }

        return new self(
            key: $key,
            label: $label,
            type: $type,
            required: (bool) ($column['required'] ?? false),
            options: $fromValues ? null : $options,
            optionsFromValues: $fromValues,
            max: max(1, (int) ($column['max'] ?? self::DEFAULT_MAX)),
        );
    }

    /**
     * Accepts [{value, label}] or value => label.
     *
     * @param  array<int|string, mixed>  $options
     * @return list<array{value: string|int, label: string}>
     */
    public static function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $value => $option) {
            if (is_array($option) && array_key_exists('value', $option) && (is_string($option['value']) || is_int($option['value']))) {
                $normalized[] = ['value' => $option['value'], 'label' => (string) ($option['label'] ?? $option['value'])];
            } elseif (is_scalar($option)) {
                $normalized[] = ['value' => $value, 'label' => (string) $option];
            }
        }

        return $normalized;
    }

    /**
     * Rules for this column's value in one row. A row that changes an
     * existing record ($partial) checks only the columns it sends.
     *
     * @param  list<array{value: string|int, label: string}>  $companyOptions  values()' options for an options_from_values column
     * @return array<int, mixed>
     */
    public function rules(bool $partial, array $companyOptions = []): array
    {
        $presence = $this->required ? ['required'] : ['nullable'];

        if ($partial) {
            array_unshift($presence, 'sometimes');
        }

        $rules = match ($this->type) {
            'toggle' => ['boolean'],
            'select' => [$this->inOptions(array_column($this->optionsFromValues ? $companyOptions : (array) $this->options, 'value'))],
            default => ['string', 'max:'.$this->max],
        };

        return [...$presence, ...$rules];
    }

    /**
     * Laravel's `in` rule needs at least one value; an agency with no
     * options yet accepts only an empty cell.
     *
     * @param  array<int, string|int>  $values
     */
    private function inOptions(array $values): mixed
    {
        return $values !== []
            ? Rule::in($values)
            : static function (string $attribute, mixed $value, Closure $fail): void {
                $fail('The selected :attribute is invalid.');
            };
    }

    /**
     * @return array{key: string, label: string, type: string, required: bool, options: list<array{value: string|int, label: string}>|null, options_from_values: bool, max: int|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'options' => $this->options,
            'options_from_values' => $this->optionsFromValues,
            'max' => $this->type === 'text' ? $this->max : null,
        ];
    }

    /**
     * How the AI tool description names this column's values.
     */
    public function describe(): string
    {
        $what = match (true) {
            $this->type === 'toggle' => 'true or false',
            $this->optionsFromValues => "one of the values listed under options.{$this->key} in the current value",
            $this->type === 'select' => 'one of: '.implode(', ', array_map(
                fn (array $option): string => $option['value'].' ('.$option['label'].')',
                (array) $this->options,
            )),
            default => "text, up to {$this->max} characters",
        };

        return "{$this->label} ({$this->key}): {$what}".($this->required ? ', required' : '');
    }
}

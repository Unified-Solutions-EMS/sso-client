<?php

namespace Unified\SsoClient\Settings;

use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;
use Stringable;

/**
 * One declared setting. Built through SettingsSchema's fluent API; apps do
 * not construct these directly.
 */
final class Setting
{
    private ?string $help = null;

    private mixed $default = null;

    /** @var array<int, mixed> */
    private array $rules = [];

    /** @var array<int, string> */
    private array $requires = [];

    private bool $danger = false;

    private bool $secret = false;

    /** @var array<string, TableColumn> */
    private array $columns = [];

    /**
     * @param  array<int, array{value: string|int, label: string}>  $options
     */
    public function __construct(
        public readonly string $key,
        public readonly SettingType $type,
        public readonly string $label,
        public readonly string $group,
        public readonly array $options = [],
        public readonly ?SettingEntity $entity = null,
    ) {}

    public function setHelp(?string $help): void
    {
        $this->help = $help;
    }

    public function setDefault(mixed $default): void
    {
        $this->default = $default;
    }

    /**
     * @param  string|array<int, mixed>  $rules
     */
    public function setRules(string|array $rules): void
    {
        $this->rules = is_string($rules) ? explode('|', $rules) : array_values($rules);
    }

    /**
     * @param  array<int, string>  $requires
     */
    public function setRequires(array $requires): void
    {
        $this->requires = array_values(array_unique($requires));
    }

    public function setDanger(bool $danger): void
    {
        $this->danger = $danger;
    }

    public function setSecret(bool $secret): void
    {
        $this->secret = $secret;
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     */
    public function setColumns(array $columns): void
    {
        if ($this->type !== SettingType::Table) {
            throw new LogicException("columns() only applies to a table setting; [{$this->key}] is a {$this->type->value}.");
        }

        if ($columns === []) {
            throw new InvalidArgumentException("Table setting [{$this->key}] needs at least one column.");
        }

        $this->columns = [];

        foreach ($columns as $column) {
            $column = TableColumn::fromArray($this->key, (array) $column);

            if (isset($this->columns[$column->key])) {
                throw new InvalidArgumentException("Table setting [{$this->key}] declares column [{$column->key}] twice.");
            }

            $this->columns[$column->key] = $column;
        }
    }

    /**
     * @return array<string, TableColumn>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }

    /**
     * A secret's default is never exposed: it would ship in the schema to SSO
     * and the AI tool description, and would make has_value read true.
     */
    public function default(): mixed
    {
        if ($this->secret) {
            return null;
        }

        return $this->type === SettingType::Table ? ($this->default ?? ['rows' => [], 'options' => []]) : $this->default;
    }

    public function isDanger(): bool
    {
        return $this->danger;
    }

    /**
     * Rules for the value itself: nullable unless the app declared `required`,
     * then the type's base rule, then the app's own rules.
     *
     * @return array<int, mixed>
     */
    public function valueRules(): array
    {
        $rules = $this->type->baseRules();

        if ($this->type === SettingType::Select) {
            $rules[] = Rule::in($this->optionValues());
        }

        $nullable = in_array('required', $this->rules, true) ? [] : ['nullable'];

        return [...$nullable, ...$rules, ...$this->rules];
    }

    /**
     * Rules applied to each element of a multi-select value.
     *
     * @return array<int, mixed>
     */
    public function itemRules(): array
    {
        return $this->type === SettingType::MultiSelect ? [Rule::in($this->optionValues())] : [];
    }

    /**
     * @return array<int, string|int>
     */
    private function optionValues(): array
    {
        return array_column($this->options, 'value');
    }

    /**
     * What the AI setup assistant reads about a table setting: its columns
     * and how a change is sent. Null for every other type (the label, help
     * and options already say it).
     */
    public function description(): ?string
    {
        if ($this->type !== SettingType::Table) {
            return null;
        }

        $columns = implode('; ', array_map(fn (TableColumn $column): string => $column->describe(), array_values($this->columns)));
        $active = $this->columns['is_active'] ?? null;
        $off = $active !== null && $active->type === 'toggle'
            ? "Rows are never deleted: set {$active->label} (is_active) to false to turn one off."
            : 'Rows are never deleted.';

        return "A list of rows. Each row has an id and these columns: {$columns}. "
            .'To change it, send {"rows": [...]} with only the rows that change: a row without an id adds one, '
            .'a row with an id changes that row and only the columns it sends. '.$off;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $table = $this->type === SettingType::Table ? [
            'columns' => array_values(array_map(fn (TableColumn $column): array => $column->toArray(), $this->columns)),
            'description' => $this->description(),
        ] : [];

        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'entity' => $this->entity?->value,
            'label' => $this->label,
            'help' => $this->help,
            'options' => $this->type->hasOptions() ? $this->options : null,
            'validation' => $this->serializableRules(),
            'default' => $this->default(),
            'requires' => $this->requires,
            'danger' => $this->danger,
            'secret' => $this->secret,
            'group' => $this->group,
        ] + $table;
    }

    /**
     * Closures and custom rule objects can't cross the wire; they still run
     * server-side, so the renderer only loses a hint, never a check.
     *
     * @return array<int, string>
     */
    private function serializableRules(): array
    {
        $rules = [];

        foreach ($this->valueRules() as $rule) {
            if (is_string($rule)) {
                $rules[] = $rule;
            } elseif ($rule instanceof Stringable) {
                $rules[] = (string) $rule;
            }
        }

        return $rules;
    }
}

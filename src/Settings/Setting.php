<?php

namespace Unified\SsoClient\Settings;

use Illuminate\Validation\Rule;
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
        return $this->secret ? null : $this->default;
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
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
        ];
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

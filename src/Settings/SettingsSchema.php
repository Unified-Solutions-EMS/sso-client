<?php

namespace Unified\SsoClient\Settings;

use InvalidArgumentException;
use LogicException;

/**
 * Fluent declaration of an app's agency settings:
 *
 *   SettingsSchema::make()
 *       ->group('dispatch', 'Dispatch alerts')
 *       ->toggle('alerts.pre_pickup', 'Pre-pickup alert')->default(true)
 *       ->number('alerts.pre_pickup_minutes', 'Minutes before pickup')->rules('integer|min:1|max:120')
 *           ->requires('alerts.pre_pickup');
 *
 * Setting methods (text, toggle, select, ...) add to the current group;
 * modifier methods (help, default, rules, requires, danger, secret) change the
 * setting declared just before them.
 */
final class SettingsSchema
{
    /** @var array<string, SettingsGroup> */
    private array $groups = [];

    /** @var array<string, Setting> */
    private array $settings = [];

    private ?SettingsGroup $currentGroup = null;

    private ?Setting $current = null;

    public static function make(): self
    {
        return new self;
    }

    public function group(string $key, string $label, ?int $order = null): self
    {
        $this->currentGroup = $this->groups[$key] ??= new SettingsGroup($key, $label, $order ?? count($this->groups) + 1);
        $this->current = null;

        return $this;
    }

    public function text(string $key, string $label): self
    {
        return $this->add($key, SettingType::Text, $label);
    }

    public function textarea(string $key, string $label): self
    {
        return $this->add($key, SettingType::Textarea, $label);
    }

    public function number(string $key, string $label): self
    {
        return $this->add($key, SettingType::Number, $label);
    }

    public function toggle(string $key, string $label): self
    {
        return $this->add($key, SettingType::Toggle, $label);
    }

    public function time(string $key, string $label): self
    {
        return $this->add($key, SettingType::Time, $label);
    }

    /**
     * @param  array<string|int, string>  $options  value => label
     */
    public function select(string $key, string $label, array $options): self
    {
        return $this->add($key, SettingType::Select, $label, $options);
    }

    /**
     * @param  array<string|int, string>  $options  value => label
     */
    public function multiSelect(string $key, string $label, array $options): self
    {
        return $this->add($key, SettingType::MultiSelect, $label, $options);
    }

    public function entity(string $key, string $label, SettingEntity|string $entity): self
    {
        return $this->add($key, SettingType::Entity, $label, entity: is_string($entity) ? SettingEntity::from($entity) : $entity);
    }

    public function help(string $help): self
    {
        $this->currentSetting()->setHelp($help);

        return $this;
    }

    public function default(mixed $default): self
    {
        $this->currentSetting()->setDefault($default);

        return $this;
    }

    /**
     * Laravel validation rules for the value, as a pipe string or array.
     * Rules see the value alone, so cross-field rules (required_if, ...)
     * don't apply; express dependencies with requires() and enforce them in
     * the provider by returning blocked().
     *
     * @param  string|array<int, mixed>  $rules
     */
    public function rules(string|array $rules): self
    {
        $this->currentSetting()->setRules($rules);

        return $this;
    }

    /**
     * Other setting keys or master-data entity types (vehicle, station, ...)
     * this setting depends on.
     */
    public function requires(string ...$dependencies): self
    {
        $this->currentSetting()->setRequires($dependencies);

        return $this;
    }

    /**
     * Marks a setting that fans out or can't be undone: the renderer asks for
     * an explicit confirm and never autosaves it.
     */
    public function danger(bool $danger = true): self
    {
        $this->currentSetting()->setDanger($danger);

        return $this;
    }

    /**
     * Marks a credential (integration token, password). GET never returns its
     * value, only whether one is set; on PATCH an empty string means
     * "unchanged" so a form that round-trips the masked field can't blank it.
     */
    public function secret(bool $secret = true): self
    {
        $this->currentSetting()->setSecret($secret);

        return $this;
    }

    public function find(string $key): ?Setting
    {
        return $this->settings[$key] ?? null;
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return array_map(fn (Setting $setting): mixed => $setting->default(), $this->settings);
    }

    /**
     * @return array{groups: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        $groups = array_values($this->groups);
        usort($groups, fn (SettingsGroup $a, SettingsGroup $b): int => $a->order <=> $b->order);

        return [
            'groups' => array_map(fn (SettingsGroup $group): array => $group->toArray(), $groups),
        ];
    }

    /**
     * @param  array<string|int, string>  $options
     */
    private function add(string $key, SettingType $type, string $label, array $options = [], ?SettingEntity $entity = null): self
    {
        if ($this->currentGroup === null) {
            throw new LogicException("Setting [{$key}] declared before any group(); call group() first.");
        }

        if (isset($this->settings[$key])) {
            throw new InvalidArgumentException("Setting key [{$key}] is declared twice; keys must be unique per app.");
        }

        if ($type->hasOptions() && $options === []) {
            throw new InvalidArgumentException("Setting [{$key}] is a {$type->value} with no options.");
        }

        $setting = new Setting(
            key: $key,
            type: $type,
            label: $label,
            group: $this->currentGroup->key,
            options: array_map(
                fn (string|int $value, string $optionLabel): array => ['value' => $value, 'label' => $optionLabel],
                array_keys($options),
                $options,
            ),
            entity: $entity,
        );

        $this->currentGroup->add($setting);
        $this->settings[$key] = $setting;
        $this->current = $setting;

        return $this;
    }

    private function currentSetting(): Setting
    {
        return $this->current ?? throw new LogicException('Modifier called before any setting was declared in this group.');
    }
}

<?php

namespace Unified\SsoClient\Settings;

final class SettingsGroup
{
    /** @var array<string, Setting> */
    private array $settings = [];

    private bool $atomic = false;

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $order,
    ) {}

    /**
     * The group's keys only make sense together (pay-period frequency + start
     * date): the renderer saves them in one PATCH behind an explicit Save
     * instead of autosaving each field. Package validation stays per key.
     */
    public function atomic(bool $atomic = true): self
    {
        $this->atomic = $atomic;

        return $this;
    }

    public function isAtomic(): bool
    {
        return $this->atomic;
    }

    public function add(Setting $setting): void
    {
        $this->settings[$setting->key] = $setting;
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * @return array{key: string, label: string, order: int, atomic: bool, settings: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'order' => $this->order,
            'atomic' => $this->atomic,
            'settings' => array_values(array_map(fn (Setting $setting): array => $setting->toArray(), $this->settings)),
        ];
    }
}

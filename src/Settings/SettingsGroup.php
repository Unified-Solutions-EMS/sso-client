<?php

namespace Unified\SsoClient\Settings;

final class SettingsGroup
{
    /** @var array<string, Setting> */
    private array $settings = [];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $order,
    ) {}

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
     * @return array{key: string, label: string, order: int, settings: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'order' => $this->order,
            'settings' => array_values(array_map(fn (Setting $setting): array => $setting->toArray(), $this->settings)),
        ];
    }
}

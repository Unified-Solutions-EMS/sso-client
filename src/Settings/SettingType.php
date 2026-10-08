<?php

namespace Unified\SsoClient\Settings;

enum SettingType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Toggle = 'toggle';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Time = 'time';
    case Entity = 'entity';

    /**
     * @return array<int, string>
     */
    public function baseRules(): array
    {
        return match ($this) {
            self::Text, self::Textarea => ['string'],
            self::Number => ['numeric'],
            self::Toggle => ['boolean'],
            self::MultiSelect => ['array'],
            self::Time => ['date_format:H:i'],
            self::Select, self::Entity => [],
        };
    }

    public function hasOptions(): bool
    {
        return $this === self::Select || $this === self::MultiSelect;
    }
}

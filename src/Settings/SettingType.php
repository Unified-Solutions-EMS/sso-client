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
     * A list of rows with declared columns (TableColumn). The value is
     * `{rows: [{id, <column>...}], options: {<column>: [{value, label}]}}`;
     * a patch is `{rows: [...]}` with only the rows that change.
     */
    case Table = 'table';

    /**
     * @return array<int, string>
     */
    public function baseRules(): array
    {
        return match ($this) {
            self::Text, self::Textarea => ['string'],
            self::Number => ['numeric'],
            self::Toggle => ['boolean'],
            self::MultiSelect, self::Table => ['array'],
            self::Time => ['date_format:H:i'],
            self::Select, self::Entity => [],
        };
    }

    public function hasOptions(): bool
    {
        return $this === self::Select || $this === self::MultiSelect;
    }
}

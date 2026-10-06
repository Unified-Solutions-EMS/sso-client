<?php

namespace Unified\SsoClient\Settings;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;

/**
 * Checks a patch against the schema so every app returns the same messages.
 * Produces unknown_key and invalid results; the keys that pass are handed to
 * the provider. Never produces blocked: that is the provider's call.
 */
final class SettingsPatchValidator
{
    public function __construct(private readonly ValidationFactory $validator) {}

    /**
     * @param  array<string|int, mixed>  $patch
     * @return array{0: array<string, mixed>, 1: SettingsResult}
     */
    public function validate(SettingsSchema $schema, array $patch): array
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

            $message = $this->firstError($setting, $value);

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
}

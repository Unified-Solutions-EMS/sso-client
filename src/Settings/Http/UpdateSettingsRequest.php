<?php

namespace Unified\SsoClient\Settings\Http;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Unified\SsoClient\Settings\SettingsActor;
use Unified\SsoClient\Settings\SettingsActorSource;

/**
 * Body shape only. Per-key value validation happens against the app's schema
 * in SettingsPatchValidator and is reported per key with HTTP 200.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'patch' => ['present', 'array'],
            'actor' => ['required', 'array'],
            'actor.sso_user_id' => ['nullable', 'integer'],
            'actor.name' => ['required', 'string', 'max:255'],
            'actor.source' => ['required', Rule::enum(SettingsActorSource::class)],
        ];
    }

    /**
     * @return array<string|int, mixed>
     */
    public function settingsPatch(): array
    {
        return $this->input('patch', []);
    }

    public function settingsActor(): SettingsActor
    {
        return SettingsActor::fromArray($this->input('actor'));
    }

    /**
     * Server-to-server caller: always answer JSON, never a redirect, and
     * never echo the submitted values back (settings may hold credentials).
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The settings request body is malformed.',
            'errors' => $validator->errors(),
        ], 422));
    }
}

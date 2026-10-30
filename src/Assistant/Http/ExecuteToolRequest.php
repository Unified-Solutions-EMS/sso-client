<?php

namespace Unified\SsoClient\Assistant\Http;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Body shape only. The tool validates its own arguments. `args` is never
 * logged or echoed back: it may carry PHI.
 */
class ExecuteToolRequest extends FormRequest
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
            'sso_user_id' => ['required', 'integer'],
            'sso_company_id' => ['required', 'integer'],
            'impersonating' => ['sometimes', 'boolean'],
            'args' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function args(): array
    {
        $raw = json_decode($this->getContent(), true);

        $args = is_array($raw) && is_array($raw['args'] ?? null) ? $raw['args'] : $this->input('args', []);

        return is_array($args) ? $args : [];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'The tool request body is malformed.',
            'errors' => $validator->errors(),
        ], 422));
    }
}

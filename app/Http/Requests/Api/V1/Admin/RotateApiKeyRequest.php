<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RotateApiKeyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'key' => [
                'nullable',
                'string',
                'min:32',
                'max:255',
                'regex:/^[A-Za-z0-9._~-]+$/',
            ],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'revoke_existing' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccessGrantRequest extends FormRequest
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
            'organization_id' => ['sometimes', 'nullable', 'uuid', 'exists:organizations,public_id'],
            'role' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9._-]+$/'],
            'permissions' => ['sometimes', 'nullable', 'array', 'max:100'],
            'permissions.*' => [
                'string',
                'max:100',
                'distinct',
                'regex:/^[a-z0-9._:-]+$/',
            ],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
        ];
    }
}

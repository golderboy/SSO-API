<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\SystemRole;
use App\Rules\ThaiCitizenId;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

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
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'required_if:system_role,super_admin',
                'email:rfc',
                'max:255',
                'unique:users,email',
            ],
            'cid' => ['required', 'string', 'max:32', new ThaiCitizenId],
            'password' => [
                'nullable',
                'required_if:system_role,super_admin',
                'max:255',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'system_role' => [
                'sometimes',
                Rule::in([
                    SystemRole::User->value,
                    SystemRole::SuperAdmin->value,
                ]),
            ],
            'is_super_admin' => ['prohibited'],
        ];
    }
}

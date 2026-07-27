<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Rules\ThaiCitizenId;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
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
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'nullable',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'cid' => ['sometimes', 'required', 'string', 'max:32', new ThaiCitizenId],
            'password' => [
                'sometimes',
                'nullable',
                'max:255',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'is_super_admin' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->route('user');
                $willBeAdmin = $this->exists('is_super_admin')
                    ? $this->boolean('is_super_admin')
                    : (bool) $user?->is_super_admin;
                $email = $this->exists('email') ? $this->input('email') : $user?->email;
                $hasPassword = $this->exists('password')
                    ? is_string($this->input('password')) && $this->input('password') !== ''
                    : $user?->password !== null;

                if (! $willBeAdmin) {
                    return;
                }

                if (! is_string($email) || $email === '') {
                    $validator->errors()->add(
                        'email',
                        'An email address is required for administrator accounts.',
                    );
                }

                if (! $hasPassword) {
                    $validator->errors()->add(
                        'password',
                        'A password is required for administrator accounts.',
                    );
                }
            },
        ];
    }
}

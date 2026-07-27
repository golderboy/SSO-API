<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\IdentityProvider;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationSsoClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'redirect_uris' => ['required', 'array', 'min:1', 'max:5'],
            'redirect_uris.*' => [
                'required',
                'string',
                'max:2048',
                'distinct:strict',
                'url:https',
                function (
                    string $attribute,
                    mixed $value,
                    Closure $fail,
                ): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $parts = parse_url($value);

                    if (
                        $parts === false
                        || ! isset($parts['host'])
                        || isset($parts['user'])
                        || isset($parts['pass'])
                        || isset($parts['fragment'])
                    ) {
                        $fail(
                            'The :attribute must be an exact HTTPS callback URI '
                            .'without user-info or fragment.',
                        );
                    }
                },
            ],
            'allowed_providers' => [
                'required',
                'array',
                'min:1',
                'max:2',
            ],
            'allowed_providers.*' => [
                'required',
                'string',
                'distinct:strict',
                Rule::enum(IdentityProvider::class),
            ],
        ];
    }
}

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ThaiCitizenId implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $rawCid = (string) $value;

        if (preg_match('/^[0-9\-\s]+$/', $rawCid) !== 1) {
            $fail('The :attribute field contains invalid characters.');

            return;
        }

        $cid = preg_replace('/\D+/', '', $rawCid) ?? '';

        if (strlen($cid) !== 13) {
            $fail('The :attribute field must be a valid 13-digit Thai citizen ID.');

            return;
        }

        $sum = 0;

        for ($index = 0; $index < 12; $index++) {
            $sum += ((int) $cid[$index]) * (13 - $index);
        }

        $checkDigit = (11 - ($sum % 11)) % 10;

        if ($checkDigit !== (int) $cid[12]) {
            $fail('The :attribute field must be a valid Thai citizen ID.');
        }
    }
}

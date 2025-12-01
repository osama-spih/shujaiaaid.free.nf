<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidRelation implements ValidationRule
{
    protected $allowedValues = [
        'زوجة',
        'زوج',
        'ابن',
        'ابنة',
        'أخرى',
    ];

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return; // nullable field
        }

        if (!is_string($value)) {
            $fail('صلة القرابة يجب أن تكون نصاً.');
            return;
        }

        if (!in_array($value, $this->allowedValues, true)) {
            $fail('صلة القرابة المحددة غير صحيحة.');
            return;
        }
    }
}



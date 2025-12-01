<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidMaritalStatus implements ValidationRule
{
    protected $allowedValues = [
        'أعزب',
        'متزوج',
        'منفصل',
        'مطلقة',
        'أرمل',
        'متعدد الزوجات',
        'مفقود',
        'شهيد',
        'متوفى',
        'أسير',
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
            $fail('الحالة الاجتماعية يجب أن تكون نصاً.');
            return;
        }

        if (!in_array($value, $this->allowedValues, true)) {
            $fail('الحالة الاجتماعية المحددة غير صحيحة.');
            return;
        }
    }
}



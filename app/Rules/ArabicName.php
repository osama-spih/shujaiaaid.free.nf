<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ArabicName implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return; // nullable field
        }

        if (!is_string($value)) {
            $fail('الاسم يجب أن يكون نصاً.');
            return;
        }

        // التحقق من الطول
        $length = mb_strlen(trim($value), 'UTF-8');
        if ($length < 2) {
            $fail('الاسم يجب أن يكون على الأقل حرفين.');
            return;
        }

        if ($length > 120) {
            $fail('الاسم يجب أن لا يزيد عن 120 حرفاً.');
            return;
        }

        // التحقق من الأحرف العربية والأرقام والمسافات فقط
        // السماح بالأحرف العربية، الأرقام، المسافات، والنقاط
        if (!preg_match('/^[\p{Arabic}\s0-9\.\-]+$/u', trim($value))) {
            $fail('الاسم يجب أن يحتوي على أحرف عربية فقط.');
            return;
        }

        // منع المسافات المتعددة
        if (preg_match('/\s{2,}/', $value)) {
            $fail('الاسم يحتوي على مسافات متعددة.');
            return;
        }

        // منع الأسماء التي تحتوي على أرقام فقط
        if (preg_match('/^[0-9\s\.\-]+$/', trim($value))) {
            $fail('الاسم لا يمكن أن يكون أرقاماً فقط.');
            return;
        }
    }
}



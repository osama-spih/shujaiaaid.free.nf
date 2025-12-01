<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneNumber implements ValidationRule
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
            $fail('رقم الجوال يجب أن يكون نصاً.');
            return;
        }

        // إزالة المسافات والأحرف الخاصة
        $cleaned = preg_replace('/[^0-9+]/', '', $value);

        // التحقق من الطول (عادة أرقام فلسطينية تبدأ بـ 059 أو +970)
        if (strlen($cleaned) < 9 || strlen($cleaned) > 15) {
            $fail('رقم الجوال غير صحيح. يجب أن يكون بين 9 و 15 رقماً.');
            return;
        }

        // التحقق من أن يبدأ برمز صحيح
        if (!preg_match('/^(\+?970|0)?5[0-9]{8,9}$/', $cleaned)) {
            // السماح بأرقام دولية أخرى أيضاً
            if (!preg_match('/^\+?[1-9]\d{8,14}$/', $cleaned)) {
                $fail('رقم الجوال غير صحيح.');
                return;
            }
        }

        // منع الأرقام المتكررة
        if (preg_match('/^(\d)\1{6,}$/', $cleaned)) {
            $fail('رقم الجوال غير صحيح.');
            return;
        }
    }
}



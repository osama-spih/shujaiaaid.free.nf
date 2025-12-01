<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NationalId implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('رقم الهوية يجب أن يكون نصاً.');
            return;
        }

        // التحقق من وجود أحرف غير رقمية (قبل التنظيف)
        if (preg_match('/[^0-9\s\-]/', $value)) {
            $fail('رقم الهوية يجب أن يحتوي على أرقام فقط. لا يُسمح بالأحرف.');
            return;
        }

        // إزالة أي مسافات أو شرطات
        $cleaned = preg_replace('/[\s\-]/', '', $value);

        // التحقق من أن كل الأحرف أرقام
        if (!ctype_digit($cleaned)) {
            $fail('رقم الهوية يجب أن يحتوي على أرقام فقط.');
            return;
        }

        $length = strlen($cleaned);

        // التحقق من الطول (6-20 رقم للدعم العام، لكن 9 أرقام للهوية الفلسطينية)
        if ($length < 6 || $length > 20) {
            $fail('رقم الهوية يجب أن يكون بين 6 و 20 رقماً.');
            return;
        }

        // منع الأرقام المتكررة (مثل 111111 أو 000000)
        if (preg_match('/^(\d)\1{5,}$/', $cleaned)) {
            $fail('رقم الهوية غير صحيح.');
            return;
        }

        // التحقق من أن رقم الهوية الفلسطينية 9 أرقام بالضبط
        // (لا نتحقق من صحة رقم التحقق - فقط الطول والشكل)
        if ($length === 9) {
            // رقم الهوية الفلسطينية يجب أن يكون 9 أرقام - تم التحقق من ذلك بالفعل
            // لا حاجة لتحقق إضافي
        }
    }
}



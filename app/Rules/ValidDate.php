<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidDate implements ValidationRule
{
    protected $maxYearsAgo;
    protected $minYearsAgo;

    public function __construct($maxYearsAgo = 120, $minYearsAgo = 0)
    {
        $this->maxYearsAgo = $maxYearsAgo;
        $this->minYearsAgo = $minYearsAgo;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return; // nullable field
        }

        if (!is_string($value)) {
            $fail('التاريخ يجب أن يكون نصاً.');
            return;
        }

        // التحقق من صيغة التاريخ
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            $fail('صيغة التاريخ غير صحيحة. يجب أن تكون YYYY-MM-DD.');
            return;
        }

        // التحقق من أن التاريخ ليس في المستقبل
        $today = new \DateTime();
        if ($date > $today) {
            $fail('التاريخ لا يمكن أن يكون في المستقبل.');
            return;
        }

        // التحقق من الحد الأقصى للعمر
        $maxDate = (clone $today)->modify("-{$this->maxYearsAgo} years");
        if ($date < $maxDate) {
            $fail("التاريخ يجب أن يكون بعد " . $this->maxYearsAgo . " سنة من اليوم.");
            return;
        }

        // التحقق من الحد الأدنى للعمر (للتاريخ المستقبلي)
        if ($this->minYearsAgo > 0) {
            $minDate = (clone $today)->modify("-{$this->minYearsAgo} years");
            if ($date > $minDate) {
                $fail("التاريخ يجب أن يكون قبل " . $this->minYearsAgo . " سنة من اليوم.");
                return;
            }
        }
    }
}



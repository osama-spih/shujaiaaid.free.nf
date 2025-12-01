<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MaxArraySize implements ValidationRule
{
    protected $max;

    public function __construct($max = 30)
    {
        $this->max = $max;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return; // nullable field
        }

        if (!is_array($value)) {
            $fail('القيمة يجب أن تكون مصفوفة.');
            return;
        }

        if (count($value) > $this->max) {
            $fail("عدد العناصر يجب أن لا يزيد عن {$this->max}.");
            return;
        }
    }
}



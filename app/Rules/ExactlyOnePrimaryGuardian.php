<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ExactlyOnePrimaryGuardian implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            $fail('At least one guardian must be provided.');
            return;
        }

        $primaryCount = 0;
        foreach ($value as $entry) {
            if (is_array($entry) && filter_var($entry['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $primaryCount++;
            }
        }

        if ($primaryCount === 0) {
            $fail('Exactly one guardian must be marked as primary.');
        } elseif ($primaryCount > 1) {
            $fail('Only one guardian can be marked as primary.');
        }
    }
}

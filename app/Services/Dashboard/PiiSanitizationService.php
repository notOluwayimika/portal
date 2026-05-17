<?php

namespace App\Services\Dashboard;

use App\Exceptions\Dashboard\PiiDetectedException;

class PiiSanitizationService
{
    private const EMAIL_PATTERN = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';

    // Matches phone-like strings: 7+ consecutive digits, optionally separated by spaces/dashes/+
    private const PHONE_PATTERN = '/(?<!\d)(\+?[\d][\d\s\-]{6,}[\d])(?!\d)/';

    // Field names that must never contain real human names
    private const NAME_FIELDS = [
        'name', 'first_name', 'last_name', 'middle_name', 'full_name',
        'school_name', // school_name is allowed as it is not a person name
    ];

    // school_name is aggregate metadata, not PII — exclude it from person-name checks
    private const ALLOWED_NAME_FIELDS = ['school_name'];

    public function scan(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $fieldPath = $path ? "{$path}.{$key}" : $key;

            if (is_array($value)) {
                $this->scan($value, $fieldPath);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $this->checkEmail($value, $fieldPath);
            $this->checkPhone($value, $fieldPath);
            $this->checkNameField($key, $value, $fieldPath);
        }
    }

    private function checkEmail(string $value, string $fieldPath): void
    {
        if (preg_match(self::EMAIL_PATTERN, $value)) {
            throw new PiiDetectedException($fieldPath, "value looks like an email address");
        }
    }

    private function checkPhone(string $value, string $fieldPath): void
    {
        // Skip datetimes and ISO dates — they contain digit runs that trigger the phone regex
        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?/', $value)) {
            return;
        }

        // Only flag short strings that are *primarily* a phone-like value
        if (strlen($value) <= 20 && preg_match(self::PHONE_PATTERN, $value)) {
            throw new PiiDetectedException($fieldPath, "value looks like a phone number");
        }
    }

    private function checkNameField(string $key, string $value, string $fieldPath): void
    {
        if (in_array($key, self::ALLOWED_NAME_FIELDS, true)) {
            return;
        }

        $lowerKey = strtolower($key);
        $isNameField = in_array($lowerKey, self::NAME_FIELDS, true)
            || str_ends_with($lowerKey, '_name')
            || str_ends_with($lowerKey, '_first')
            || str_ends_with($lowerKey, '_last');

        if (!$isNameField) {
            return;
        }

        // Allow known synthetic placeholders
        if (preg_match('/^(student_\d+|record_[a-z]|teacher_\d+|guardian_\d+|user_\d+|[a-f0-9\-]{36})$/i', $value)) {
            return;
        }

        // Flag values that look like real human names: contain multiple capitalized words
        if (preg_match('/^[A-Z][a-z]+([\s,\-][A-Z][a-z]+)+$/', trim($value))) {
            throw new PiiDetectedException($fieldPath, "value in a name field looks like a real person name: '{$value}'");
        }
    }
}

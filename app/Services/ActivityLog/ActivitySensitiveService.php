<?php

namespace App\Services\ActivityLog;

use Illuminate\Support\Collection;

/**
 * Sensitive-entry detection + field masking, driven by
 * config/activity_log_sensitive.php.
 */
class ActivitySensitiveService
{
    public function __construct(
        private readonly array $entryPatterns,
        private readonly array $maskedFields,
    ) {}

    public static function make(): self
    {
        return new self(
            config('activity_log_sensitive.entries', []),
            config('activity_log_sensitive.fields', []),
        );
    }

    public function isSensitiveEntry(?string $logName, ?string $event): bool
    {
        $key = ActivityPatternMatcher::key($logName, $event);

        foreach ($this->entryPatterns as $pattern) {
            if (ActivityPatternMatcher::matches($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /** Field names (lower-cased) that must be masked in the diff/detail view. */
    public function maskedFields(): array
    {
        return array_map('strtolower', $this->maskedFields);
    }

    public function isMaskedField(string $field): bool
    {
        return in_array(strtolower($field), $this->maskedFields(), true);
    }

    /**
     * Recursively mask sensitive keys in a properties array.
     *
     * USED AT BOTH ENDS, deliberately one implementation. It is applied at WRITE
     * time by the `Activity::saving` hook in AppServiceProvider — so a sensitive
     * value never reaches the table — and it remains the read-time safety net for
     * rows written before that hook existed and for anything a future logging path
     * inserts directly.
     *
     * Until 2026-08-02 the write-time half of that was only a comment here: this
     * docblock claimed values "should already be stripped at write time by the
     * logging code", and nothing did it. `User::getActivitylogOptions()` logs
     * `password`, so every signup and every password change wrote a live bcrypt
     * hash into `activity_log.properties` — masked on screen, plaintext in the
     * column, and therefore in every dump and backup.
     */
    public function maskProperties(mixed $properties): ?array
    {
        if ($properties === null) {
            return null;
        }

        // Spatie casts the `properties` column to a Collection.
        if ($properties instanceof Collection) {
            $properties = $properties->toArray();
        }

        if (! is_array($properties)) {
            return null;
        }

        $masked = [];
        foreach ($properties as $key => $value) {
            if (is_string($key) && $this->isMaskedField($key)) {
                $masked[$key] = '***';
            } elseif (is_array($value)) {
                $masked[$key] = $this->maskProperties($value);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }
}

<?php

namespace App\Services\ActivityLog;

/**
 * Shared "{log_name}.{event}" wildcard matching used by both severity
 * derivation and sensitive-entry detection.
 *
 * Patterns support `*` wildcards on either side of the dot, e.g.
 * `*.deleted`, `*.bulk_*`, `permissions.*`. "Most specific match wins":
 * an exact pattern outranks a wildcard one (longer literal = more specific).
 */
class ActivityPatternMatcher
{
    public static function key(?string $logName, ?string $event): string
    {
        return ($logName ?: 'default') . '.' . ($event ?: 'unknown');
    }

    public static function matches(string $pattern, string $key): bool
    {
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

        return (bool) preg_match($regex, $key);
    }

    /**
     * Specificity score: exact patterns (no wildcard) score highest, then
     * by the length of the literal (non-`*`) characters.
     */
    public static function specificity(string $pattern): int
    {
        if (! str_contains($pattern, '*')) {
            return PHP_INT_MAX;
        }

        return strlen(str_replace('*', '', $pattern));
    }
}

<?php

namespace App\Services\ActivityLog;

/**
 * Derives severity (critical|warning|notice|info) at read time from the
 * config/activity_log_severity.php map. Never stored.
 */
class ActivitySeverityService
{
    /** Tier priority used to break specificity ties. */
    private const TIERS = ['critical', 'warning', 'notice'];

    public function __construct(private readonly array $map)
    {
        //
    }

    public static function make(): self
    {
        return new self(config('activity_log_severity', []));
    }

    public function for(?string $logName, ?string $event): string
    {
        $key = ActivityPatternMatcher::key($logName, $event);

        $best = null;          // ['tier' => string, 'score' => int]

        foreach (self::TIERS as $tierIndex => $tier) {
            foreach ((array) ($this->map[$tier] ?? []) as $pattern) {
                if (! ActivityPatternMatcher::matches($pattern, $key)) {
                    continue;
                }

                $score = ActivityPatternMatcher::specificity($pattern);

                // Higher specificity wins; on a tie the more severe tier
                // (earlier in self::TIERS) wins.
                if ($best === null
                    || $score > $best['score']
                    || ($score === $best['score'] && $tierIndex < $best['tierIndex'])
                ) {
                    $best = ['tier' => $tier, 'score' => $score, 'tierIndex' => $tierIndex];
                }
            }
        }

        return $best['tier'] ?? 'info';
    }
}

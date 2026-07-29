<?php

namespace App\Support;

use App\Models\MarkingComponent;

/**
 * The ONE place the score unit is converted.
 *
 * `scores.score` stores a WEIGHTED value: the percentage the teacher typed, multiplied by the
 * marking component's weight. A 100 on a 10%-weighted component is stored as 10.0.
 *
 * That conversion used to exist only in score-entry-page.tsx — a percentage was turned into a
 * weighted value on the way out of the browser and back again on the way in, with the server
 * storing whatever arrived. The meaning of a stored score therefore depended on which JS bundle
 * the teacher's browser was running: a cached bundle applying one half of the pair wrote or read a
 * number an order of magnitude out, which is the reported "I typed 100 and it shows 10.0".
 *
 * Keeping both directions in one class is the fix, not a tidy-up: as long as the two halves can
 * drift apart, the bug can come back.
 *
 * Storage is `decimal(4,1)`, so a percentage survives the round trip exactly when
 * `percent × weight` lands on a 0.1 boundary. Every whole-number percentage is exact for the
 * weights in use (0.100 / 0.500 / 0.700), which is why the entry input is restricted to whole
 * numbers.
 */
final class ScoreUnit
{
    /** Percentage (0–100) as the teacher typed it → the weighted value the column stores. */
    public static function toWeighted(float $percent, MarkingComponent $component): float
    {
        $weight = self::weight($component);

        if ($weight <= 0.0) {
            // Not defensive padding: a zero-weight component contributes nothing to the total, so
            // there is no weighted value that could represent this score, and dividing it back out
            // would be a division by zero. Refuse rather than store a number that cannot be read.
            throw new \DomainException('Marking component has no weight, so it cannot carry a score.');
        }

        return round($percent * $weight, 1);
    }

    /**
     * The weighted value the column stores → the percentage to show the teacher.
     *
     * Null when the component has no usable weight, so callers render an empty cell instead of
     * INF/NAN.
     */
    public static function toPercent(float|string|null $weighted, MarkingComponent $component): ?float
    {
        $weight = self::weight($component);

        if ($weighted === null || $weight <= 0.0) {
            return null;
        }

        return round((float) $weighted / $weight, 1);
    }

    /** `weight` is cast `decimal:3`, so it arrives as a string. */
    private static function weight(MarkingComponent $component): float
    {
        return (float) $component->weight;
    }
}

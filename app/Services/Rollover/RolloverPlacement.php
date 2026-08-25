<?php

namespace App\Services\Rollover;

use Illuminate\Support\Collection;

/**
 * Where every pupil in an end-of-year rollover would land — the answer to the question the count
 * never answered.
 *
 * Before this, a registrar confirmed on "12 classes, 340 pupils would move". Arm placement is the
 * least obvious part of the operation (an explicit map, then a stream-aware label match, then
 * `student_id % armCount` over the target level's arms by id) and it was invisible until after the
 * batch drained.
 *
 * ── EXACT, NOT INDICATIVE, AND ONLY BECAUSE PLACEMENT IS A PURE FUNCTION ─────────────────────────
 * `student_id % armCount` needs no counter and no coordination, so the same pupil resolves to the
 * same arm however many times it is asked and whichever process asks. A per-job round-robin or a
 * shared counter would make a preview a guess. This is computed by the SAME
 * {@see NextYearPlacementResolver} the job uses, in read-only mode — not a second implementation of
 * the rules, which is the drift that made the last-term defect invisible.
 *
 * ── THREE BUCKETS, BECAUSE THEY MEAN THREE DIFFERENT THINGS TO AN OPERATOR ───────────────────────
 * Advancers move up a level. Repeaters are HELD in their own level (same arm, next session) and
 * carry no promotion link. The unplaceable are neither, and each carries the reason it could not be
 * placed — "the target level is explicit_only and nothing matched" is a configuration hole someone
 * must fix, while a terminal level advancing nobody is simply what a graduating year does.
 */
final class RolloverPlacement
{
    /**
     * @param  Collection<int, PlacementGroup>  $advancers
     * @param  Collection<int, PlacementGroup>  $repeaters
     * @param  Collection<int, array{source: string, reason: string, explanation: string, pupils: list<array{id: int, name: string, admission_number: string|null}>}>  $unplaceable
     */
    /**
     * @param  Collection<int, array{source: string, pupils: list<array{id: int, name: string, admission_number: string|null}>}>  $graduating
     */
    public function __construct(
        public readonly Collection $advancers,
        public readonly Collection $repeaters,
        public readonly Collection $unplaceable,
        /**
         * TERMINAL-LEVEL PUPILS — the leaving cohort. A fourth bucket, not an omission.
         *
         * They are inside `pupil_count` (planEndOfYear selects each level's final slot and does not
         * filter terminal levels — "no next slot" is the SELECTION CRITERION there, not a failure),
         * their curriculum is dispatched a job, and the job advances none of them. Left out of every
         * bucket, they became an unexplained gap: the confirm says "340 pupils across 12 classes"
         * directly above a table totalling fewer, and for any school with a leaving year the
         * difference IS that whole cohort.
         *
         * Every number was individually correct and the screen still did not add up — which is the
         * count-honesty lesson this milestone already paid for once, in the other direction.
         */
        public readonly Collection $graduating = new Collection,
    ) {}

    public static function empty(): self
    {
        return new self(collect(), collect(), collect(), collect());
    }

    /**
     * Every pupil this placement accounts for. The panel reconciles this against `pupil_count`, so a
     * pupil who falls out of every bucket is VISIBLE as a remainder rather than silently absent.
     */
    public function accountedPupils(): int
    {
        $inGroups = fn (Collection $groups) => $groups->sum(fn (PlacementGroup $g) => $g->pupilCount());

        return $inGroups($this->advancers)
            + $inGroups($this->repeaters)
            + $this->unplaceable->sum(fn (array $u) => count($u['pupils']))
            + $this->graduating->sum(fn (array $g) => count($g['pupils']));
    }

    /**
     * Every group whose destination does not exist yet — advancers AND repeaters, because both reach
     * their destination through the same create path and both land subject-less when it is missing.
     *
     * @return Collection<int, PlacementGroup>
     */
    public function unconfiguredGroups(): Collection
    {
        return $this->advancers
            ->concat($this->repeaters)
            ->filter(fn (PlacementGroup $g) => $g->destinationIsUnconfigured())
            ->values();
    }

    /**
     * THE ACKNOWLEDGMENT SET the commit checks — destination identities, not a count.
     *
     * A count masks a swap: configure one destination and delete another between preview and confirm
     * and the number is unchanged, so an equality check passes while a destination the operator never
     * saw takes pupils subject-less. What an operator acknowledges is not "there were N" but "I
     * accept THESE destinations being empty", so the set is what has to cross the wire.
     *
     * Distinct and sorted, so the value is stable for a given plan regardless of iteration order —
     * an acknowledgment that depended on ordering would refuse at random.
     *
     * @return list<string>
     */
    public function unconfiguredKeys(): array
    {
        return $this->unconfiguredGroups()
            ->map(fn (PlacementGroup $g) => $g->destinationKey)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * How many DESTINATIONS are unconfigured — distinct destinations, not groups and not pupils.
     *
     * The operator-facing sentence is "3 destinations have no curriculum set up", and two source
     * classes feeding one empty destination is one thing to fix, not two.
     */
    public function unconfiguredCount(): int
    {
        return count($this->unconfiguredKeys());
    }

    public function isEmpty(): bool
    {
        return $this->advancers->isEmpty()
            && $this->repeaters->isEmpty()
            && $this->unplaceable->isEmpty();
    }
}

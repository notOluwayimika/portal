<?php

namespace App\Services\Rollover;

/**
 * One (source class → destination) pair, with the pupils that would take it.
 *
 * ── GROUPED, WITH PUPILS NESTED — NOT A FLAT LIST ────────────────────────────────────────────────
 * A year group is hundreds of pupils and a flat list of them is not a preview anyone reads. The
 * question a registrar actually asks is "which class goes where, and is anybody stranded" — counts
 * answer that, and the nested names answer the follow-up when one row looks wrong.
 *
 * ── THE SUBJECT-READINESS SIGNAL IS `destinationIsUnconfigured()`, AND IT IS NOT ABOUT EXISTENCE ─
 * It reports that the destination has no ACTIVE COMPULSORY curriculum subjects — which is what
 * MoveToNextYearJob::createEpisode reads when it decides what to attach, and therefore what decides
 * whether a pupil lands able to study. Nothing re-attaches subjects afterwards: every caller of
 * autoAttachCompulsorySubjects fires at enrollment-creation time and they have all run by the time
 * anyone notices. So this flag is the difference between a registrar setting subjects up first and a
 * cohort landing permanently subject-less.
 *
 * `destinationCurriculumId === null` is kept as INFORMATION — it distinguishes "would be created"
 * from "exists but is empty" when the screen explains itself — but it is NOT the predicate. It was
 * once, and that was wrong in the direction that mattered: see
 * {@see NextYearPlacement::destinationIsUnconfigured()} for the re-run where an existence check
 * guards the run with nothing at risk and passes the run where everything is.
 *
 * ── IT IS ON REPEATER GROUPS TOO, AND THAT IS NOT SYMMETRY FOR ITS OWN SAKE ──────────────────────
 * MoveToNextYearJob::holdRepeater reaches its destination through the SAME
 * resolveTargetCurriculum → firstOrCreate path an advancer does. A repeater re-enrolled into a
 * same-level curriculum with no subjects lands unable to study identically. Carrying the marker only
 * on advancer groups would show a registrar flagged advancer destinations and silently unflagged
 * repeater ones — an under-warn for precisely the pupils being held back, who are the least likely
 * to be looked at twice.
 */
final class PlacementGroup
{
    /**
     * @param  string  $destinationKey  stable identity of the destination, derived from the five
     *                                  resolved curriculum keys rather than from an id — because the
     *                                  destinations that matter here are the ones with no id yet.
     *                                  This is what the commit's acknowledgment set compares.
     * @param  list<array{id: int, name: string, admission_number: string|null}>  $pupils
     */
    public function __construct(
        public readonly string $sourceLabel,
        public readonly string $destinationLabel,
        public readonly ?int $destinationCurriculumId,
        public readonly string $destinationKey,
        public readonly array $pupils,
        /**
         * Whether the destination has ACTIVE COMPULSORY curriculum subjects — carried from
         * {@see NextYearPlacement} rather than re-derived, so the screen and the commit's gate cannot
         * disagree about which destinations are unsafe.
         *
         * Named for the POSITIVE fact and mirroring the resolver's field, not
         * `$destinationIsUnconfigured`: a property sharing a name with the method below is legal PHP
         * and reads as a typo forever after — the same collision family as a `session()` helper on a
         * FormRequest, one scope down.
         */
        public readonly bool $destinationHasCompulsorySubjects = false,
        /**
         * Whether end-of-year will SEED this destination from the same level's prior-session
         * curriculum at commit. Carried from {@see NextYearPlacement} for the same reason as the
         * field above — it is resolved through the very lookup the job seeds on, and re-deriving it
         * here would be the second implementation that makes the screen able to lie.
         */
        public readonly bool $destinationWillInheritSubjects = false,
    ) {}

    public function pupilCount(): int
    {
        return count($this->pupils);
    }

    /**
     * NOT `destinationCurriculumId === null`. That measured whether the destination ROW existed, and
     * a destination created empty by an earlier run exists while still teaching nothing — see
     * {@see NextYearPlacement::destinationIsUnconfigured()} for the re-run this got wrong.
     *
     * `destinationCurriculumId` is kept because the screen still wants to distinguish "would be
     * created" from "exists but is empty" when explaining itself; it is no longer the predicate.
     */
    public function destinationIsUnconfigured(): bool
    {
        return ! $this->destinationHasCompulsorySubjects;
    }

    /**
     * Unconfigured AND not going to be seeded — the destination genuinely lands empty. This is what
     * the red warning, the confirm line and the acknowledgment set key on; see
     * {@see NextYearPlacement::destinationWillLandEmpty()} for why it is a conjunction rather than
     * the absence of a prior-session curriculum.
     */
    public function destinationWillLandEmpty(): bool
    {
        return $this->destinationIsUnconfigured() && ! $this->destinationWillInheritSubjects;
    }

    /** Unconfigured today, but the commit will populate it. Informational, not a hazard. */
    public function destinationWillInherit(): bool
    {
        return $this->destinationIsUnconfigured() && $this->destinationWillInheritSubjects;
    }
}

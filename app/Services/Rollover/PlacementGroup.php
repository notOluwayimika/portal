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
 * ── `destinationCurriculumId === null` IS THE SUBJECT-READINESS SIGNAL ───────────────────────────
 * Not an error. It means the destination does not exist yet and the rollover would create it — and
 * a curriculum created by the rollover has NO subjects, because MoveToNextYearJob::createEpisode
 * attaches only the compulsory ones and reads them from the target. Nothing re-attaches them
 * afterwards: every caller of autoAttachCompulsorySubjects fires at enrollment-creation time, and
 * they have all run by the time anyone notices. So this flag is the difference between a registrar
 * setting subjects up first and a cohort landing permanently subject-less.
 *
 * ── IT IS ON REPEATER GROUPS TOO, AND THAT IS NOT SYMMETRY FOR ITS OWN SAKE ──────────────────────
 * MoveToNextYearJob::holdRepeater reaches its destination through the SAME
 * resolveTargetCurriculum → firstOrCreate path an advancer does. A repeater re-enrolled into a
 * same-level curriculum that does not exist in the target session lands subject-less identically.
 * Carrying the marker only on advancer groups would show a registrar flagged advancer destinations
 * and silently unflagged repeater ones — an under-warn for precisely the pupils being held back,
 * who are the least likely to be looked at twice.
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
    ) {}

    public function pupilCount(): int
    {
        return count($this->pupils);
    }

    public function destinationIsUnconfigured(): bool
    {
        return $this->destinationCurriculumId === null;
    }
}

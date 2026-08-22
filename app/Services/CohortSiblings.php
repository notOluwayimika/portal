<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\StudentCurriculum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The classes a placed pupil may be reassigned INTO: the same cohort, a different arm.
 *
 * ── ONE DEFINITION, TWO CALLERS, AND THAT IS THE WHOLE POINT ──────────────────────────────────────
 * This query is consulted twice per reassignment — once by StudentReassignmentController::show to
 * OFFER the destinations, and once by ReassignStudentRequest to ACCEPT one. If those two ever
 * disagree, the screen offers a class the guard then refuses (an operator staring at a 422 on the
 * only option they were given), or worse, accepts one it never offered. Both read this method, so
 * they cannot drift apart; the same reasoning that put the graph walk behind ProgressionGraph rather
 * than duplicating it between the config screen and the rollover gate.
 *
 * ── WHAT "SAME COHORT" MEANS, AND WHY IT IS EXACTLY THIS SET ──────────────────────────────────────
 * The migration jobs only ever produce placements that share (class level, term, exam type, is_ccm)
 * and differ in arm. So this set is precisely "a placement a job could have produced instead" — which
 * makes reassignment correct-by-construction: it can rearrange what the jobs did, and it cannot
 * invent a placement no job would ever create.
 *
 * SESSION IS NOT A SEPARATE AXIS: a term belongs to exactly one academic session, so an equal
 * `term_id` already pins the session. Adding a session join would be a second, redundant statement of
 * the same constraint — and a place for the two to disagree later.
 *
 * CROSS-EXAM-TYPE IS DEFERRED, NOT OVERLOOKED. Moving a pupil between exam tracks by hand is a
 * different operation with different billing and marking-scheme consequences, and it is deliberately
 * not buildable from this screen. Widening it means relaxing the `exam_type_id` match here AND
 * revisiting what the sibling rule protects — a decision, not a one-line change.
 *
 * ── THE NULLABLE AXES ARE SAFE, AND NOT FOR THE REASON IT FIRST LOOKS ─────────────────────────────
 * `term_id` and `exam_type_id` are both nullable, and `WHERE term_id = NULL` is never true in SQL —
 * so a term-less curriculum matching none of its term-less siblings is a real failure mode, and it
 * fails SILENTLY in the safe direction (the operator is told the pupil has nowhere to go).
 *
 * Eloquent closes it: Query\Builder::where() converts a null VALUE into a `whereNull` before it ever
 * builds an `=` comparison. So the plain `where()` calls below are correct, and an explicit
 * null-branching helper here would be dead weight — it was written, and a mutation check proved no
 * test could tell it from its absence, because the two are the same query.
 *
 * Stated rather than deleted silently, because the danger returns the moment any of this becomes
 * `whereRaw` or a hand-written join, where nothing does the conversion. That is what the term-less
 * test pins: the BEHAVIOUR, not this particular spelling of it.
 */
class CohortSiblings
{
    /**
     * @return Collection<int, Curriculum> the sibling classes, arms eager-loaded for labelling
     */
    public static function for(StudentCurriculum $episode): Collection
    {
        $current = $episode->curriculum;

        if ($current === null) {
            return collect();
        }

        $classLevelId = $current->classLevelArm?->class_level_id;

        // A curriculum with no arm has no class level, so it has no cohort to be a sibling of.
        // Returning nothing is the honest answer; guessing a level would invent the very placement
        // this class exists to make impossible.
        if ($classLevelId === null) {
            return collect();
        }

        return Curriculum::query()
            // ── DEFENCE IN DEPTH, AND NO TEST CAN DISTINGUISH IT ──────────────────────────────────
            // Curriculum carries the SchoolScope global scope, which filters to the Active School on
            // every request. This restates the boundary against the EPISODE's school so the set is
            // still correct off-request (a job, a command, a console context with no ambient
            // school), where the scope has nothing to filter by.
            //
            // BE HONEST ABOUT ITS REACH: on the request path this line is unreachable as a guard.
            // The class-level match below already excludes every foreign-school curriculum, because
            // each school has its OWN class_levels row — so a foreign curriculum is necessarily in a
            // foreign class level. Removing this line, the global scope, or BOTH leaves the suite
            // green, which a mutation check confirmed rather than assumed. It is kept for the
            // off-request case and as the clause that would still hold if the cohort axes were ever
            // relaxed; it is NOT what refuses a cross-school reassignment today. That refusal is the
            // uuid resolution in ReassignStudentRequest, which IS isolatable and IS tested.
            ->where('school_id', $episode->school_id)
            // Only classes that are still running. `curricula.status` goes 'active' -> 'closed' when
            // the term is done, and every closed curriculum in the data pairs with a `promoted`
            // episode — so offering one would place a pupil into a finished class.
            ->where('status', 'active')
            ->where('is_ccm', (bool) $current->is_ccm)
            ->whereHas('classLevelArm', fn (Builder $query) => $query->where('class_level_id', $classLevelId))
            // DIFFERENT ARM, expressed as "not this curriculum's arm" rather than "not this
            // curriculum" — the two differ if a level ever carries two curricula for one arm, and
            // the arm is the axis the operator is actually changing.
            ->where('class_level_arm_id', '!=', $current->class_level_arm_id)
            // Both nullable; where() converts a null value to IS NULL — see the class docblock.
            ->where('term_id', $current->term_id)
            ->where('exam_type_id', $current->exam_type_id)
            ->with(['classLevelArm.arm', 'classLevelArm.classLevel', 'classLevelArm.stream'])
            ->orderBy('id')
            ->get();
    }
}

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
 * A curriculum is a valid destination iff it shares (class level, term, is_ccm) with the pupil's
 * current placement and IS NOT that placement. Arm and exam type are both free.
 *
 * SESSION IS NOT A SEPARATE AXIS: a term belongs to exactly one academic session, so an equal
 * `term_id` already pins the session. Adding a session join would be a second, redundant statement of
 * the same constraint — and a place for the two to disagree later.
 *
 * ── EXAM TYPE WAS IN THIS KEY AND HAS BEEN REMOVED, DELIBERATELY ──────────────────────────────────
 * It used to match, and the docblock here used to argue that cross-exam-type moves were "a different
 * operation" deferred pending a decision. THAT DECISION HAS BEEN TAKEN AND WENT THE OTHER WAY, so
 * the argument is recorded as superseded rather than quietly deleted.
 *
 * The reason it went the other way: under curriculum-as-truth, exam type is just one axis of the
 * destination curriculum, and the placement being corrected is PROVISIONAL. MoveToNextYearJob picks
 * an exam type by "carry the source's if the target level runs it, else the level default" — a
 * reasonable guess made without a human, and routinely the wrong one. Year 10 B/WAEC → Year 10 S/BSS
 * is the ordinary correction, not an exotic one. A rule that forbade it left the operator with a
 * misplaced pupil and no way to fix it from the screen built to fix misplacements.
 *
 * WHAT THIS COST, STATED PLAINLY: the set is no longer "a placement a job could have produced
 * instead" — it is wider than that, because a job would not have crossed exam tracks. Reassignment is
 * therefore no longer correct-by-construction against the jobs, and the guarantee now rests on the
 * three axes that remain. `is_ccm` in particular is doing more work than before: it is the only thing
 * standing between this screen and a CCM crossing, which belongs to MoveFromCcmJob and not to a
 * manual move.
 *
 * TWO CLAUSES CHANGED, NOT ONE. Dropping the `exam_type_id` match alone would have been a half-fix:
 * the exclusion below used to be "a DIFFERENT ARM" (`class_level_arm_id != …`), which by itself still
 * rejects a same-arm/different-exam-type destination — precisely the Year 10 B/WAEC → Year 10 B/BSS
 * case. The exclusion is now "not this curriculum", which is what "arm and exam type free" actually
 * requires.
 *
 * ── THE NULLABLE AXES ARE SAFE, AND NOT FOR THE REASON IT FIRST LOOKS ─────────────────────────────
 * `term_id` is nullable, and `WHERE term_id = NULL` is never true in SQL — so a term-less curriculum
 * matching none of its term-less siblings is a real failure mode, and it fails SILENTLY in the safe
 * direction (the operator is told the pupil has nowhere to go). `exam_type_id` is also nullable but
 * no longer matched, so it cannot contribute to this any more.
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
            // THE AXIS DOING THE MOST WORK NOW. With exam type out of the key, this is the only
            // clause standing between a manual move and a CCM crossing, which belongs to
            // MoveFromCcmJob. Do not relax it without revisiting that job.
            ->where('is_ccm', (bool) $current->is_ccm)
            ->whereHas('classLevelArm', fn (Builder $query) => $query->where('class_level_id', $classLevelId))
            // NOT THIS CURRICULUM — not "not this arm". A level can carry two curricula for one arm
            // (same arm, different exam type), and excluding by arm would reject exactly the
            // correction this screen exists for. See the docblock: this is the second of the two
            // clauses that changed, and dropping the exam-type match without this one is a half-fix
            // that looks complete.
            ->where('id', '!=', $current->id)
            // Nullable; where() converts a null value to IS NULL — see the class docblock.
            ->where('term_id', $current->term_id)
            ->with(['classLevelArm.arm', 'classLevelArm.classLevel', 'classLevelArm.stream'])
            ->orderBy('id')
            ->get();
    }
}

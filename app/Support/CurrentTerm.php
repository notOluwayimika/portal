<?php

namespace App\Support;

use App\Http\Controllers\SetupController;
use App\Models\School;
use App\Models\Term;

/**
 * WHICH TERM A SCHOOL IS IN RIGHT NOW — one definition, in the shared kernel, for every surface that
 * needs to default a term rather than ask for one.
 *
 * IT IS AN EXTRACTION, NOT A NEW RULE. The expression below stood inside
 * {@see SetupController::index()} and was the only one in the application; U6's bulk-run screen is
 * the second consumer, and a second copy of "which term is current" is exactly the drift this repo
 * has already paid for twice on other predicates (the two hand-maintained copies of "does this
 * episode already have an invoice", which disagreed the day one of them gained a filter). The setup
 * endpoint now reads this too, so there is one definition and not one-plus-a-comment.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE RESOLUTION, AND ITS FALLBACK, BOTH PRESERVED EXACTLY
 *
 *   1. The school's CURRENT SESSION — `academic_sessions.is_current`, via
 *      {@see School::currentSession()}.
 *   2. Inside it, the term whose `status` is `active`.
 *   3. If no term in that session is active, THE LAST ONE BY `order`.
 *
 * Step 3 is the part worth stating rather than tidying away. Between terms — the holiday, or a
 * session that has been rolled over but not yet started — no term is `active`, and a resolver that
 * returned null there would leave the screen with no default at the exact moment an operator is most
 * likely to be billing the term that just ended. The last term by `order` is the one the school was
 * most recently in, which is the better guess and is the guess the setup endpoint has always made.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * "TERM" MEANS `terms.id` HERE, AND THERE IS NO SECOND MEANING LEFT TO CONFUSE IT WITH.
 *
 * `curricula` once carried an ordinal `term` column (1 | 2 | 3) beside a `terms` table keyed by id,
 * and a resolver that returned the wrong one of those two would have been a silent, plausible defect.
 * That column is GONE: `2026_05_06_085734_update_terms_and_curricula_tables.php:114` dropped
 * `curricula.term` and `curricula.academic_session_id` together and replaced them with a `term_id`
 * FK. Re-derived from the live database rather than from that migration —
 * `SHOW COLUMNS FROM curricula` returns `term_id` and no `term`, and the live `curricula_unique_key`
 * is `(school_id, class_level_arm_id, term_id, exam_type_id, is_ccm)`, which is not the five columns
 * the create migration names. Everything in the billing path — `finance_fee_schedules.term_id`,
 * `BillableEnrollment::$termId`, `FeeScheduleLookup::activeFor()` and this class — therefore speaks
 * the same `terms.id`.
 *
 * IT IS A DEFAULT AND NEVER A CONSTRAINT. Billing a PAST term is a real act — a child who enrols
 * late is billed for the term they enrolled in — so every consumer must offer this as a pre-filled,
 * overridable value. A surface that pinned it would make the late-enrolment case unreachable, and a
 * surface that resolved it server-side at write time would make the operator's override
 * unrepresentable on the wire.
 *
 * NOT DERIVABLE FROM A CLASS LEVEL, which is the other way this defaulting gets built wrongly. The
 * live unique key above puts `class_level_arm_id` and `term_id` in the same index, so one arm holds
 * one curriculum row PER TERM: the mapping from a class level to a term is one-to-many, and there is
 * nothing to derive.
 */
final class CurrentTerm
{
    /**
     * The school's current term, or null when it has no current session — or a current session with
     * no terms in it at all, which is a school that has not been set up rather than an error.
     */
    public static function forSchool(int $schoolId): ?Term
    {
        $school = School::find($schoolId);

        if (! $school instanceof School) {
            return null;
        }

        return self::forSchoolModel($school);
    }

    /**
     * The same resolution against a School already in hand — {@see SetupController} has one and
     * re-finding it by id would be a second query for a row it is already holding.
     */
    public static function forSchoolModel(School $school): ?Term
    {
        $session = $school->currentSession;

        if ($session === null) {
            return null;
        }

        return Term::query()
            ->where('academic_session_id', $session->id)
            ->where('status', 'active')
            ->first()
            ?? Term::query()
                ->where('academic_session_id', $session->id)
                ->orderByDesc('order')
                ->first();
    }
}

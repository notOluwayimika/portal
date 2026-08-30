<?php

namespace App\Support;

use App\Enums\TermStatusEnum;
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
 * THE RESOLUTION, AND ITS FALLBACK — WHICH READS `status`, NOT `order` ALONE
 *
 *   1. The school's CURRENT SESSION — `academic_sessions.is_current`, via
 *      {@see School::currentSession()}.
 *   2. Inside it, the term whose `status` is `active` — the HIGHEST `order` among them if more
 *      than one somehow is, which is a tie-break and not a rule (see below).
 *   3. Else the LAST `completed` term by `order` — the term the school most recently FINISHED.
 *   4. Else the FIRST term by `order` — nothing is active and nothing is completed, so the school
 *      has not started this session and the term it is about to begin is the first one.
 *   5. Else null.
 *
 * THE FALLBACK IS WHY THIS CLASS IS INTERESTING, and steps 3 and 4 answer TWO DIFFERENT SITUATIONS
 * that an earlier revision named in one sentence and then gave one answer to. Between terms — the
 * holiday — no term is `active`, and a resolver that returned null there would leave the screen with
 * no default at the exact moment an operator is most likely to be billing the term that just ended.
 * That is step 3's case. A session that has been ROLLED OVER BUT NOT YET STARTED has no term active
 * either, but the school is not between terms: it is before all of them, and the term an operator is
 * about to bill is the FIRST. That is step 4's case, and it is the opposite end of the session.
 *
 * `terms.status` is what tells them apart — an enum of THREE values, `active | upcoming | completed`,
 * defaulting to `upcoming`, declared at
 * `database/migrations/2026_05_06_082137_create_terms_table.php:22 (status)`. An earlier revision
 * tested only `active` and fell back to the last term by `order`, which cannot distinguish a term the
 * school has FINISHED from one it has not REACHED, and so answered the last term of the session in
 * both cases. Live consequence, twice over: with the 2026/2027 session opened and Term 1 starting
 * 2026-09-05, every term was `upcoming` and the bulk-run screen pre-filled Summer/Term 3 — a term
 * starting in April 2027 — so the session's first bulk run would have billed every enrolled student
 * against a term seven months away. Mid-session it failed the same way in miniature: Term 1 completed
 * with Terms 2 and 3 upcoming answered Term 3 rather than the term that had just ended.
 *
 * Step 4 does NOT filter on `upcoming`. Reaching it already means no term in the session is active or
 * completed, so every remaining row is upcoming; leaving the filter off means a session whose rows
 * somehow carry neither state still yields a default rather than null, which is the behaviour the
 * whole fallback exists to guarantee. Step 5 is then reached only by a session with NO terms.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * STEP 2 ORDERS BY `order` DESCENDING, AND THAT IS DETERMINISM — NOT CORRECTNESS.
 *
 * THIS IS NOT A CORRECTNESS FIX, and reading it as one is the mistake to avoid. TWO ACTIVE TERMS IN
 * ONE SESSION IS A STATE THAT SHOULD NOT EXIST: nothing in the schema or the application prevents
 * it — there is no constraint, and activation is a human action. Ordering the step does not make
 * that state right and does not stop it arising. The correct fix is a database constraint, at most
 * one active term per session, and it is deliberately NOT in this commit — ticketed at
 * `docs/handoff/tickets/two-active-terms-in-one-session-has-no-constraint.md`.
 *
 * WHAT IT DOES FIX is that the unordered `first()` it replaces let MySQL return WHICHEVER of the two
 * rows it liked, and nothing obliges it to return the same one for two calls in the same request.
 * This class is the single resolver behind `App\Finance\Contracts\BillableEnrollment::$termId`
 * and `FeeScheduleLookup::activeFor()`, so it decides which term the bulk run BILLS and which
 * schedule PRICES it — a resolver that can answer differently between two calls can price a run
 * against a schedule the run is not billing, and nothing downstream refuses that. Term 1 goes active
 * on 2026-09-05 by a human action; if the term before it is left active, that is the live shape.
 * Ordered, a wrong answer stays wrong — but it stays the SAME wrong answer, and it can be explained.
 *
 * DESCENDING, NOT ASCENDING, and it is the same reading of `order` step 3 already uses rather than a
 * new one. Two active terms means somebody activated the NEXT term without completing the current
 * one, so the later term is the intended one; step 3's `orderByDesc` already means "the most recent
 * by `order`". Ascending here would answer the term the school is leaving.
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
     * no terms in it at all, which is a school that has not been set up rather than an error. Null
     * for an unknown school id for the same reason: absence, not failure.
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

        // One base query, re-derived per step rather than cloned, so each step reads as the whole
        // question it asks. The steps are ordered and short-circuit: the second runs only when no
        // term is active, the third only when none is completed either. EVERY step orders — the
        // first one's `orderByDesc` is a determinism tie-break for a state that should not exist,
        // not a correctness rule; see the class docblock.
        $inSession = fn () => Term::query()->where('academic_session_id', $session->id);

        return $inSession()->where('status', TermStatusEnum::ACTIVE->value)->orderByDesc('order')->first()
            ?? $inSession()->where('status', TermStatusEnum::COMPLETED->value)->orderByDesc('order')->first()
            ?? $inSession()->orderBy('order')->first();
    }
}

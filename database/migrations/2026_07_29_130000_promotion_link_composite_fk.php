<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1 commit 5 — make a promotion link a durable, same-student same-school fact.
 *
 * WHY. `2026_07_19_130000` converted `student_id` and `curriculum_id` to composite `(x, school_id)` FKs on
 * the reasoning that "because a parent's id already determines its school_id, a child can only reference a
 * (parent_id, school_id) pair that actually exists — so a divergent episode is rejected by the engine." It
 * left `promoted_to_id` as a single-column self-FK. Nothing in the schema then required a promotion to point
 * at an episode of the SAME STUDENT, or even the same school — so a client-supplied value (removed in this
 * same commit's Part 2) that happened to exist in `student_curricula` stored a referentially-valid,
 * semantically-garbage link. The argument that justified the other two FKs applies here with one column more.
 *
 * MECHANISM. One composite FK `(promoted_to_id, student_id, school_id) -> (id, student_id, school_id)` closes
 * BOTH the cross-school link and the cross-student link, and makes the poisoning structurally impossible
 * rather than merely unreachable. Both legitimate writers already satisfy it: promote() and
 * BackfillPastTermJob create the target with the same student/school, and MoveFromCcmJob (fixed in this
 * commit's Part 5c) copies student_id and runs under a school guard. Verified by the suite, not asserted here.
 *
 * NULLABILITY is not a problem. `promoted_to_id` is nullable while `student_id`/`school_id` are NOT NULL;
 * InnoDB skips a foreign-key check when ANY of its columns is NULL, so an unpromoted row (link IS NULL) passes
 * unconditionally — exactly the existing meaning of NULL ("this is the student's current active row"). Proven
 * (Part 6, plant 5) rather than trusted.
 *
 * ON DELETE — RESTRICT, and this replaces SET NULL deliberately. The delete path that reaches this FK is
 * CURRICULUM deletion: `Curriculum` has no SoftDeletes, so `DELETE /curricula/{uuid}` is a hard DELETE and the
 * composite `(curriculum_id, school_id)` FK is ON DELETE CASCADE — deleting a curriculum hard-deletes every
 * episode in it. Judged against that scenario:
 *   • SET NULL (today): an earlier episode keeps status='promoted' while promoted_to_id goes NULL — a row that
 *     reads as both "promoted" and "current". The silent bug this commit exists to close.
 *   • CASCADE: the cascade runs BACKWARDS along the arrow (the FK's child is the EARLIER episode, its parent
 *     the later one), so deleting curriculum X also deletes enrollment history in curricula the delete never
 *     named. Worse — silent cross-curriculum history loss. (This was my first recommendation; I discarded it
 *     after checking the model instead of the sibling FKs — see below.)
 *   • RESTRICT: the cascade reaches a RESTRICT child and the whole statement fails — you cannot hard-delete a
 *     curriculum that students were promoted into. Correct, and the same soft-end-not-delete principle
 *     StudentCurriculumController:93-96 states in prose (the row is the durable referent).
 * This is the THIRD RESTRICT child on this table's delete-armour, not a new policy: student_subjects
 * (2026_05_17_000004) and finance_invoices (2026_07_19_100000) already refuse a hard delete of a populated
 * curriculum/episode. This closes the narrow remaining case — a promotion TARGET with no subjects and no
 * invoices. The July-19 RESTRICT WARNING does NOT transfer: it was about the student_id FK blocking a hard
 * delete of an UNINVOICED student, a path no code reaches because `Student` SoftDeletes and nothing calls
 * `forceDelete` (grep returns none) — a different FK and a different argument.
 *
 * INDEX HAZARD (audited via SHOW CREATE TABLE 2026-07-29, not assumed). The original self-FK was created by
 * `->constrained('student_curricula')`, so Laravel named BOTH the constraint and its backing index
 * `student_curricula_promoted_to_id_foreign`. MySQL leaves a FK's backing index behind on DROP FOREIGN KEY,
 * so the index is dropped explicitly by that name below. The new composite FK gets its own auto-named backing
 * index (`student_curricula_promoted_to_student_school_foreign`), dropped explicitly in down() for the same
 * reason the July-19 migration gives: otherwise a re-up() cannot recreate the original cleanly.
 *
 * THE CHECK (status<>'promoted' OR promoted_to_id IS NOT NULL) IS DELIBERATELY NOT ADDED. Part 0's Q3 found
 * 366 rows on staging/dev with status='promoted' and a NULL link (produced by MoveFromCcmJob before Part 5c,
 * and by UpdateStudentCurriculumStatusRequest permitting status='promoted' with no link). The CHECK would
 * reject the existing table, and this commit does not clean data to fit a constraint whose subject is
 * something else. It is a separate follow-up once the upstream sources are closed — recorded in the PR.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Parent unique key the composite child FK references. id alone is already unique, so this is
        // additive and risk-free — the same trick students_id_school_unique used in the July-19 migration.
        DB::statement('ALTER TABLE student_curricula ADD UNIQUE student_curricula_id_student_school_unique (id, student_id, school_id)');

        // Swap the single-column self-FK for the composite. The backing index shares the constraint name
        // (Laravel default, confirmed by SHOW CREATE TABLE) and must be dropped explicitly or it lingers.
        DB::statement('ALTER TABLE student_curricula DROP FOREIGN KEY student_curricula_promoted_to_id_foreign');
        DB::statement('ALTER TABLE student_curricula DROP INDEX student_curricula_promoted_to_id_foreign');
        DB::statement(
            'ALTER TABLE student_curricula
                ADD CONSTRAINT student_curricula_promoted_to_student_school_foreign
                FOREIGN KEY (promoted_to_id, student_id, school_id)
                REFERENCES student_curricula (id, student_id, school_id)
                ON DELETE RESTRICT'
        );
    }

    public function down(): void
    {
        // Mirror image, including the index-drop hazard on the way back: the composite FK's backing index
        // must go explicitly, or a re-up() cannot recreate the single-column one cleanly. Restores the
        // original SET NULL semantics.
        DB::statement('ALTER TABLE student_curricula DROP FOREIGN KEY student_curricula_promoted_to_student_school_foreign');
        DB::statement('ALTER TABLE student_curricula DROP INDEX student_curricula_promoted_to_student_school_foreign');
        DB::statement(
            'ALTER TABLE student_curricula
                ADD CONSTRAINT student_curricula_promoted_to_id_foreign
                FOREIGN KEY (promoted_to_id) REFERENCES student_curricula (id)
                ON DELETE SET NULL'
        );

        DB::statement('ALTER TABLE student_curricula DROP INDEX student_curricula_id_student_school_unique');
    }
};

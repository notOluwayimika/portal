<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slice (i) file 1 of 2 — give the enrollment episode a School of its own, and make
 * "episode.school == student.school == curriculum.school" structural.
 *
 * WHY. `student_curricula` carries no school_id and StudentCurriculum is globally
 * unscoped, so every consumer re-derives School through scoped relations. That is
 * what produced Finance slice 2's three-branch resolution (students.school_id →
 * curricula.school_id → 0) and forced cross-School isolation into application code.
 * The same invariant is hand-rolled in three places (CurriculumEnrollmentService:34,
 * StudentCurriculumController:154, :206) and MISSING in a fourth
 * (StudentService::update). An invariant asserted in N places and missed in one
 * belongs in the schema.
 *
 * MECHANISM. Two composite foreign keys. Because a parent's id already determines
 * its school_id, a child can only reference a (parent_id, school_id) pair that
 * actually exists — so a divergent episode is rejected by the engine. Same pattern
 * the Finance template uses for F3 (docs/roadmap.md), applied to the academic side.
 *
 * NOT a generated column: MySQL generated columns cannot reference another table,
 * so school_id must be a real column disciplined by FK rather than derived.
 *
 * ON DELETE — semantics PRESERVED per FK, not chosen globally. Both single-column
 * FKs are ON DELETE CASCADE today and the composites keep that. Verified safe: a
 * students delete cannot reach a finance_invoices row, because
 * finance_invoices.student_id (RESTRICT) blocks it directly AND
 * finance_invoices.student_curriculum_id (RESTRICT) blocks the cascade — in InnoDB a
 * cascaded delete reaching a RESTRICT child fails the whole statement. The armour
 * for an invoiced episode lives on finance_invoices, NOT here, so preserving CASCADE
 * weakens nothing; tightening to RESTRICT would instead block hard-deleting an
 * UNINVOICED student, a behaviour change beyond this slice.
 *
 * ON UPDATE — NO ACTION, deliberately (D2). students.school_id has no mutation path
 * in the codebase; a student moving School is a new admission, not an UPDATE. A
 * cascade here would silently rewrite the School attribution of every historical
 * billed/graded episode. Student::$fillable is guarded alongside this migration.
 *
 * INDEX HAZARD. MySQL leaves a FK's backing index behind on DROP FOREIGN KEY, and
 * the two FKs differ: fk_student_curricula_curriculum_id owns an index of the same
 * name (dropped explicitly below), while fk_student_curricula_student_id owns none —
 * it rides student_curricula_student_id_curriculum_id_unique (Option B's target
 * constraint, deliberately untouched here).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Parent unique keys the composite child FKs reference. Additive.
        DB::statement('ALTER TABLE students  ADD UNIQUE students_id_school_unique  (id, school_id)');
        DB::statement('ALTER TABLE curricula ADD UNIQUE curricula_id_school_unique (id, school_id)');

        // Nullable first so the backfill has somewhere to land.
        DB::statement('ALTER TABLE student_curricula ADD COLUMN school_id BIGINT UNSIGNED NULL AFTER student_id');

        // Backfill from the account holder. A RAW join is correct precisely BECAUSE
        // it ignores SoftDeletes: `students.deleted_at` is just a column, so a
        // soft-deleted student's row is still joinable and its episodes are still
        // filled. A relation-based fill would silently skip them and leave NULLs
        // that the NOT NULL below would then reject.
        DB::statement(
            'UPDATE student_curricula sc
               JOIN students s ON s.id = sc.student_id
                SET sc.school_id = s.school_id'
        );

        // Fail loudly rather than let NOT NULL produce an opaque error: an orphan
        // episode (student_id pointing nowhere) is the only way to get here.
        $unfilled = (int) DB::table('student_curricula')->whereNull('school_id')->count();
        if ($unfilled > 0) {
            throw new RuntimeException(
                "student_curricula backfill left {$unfilled} row(s) with a NULL school_id — ".
                'these have no resolvable student. Investigate before re-running.'
            );
        }

        DB::statement('ALTER TABLE student_curricula MODIFY school_id BIGINT UNSIGNED NOT NULL');

        // Swap single-column FK -> composite. student_id first (no orphan index).
        DB::statement('ALTER TABLE student_curricula DROP FOREIGN KEY fk_student_curricula_student_id');
        DB::statement(
            'ALTER TABLE student_curricula
                ADD CONSTRAINT student_curricula_student_school_foreign
                FOREIGN KEY (student_id, school_id) REFERENCES students (id, school_id)
                ON DELETE CASCADE'
        );

        // curriculum_id owns its index — drop it explicitly or it lingers.
        DB::statement('ALTER TABLE student_curricula DROP FOREIGN KEY fk_student_curricula_curriculum_id');
        DB::statement('ALTER TABLE student_curricula DROP INDEX fk_student_curricula_curriculum_id');
        DB::statement(
            'ALTER TABLE student_curricula
                ADD CONSTRAINT student_curricula_curriculum_school_foreign
                FOREIGN KEY (curriculum_id, school_id) REFERENCES curricula (id, school_id)
                ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        // Mirror image, including the same index-drop hazard on the way back: the
        // composite FKs' backing indexes must go explicitly, or a re-up() cannot
        // recreate the original single-column ones cleanly.
        DB::statement('ALTER TABLE student_curricula DROP FOREIGN KEY student_curricula_curriculum_school_foreign');
        DB::statement('ALTER TABLE student_curricula DROP INDEX student_curricula_curriculum_school_foreign');
        DB::statement(
            'ALTER TABLE student_curricula
                ADD CONSTRAINT fk_student_curricula_curriculum_id
                FOREIGN KEY (curriculum_id) REFERENCES curricula (id) ON DELETE CASCADE'
        );

        DB::statement('ALTER TABLE student_curricula DROP FOREIGN KEY student_curricula_student_school_foreign');
        DB::statement('ALTER TABLE student_curricula DROP INDEX student_curricula_student_school_foreign');
        DB::statement(
            'ALTER TABLE student_curricula
                ADD CONSTRAINT fk_student_curricula_student_id
                FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE'
        );

        DB::statement('ALTER TABLE student_curricula DROP COLUMN school_id');

        DB::statement('ALTER TABLE curricula DROP INDEX curricula_id_school_unique');
        DB::statement('ALTER TABLE students  DROP INDEX students_id_school_unique');
    }
};

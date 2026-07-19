<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slice (i) file 2 of 2 (D3) — make Finance slice 2's duplicate-invoice guard
 * STRUCTURAL instead of derivation-dependent.
 *
 * THE PROBLEM THIS CLOSES. Slice 2 guards "one active invoice per enrollment
 * episode" with UNIQUE(school_id, active_enrollment_key), where the generated key
 * is student_curriculum_id. That guard therefore already depends on the episode's
 * School — while deriving it from a DIFFERENT table (students, via the ACL adapter,
 * with a null → 0 fallback). Once student_curricula.school_id exists, the same fact
 * lives in two tables, and nothing but application discipline keeps them agreeing.
 * A denormalization without the constraint that disciplines it is the wallpaper
 * state this slice exists to end.
 *
 * The composite FK below makes a finance_invoices row whose school_id disagrees with
 * its episode's school_id a FOREIGN KEY VIOLATION, so slice 2's guard no longer
 * rests on the adapter's derivation being right.
 *
 * SEPARATE FILE, SAME DEPLOY (Gate 1). "Own file" buys clean down() ordering and
 * independent rollback of the Finance coupling — it was never about deferred
 * shipping. Shipping later would leave the two-table denormalization undisciplined
 * across a deploy, and would throw away a free correctness check: adding this
 * constraint validates EVERY existing invoice against its episode's School at
 * creation time. If file 1's backfill were wrong, this is where we find out.
 *
 * ON DELETE RESTRICT — preserved from the single-column FK it replaces. This is the
 * armour that makes an invoiced episode undeletable and blocks the academic CASCADE
 * chain (docs/finance-data-ownership.md Part 4); it must not weaken.
 *
 * PARENT KEY. This FK points FROM finance_invoices TO student_curricula, so the new
 * parent key it needs is student_curricula (id, school_id) — added below.
 * finance_invoices already carries its own finance_invoices_id_school_unique, but
 * that one parents ITS children (finance_invoice_lines, finance_payment_allocations)
 * and is irrelevant here; it was added by the template-freeze migration
 * 2026_07_19_110001_enforce_finance_child_school_integrity, NOT by slice 2.
 * finance_invoices itself, and the single-column FK swapped below, both come from
 * the walking skeleton (2026_07_19_100000) — so this migration depends only on
 * changes already on staging, never on slice 2's 120000.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Parent key for the composite child FK below.
        DB::statement('ALTER TABLE student_curricula ADD UNIQUE student_curricula_id_school_unique (id, school_id)');

        // Swap single-column -> composite. This FK owns its index; drop it explicitly.
        DB::statement('ALTER TABLE finance_invoices DROP FOREIGN KEY fee_invoices_student_curriculum_id_foreign');
        DB::statement('ALTER TABLE finance_invoices DROP INDEX fee_invoices_student_curriculum_id_foreign');
        DB::statement(
            'ALTER TABLE finance_invoices
                ADD CONSTRAINT finance_invoices_episode_school_foreign
                FOREIGN KEY (student_curriculum_id, school_id)
                REFERENCES student_curricula (id, school_id)
                ON DELETE RESTRICT'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE finance_invoices DROP FOREIGN KEY finance_invoices_episode_school_foreign');
        DB::statement('ALTER TABLE finance_invoices DROP INDEX finance_invoices_episode_school_foreign');
        DB::statement(
            'ALTER TABLE finance_invoices
                ADD CONSTRAINT fee_invoices_student_curriculum_id_foreign
                FOREIGN KEY (student_curriculum_id) REFERENCES student_curricula (id)
                ON DELETE RESTRICT'
        );

        DB::statement('ALTER TABLE student_curricula DROP INDEX student_curricula_id_school_unique');
    }
};

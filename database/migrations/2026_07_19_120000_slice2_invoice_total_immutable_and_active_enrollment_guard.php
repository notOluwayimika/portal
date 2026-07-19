<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slice 2 — multi-line invoicing. Three DB-level changes, each closing a hole the
 * application layer alone cannot close.
 *
 * 1. VOID vocabulary (ubiquitous language). The signed accounting policy says
 *    cancellation is a VOID status, never a deletion. The skeleton called it
 *    'cancelled'; the data is migrated so code and DB speak the policy's word.
 *
 * 2. F6 — invoice total is snapshotted at creation and NEVER hand-edited.
 *    finance_invoices legitimately allows UPDATE (the status flips issued → void),
 *    which left total_minor hand-editable and therefore able to drift from
 *    SUM(lines). A BEFORE UPDATE trigger denies any change to the money columns
 *    while leaving the status transition free.
 *
 *    SCOPE OF THIS GUARD (recorded honestly): `total ≠ SUM(lines)` has two
 *    sources — (a) mutating total after creation, and (b) inserting a line after
 *    total was computed. This closes (a) at the DB. (b) is NOT domain-reachable:
 *    a grep of every line-INSERT path found exactly one, inside GenerateInvoice's
 *    creating transaction — there is no add-line-to-existing-invoice route,
 *    method or raw write. So (b) is a tamper vector, not an operational path, and
 *    is recorded as a residual GAP in docs/roadmap.md. Its closing mechanism is
 *    the "seal" (a lines_sealed_at column + a BEFORE INSERT trigger on
 *    finance_invoice_lines rejecting lines on a sealed invoice); it lands when a
 *    draft / multi-step-build lifecycle makes "sealed" an observable state the
 *    domain actually has. Building that lifecycle now would front-load a shape
 *    with no consumer — the mistake v10 §28.4 records.
 *
 * 3. "One ACTIVE invoice per enrollment episode" — a SET-based invariant no single
 *    Invoice aggregate can see, so it is enforced at the DB by a STORED generated
 *    column + a unique index:
 *
 *        active_enrollment_key = IF(status = 'issued', student_curriculum_id, NULL)
 *        UNIQUE (school_id, active_enrollment_key)
 *
 *    Issued ⇒ the key is the episode ⇒ a second issued invoice for that episode is
 *    a duplicate-key error. Voided ⇒ the key recomputes to NULL, and NULLs do not
 *    collide in a MySQL unique index ⇒ the policy's "repeat = billed fresh"
 *    re-bill after a void still works. A naive UNIQUE(school_id,
 *    student_curriculum_id) would forbid that re-bill, because voided invoices are
 *    append-only and never leave the table.
 *
 *    It is GENERATED, not application-maintained, so no code path can forget to
 *    set or clear it — the invariant holds by construction rather than by
 *    discipline.
 */
return new class extends Migration
{
    private const TOTAL_IMMUTABLE_TRIGGER = 'finance_invoices_total_immutable';

    public function up(): void
    {
        // 1 — VOID vocabulary. Runs before the generated column exists; the column
        // keys on the ACTIVE state ('issued'), so this rename cannot disturb it.
        DB::statement("UPDATE finance_invoices SET status = 'void' WHERE status = 'cancelled'");

        // 2 — F6: the money snapshot is immutable; the status transition is not.
        DB::unprepared(
            'CREATE TRIGGER '.self::TOTAL_IMMUTABLE_TRIGGER.' BEFORE UPDATE ON finance_invoices
             FOR EACH ROW
             BEGIN
                IF NEW.total_minor <> OLD.total_minor OR NEW.total_currency <> OLD.total_currency THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_invoices.total is snapshotted at creation (F6: total = SUM(lines)): UPDATE of total_minor/total_currency is denied.\';
                END IF;
             END'
        );

        // 3 — the set-based "one active invoice per enrollment" invariant.
        DB::statement(
            'ALTER TABLE finance_invoices
                ADD COLUMN active_enrollment_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = \'issued\', student_curriculum_id, NULL)) STORED'
        );
        DB::statement(
            'ALTER TABLE finance_invoices
                ADD UNIQUE finance_invoices_active_enrollment_unique (school_id, active_enrollment_key)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE finance_invoices DROP INDEX finance_invoices_active_enrollment_unique');
        DB::statement('ALTER TABLE finance_invoices DROP COLUMN active_enrollment_key');
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TOTAL_IMMUTABLE_TRIGGER);
        DB::statement("UPDATE finance_invoices SET status = 'cancelled' WHERE status = 'void'");
    }
};

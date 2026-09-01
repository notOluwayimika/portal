<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INTERNAL AUDIT REVIEW — the release axis on an invoice, and the gate in front of the parent
 * portal's 6 September date.
 *
 * Brookstone ruled on 31 August 2026 (docs/handoff/brookstone-answers-31-august.md §2, §6) that
 * EVERY bill — the scheduled termly run included — must be reviewed by an Internal Auditor before
 * it is released to parents. The bill is created and DOES count against the student's balance
 * immediately; only its VISIBILITY to parents is gated.
 *
 * This migration ships the axis and nothing else. There is no auditor seat, no review action and
 * no batch object here — those are the feature. This is the compliance gate that must be in front
 * of a parent-facing screen that is ALREADY LIVE (`GET /api/parent/finance/wards`,
 * declared in routes/endpoints/parent-finance.php and served by `GuardianFinanceController::wards()`).
 *
 * ── WHY THIS IS A SEPARATE AXIS AND NOT AN `InvoiceStatus` CASE ──────────────────────────────────
 *
 * `finance_invoices` carries a STORED generated column installed by
 * 2026_07_19_120000 and re-expressed by 2026_08_18_100000:
 *
 *     active_enrollment_key = IF(status = 'issued' AND kind = 'scheduled', student_curriculum_id, NULL)
 *     UNIQUE (school_id, active_enrollment_key)
 *
 * That is the duplicate-invoice guard. ANY status other than `issued` recomputes the key to NULL
 * and frees the enrollment's active slot — `InvoiceStatus`'s own docblock says so deliberately,
 * naming "DRAFT, REJECTED, Ph3" as future states that SHOULD free it. That docblock was written on
 * the assumption that a pre-release bill does not count. Brookstone have now said it does.
 *
 * So a bill awaiting review carrying a new STATUS would leave its enrollment unguarded for the
 * whole review window, and a second run over the same cohort would SUCCEED instead of colliding.
 * `status` therefore stays `issued` and the release state lives here. That also leaves the eight
 * `InvoiceStatus::` reads in `app/` correct without revisiting each one.
 *
 * ── THE COLUMNS ARE FREELY WRITABLE, AND THAT WAS CHECKED RATHER THAN ASSUMED ────────────────────
 *
 * The only BEFORE UPDATE trigger on this table is `finance_invoices_total_immutable`
 * (2026_07_19_120000:63-70), which signals only when `total_minor` or `total_currency` changes.
 * `finance_invoices_kind_immutable` (2026_08_18_100000) guards `kind`. Neither is disturbed by an
 * UPDATE that touches only the two columns added here, and neither is touched by this migration.
 *
 * ALTER TABLE is DDL and fires no DML trigger — precedent: 2026_07_21_120000 and 2026_07_26_120000
 * both added columns to `finance_invoice_lines` while its append-only trigger was live.
 *
 * ── `reviewed_by_user_id` IS A LOOKUP, NOT AN FK ─────────────────────────────────────────────────
 *
 * Plain `unsignedBigInteger`, nullable, no `constrained()`. The house convention for an ATTRIBUTION
 * — `finance_invoices.cancelled_by_user_id`, `finance_payments.received_by_user_id`,
 * `finance_credit_notes.created_by_user_id`, `finance_bulk_invoice_runs.started_by_user_id`. An
 * attribution must never block a user's lifecycle.
 *
 * ── THE BACKFILL, WHICH IS THE HALF THAT DECIDES WHETHER THE 6th WORKS ───────────────────────────
 *
 * Every invoice that exists when this migration runs is stamped REVIEWED, at its own `created_at`.
 *
 * WITHOUT IT THE FILTER EMPTIES THE PARENT SCREEN FOR THE WHOLE EXISTING BOOK. A nullable column
 * defaults to NULL, `InvoiceReadModel::outstandingForStudent()` withholds every NULL, and the
 * result is a live screen that tells every parent in the school they owe nothing — on the day a
 * compliance gate ships, in the falsely-reassuring direction.
 *
 * THE REASONING, AND IT IS A RULING ABOUT SCOPE RATHER THAN A CONVENIENCE: **the Auditor's remit
 * starts when the control does.** A bill raised before Internal Audit review existed was never
 * within the Auditor's remit and cannot meaningfully be "awaiting" a review nobody was ever asked
 * to perform. Withholding it would not enforce Brookstone's rule retroactively — no such review
 * happened or could have — it would only hide a bill the parent could already see yesterday.
 *
 * `created_at` IS THE HONEST STAMP, not `now()`. It says the bill was releasable from the moment it
 * was raised, which is exactly what was true of the world before this column existed. `now()` would
 * assert that six hundred invoices were reviewed at one instant during a migration.
 *
 * `reviewed_by_user_id` STAYS NULL ON EVERY BACKFILLED ROW, deliberately. Nobody reviewed them, and
 * naming a user who did not would be a fabricated audit record — the one thing an audit column must
 * never contain. So `reviewed_at IS NOT NULL AND reviewed_by_user_id IS NULL` is a THIRD legitimate
 * state, meaning "grandfathered: released because it predates the control". It is distinguishable
 * from a real review (both columns set) and from a pending one (both NULL), which is why no CHECK
 * pairs them.
 *
 * ── ROLLBACK ────────────────────────────────────────────────────────────────────────────────────
 *
 * `down()` drops the index and both columns, which is total: the release state has no second home,
 * so nothing survives to be inconsistent. Rolling back restores the pre-31-August behaviour, where
 * every issued invoice is visible to its parent.
 */
return new class extends Migration
{
    /**
     * IDEMPOTENT BY CONSTRUCTION, and the guard is load-bearing rather than defensive habit.
     *
     * The backfill is the half of this migration most worth testing, and the only honest way to test
     * a backfill is to seed rows in the PRE-migration shape and then migrate them — a test starting
     * from post-migration rows describes rows the migration created, not rows it had to survive. So
     * the audit arm in ParentPortalFinanceReadTest drops the columns to reconstruct that shape and
     * calls `up()` directly, then calls it once more in a `finally` so a failed assertion cannot
     * leave the schema broken for the rest of the suite. Both calls require this to be re-runnable.
     *
     * The columns are added only when absent; the BACKFILL RUNS EVERY TIME and is safe to, because
     * it is conditioned on `reviewed_at IS NULL` — on a second call there is nothing left to stamp.
     *
     * It reconstructs the shape by DROPPING THE COLUMNS rather than by `migrate:rollback --step=N`,
     * for the reason recorded in docs/testing.md: `--step` counts from the branch's latest
     * migrations, so a sibling migration landing on top would be rolled back instead and the arm
     * would pass having tested nothing. This repository has been bitten by that once already.
     */
    public function up(): void
    {
        if (Schema::hasColumn('finance_invoices', 'reviewed_at')) {
            $this->backfill();

            return;
        }

        Schema::table('finance_invoices', function (Blueprint $table) {
            // WHEN Internal Audit released this bill to parents. NULL = raised and counting against
            // the balance, but not yet visible to the payer.
            $table->timestamp('reviewed_at')->nullable()->after('cancel_reason');

            // WHO released it. LOOKUP, not an FK — see the docblock. NULL on a grandfathered row.
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable()->after('reviewed_at');

            // The two reads this column exists to serve are both per (school, student): the parent's
            // invoice list and the withheld-charge adjustment behind their balance. `finance_invoices`
            // carries no (school_id, student_id) index today — only `unique(school_id, number)` and
            // the FK index on `student_id` — so this covers the pair AND the predicate.
            $table->index(['school_id', 'student_id', 'reviewed_at'], 'finance_invoices_school_student_reviewed_index');
        });

        $this->backfill();
    }

    /**
     * THE BACKFILL. Every invoice that predates this column is released, at its own creation moment
     * — the Auditor's remit starts when the control does. See the class docblock for why that is a
     * ruling about scope and not a convenience, and why `reviewed_by_user_id` is left NULL.
     *
     * Set-based, one statement, and guarded on `reviewed_at IS NULL` so it stamps only rows that
     * have never been decided — which is what makes `up()` re-runnable without ever overwriting a
     * real Auditor's timestamp with a creation date.
     */
    private function backfill(): void
    {
        DB::statement('UPDATE finance_invoices SET reviewed_at = created_at WHERE reviewed_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table) {
            $table->dropIndex('finance_invoices_school_student_reviewed_index');
            $table->dropColumn(['reviewed_at', 'reviewed_by_user_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §9 commit 1 of the WCBS opening-balance import (docs/handoff/opening-balance-import-spec.md Rev 2) —
 * the STAGING tables only. Nothing here posts: no ledger row, no payment, no invoice, no account
 * balance movement. The only writer is the read-only validator `finance:import-opening-balances
 * --dry-run` ({@see App\Finance\Console\ImportOpeningBalances}), which parses a WCBS extract, checks
 * §1's identity per row, runs §5's fee-schedule comparison and records what it found.
 *
 * DELIBERATELY ABSENT (they ship with the commits that WRITE them, per §9 — a column ahead of its
 * writer is front-loading): finance_payments.origin / external_reference / the `migrated` method value
 * / the reserved receipt band (§4), the posting Action (§3), the approval gate (§8), and the U12b
 * screen. The batch's `status` therefore has three values today — draft | validated | rejected — and
 * gains its posting states in commit 4.
 *
 * MONEY is integer minor units + an explicit ISO-4217 currency on every amount ({name}_minor +
 * {name}_currency, Constitution rule 10). The row-level amounts are NULLABLE because a blank or
 * unparseable cell must be STAGED AS ABSENT and rejected with a named finding — never coerced to
 * zero (§2: "Blank ≠ zero"). MoneyCast's contract already distinguishes those: both columns NULL is
 * a legitimate absence, exactly one NULL is a corrupt row and throws.
 *
 * The eight new *_currency columns get the same `^[A-Z]{3}$` CHECK the other ten carry
 * (2026_08_01_120000_add_currency_shape_checks.php). `COLLATE utf8mb4_bin` is load-bearing: under the
 * default case-insensitive collation 'ngn' matches the pattern and the CHECK is a false all-clear.
 *
 * KEYS:
 *  - unique(school_id, batch_reference) — re-running a batch is refused BY THE DATABASE (§7
 *    idempotency), not by a PHP guard clause. The validator inserts the batch row FIRST, before it
 *    parses a byte, so a repeat run stops at the engine.
 *  - unique(school_id, batch_id, admission_number) — one staged row per student per batch, also at
 *    the DB. NULL admission numbers are exempt from the index (MySQL), which is correct: a blank join
 *    key is a rejected row, not a collision.
 *  - the (batch_id, school_id) → batches(id, school_id) composite FK is the 2026_07_19_110001
 *    pattern: a row's School can only ever be its batch's School, unrepresentable-when-violated at
 *    the engine rather than by application discipline. CASCADE on delete — a staging batch is scratch
 *    data with no posted consequence in this commit, so deleting one takes its rows with it.
 *
 * NOT FKs, deliberately: `uploaded_by_user_id` (a LOOKUP, matching finance_payments.received_by_user_id)
 * and `rows.student_id` — a row may resolve to NO student, and that is a reportable outcome (§7:
 * "Student in WCBS, absent from the portal → Reject the row and name it"), not a constraint violation.
 */
return new class extends Migration
{
    /**
     * The new *_currency columns needing the shape CHECK, each with the constraint name to use.
     *
     * The name is given EXPLICITLY and does not follow the sibling migration's `{table}_{column}_shape`
     * convention, because that convention does not fit: MySQL caps an identifier at 64 characters and
     * `finance_opening_balance_batches_total_prior_arrears_currency_shape` is 66. The abbreviated
     * prefix is the only thing that changes; the constraint itself is identical.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private const CURRENCY_COLUMNS = [
        ['finance_opening_balance_batches', 'total_prior_arrears_currency', 'ob_batches_total_prior_arrears_currency_shape'],
        ['finance_opening_balance_batches', 'total_paid_to_date_currency', 'ob_batches_total_paid_to_date_currency_shape'],
        ['finance_opening_balance_batches', 'total_wcbs_billed_currency', 'ob_batches_total_wcbs_billed_currency_shape'],
        ['finance_opening_balance_rows', 'prior_arrears_currency', 'ob_rows_prior_arrears_currency_shape'],
        ['finance_opening_balance_rows', 'wcbs_billed_total_currency', 'ob_rows_wcbs_billed_total_currency_shape'],
        ['finance_opening_balance_rows', 'paid_to_date_currency', 'ob_rows_paid_to_date_currency_shape'],
        ['finance_opening_balance_rows', 'wcbs_total_balance_currency', 'ob_rows_wcbs_total_balance_currency_shape'],
        ['finance_opening_balance_rows', 'expected_billed_currency', 'ob_rows_expected_billed_currency_shape'],
    ];

    public function up(): void
    {
        Schema::create('finance_opening_balance_batches', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();

            $table->string('batch_reference');
            $table->string('filename');
            $table->string('status')->default('draft');   // draft | validated | rejected

            // Control totals (§5), written when the run completes. NULL until then — a batch that
            // aborted mid-parse must not present a total that was never summed.
            $table->unsignedInteger('row_count')->default(0);
            $table->bigInteger('total_prior_arrears_minor')->nullable();
            $table->char('total_prior_arrears_currency', 3)->nullable();
            $table->bigInteger('total_paid_to_date_minor')->nullable();
            $table->char('total_paid_to_date_currency', 3)->nullable();
            $table->bigInteger('total_wcbs_billed_minor')->nullable();
            $table->char('total_wcbs_billed_currency', 3)->nullable();

            $table->date('cutover_date');                                       // D
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete(); // T, named ONCE on
            // the batch and never per row — §1's three figures are all defined relative to a single
            // cutover term, so a per-row term would make the identity un-checkable.

            $table->unsignedBigInteger('uploaded_by_user_id')->nullable(); // LOOKUP, not an FK
            $table->json('findings')->nullable();                          // batch-level findings (§6)
            $table->timestamps();

            // Named explicitly — Laravel's derived name is exactly at MySQL's 64-character ceiling,
            // and the rows' equivalent is over it (1059). One convention for both, not one each.
            $table->unique(['school_id', 'batch_reference'], 'ob_batches_school_reference_unique');
        });

        // Parent key for the rows' composite school-integrity FK.
        DB::statement(
            'ALTER TABLE finance_opening_balance_batches
             ADD UNIQUE finance_opening_balance_batches_id_school_unique (id, school_id)'
        );

        Schema::create('finance_opening_balance_rows', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->unsignedBigInteger('batch_id'); // composite FK added below

            $table->unsignedInteger('line_number');           // 1-based position in the file
            $table->string('admission_number')->nullable();   // exactly as it appeared, untrimmed
            $table->string('wcbs_student_ref')->nullable();

            $table->bigInteger('prior_arrears_minor')->nullable();
            $table->char('prior_arrears_currency', 3)->nullable();
            $table->bigInteger('wcbs_billed_total_minor')->nullable();
            $table->char('wcbs_billed_total_currency', 3)->nullable();
            $table->bigInteger('paid_to_date_minor')->nullable();
            $table->char('paid_to_date_currency', 3)->nullable();
            $table->bigInteger('wcbs_total_balance_minor')->nullable();
            $table->char('wcbs_total_balance_currency', 3)->nullable();

            $table->string('wcbs_bill_reference')->nullable();
            $table->date('last_payment_date')->nullable();

            $table->unsignedBigInteger('student_id')->nullable(); // LOOKUP, not an FK — see docblock
            $table->string('status');                             // ok | rejected | not_comparable
            $table->json('findings')->nullable();                 // machine codes + human text

            // What §5 computed from the ACTIVE fee schedule; NULL when not comparable.
            $table->bigInteger('expected_billed_minor')->nullable();
            $table->char('expected_billed_currency', 3)->nullable();

            $table->timestamps();

            $table->unique(['school_id', 'batch_id', 'admission_number'], 'ob_rows_school_batch_admission_unique');
        });

        DB::statement(
            'ALTER TABLE finance_opening_balance_rows
             ADD CONSTRAINT finance_opening_balance_rows_batch_school_foreign
             FOREIGN KEY (batch_id, school_id)
             REFERENCES finance_opening_balance_batches (id, school_id) ON DELETE CASCADE'
        );

        foreach (self::CURRENCY_COLUMNS as [$table, $column, $name]) {
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$name}
                 CHECK ({$column} IS NULL OR {$column} COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}\$')"
            );
        }
    }

    public function down(): void
    {
        // Rows first — its composite FK references the batches parent key.
        Schema::dropIfExists('finance_opening_balance_rows');
        Schema::dropIfExists('finance_opening_balance_batches');
    }
};

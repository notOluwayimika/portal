<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S2 + S3 — the capture columns. Money rows must say WHEN they happened and WHY they were
 * allocated, and that has to be true from the first row Brookstone writes.
 *
 * THIS MIGRATION WILL FAIL IF ANY OF THE THREE TABLES HOLDS A ROW, AND THAT IS THE DESIGN.
 * MySQL refuses to add a NOT NULL column with no default to a non-empty table. All three tables
 * are empty today (verified against the production copy: 0 payments, 0 ledger transactions, 0
 * allocations), so the columns can be NOT NULL. If production is not as empty as the copy, this
 * deploy STOPS and we find out — which is enormously better than stamping a fabricated value onto
 * real money rows. docs/handoff/post-deploy-tasks.md carries a PRE-deploy step to confirm the
 * counts before this runs, so the failure is anticipated rather than discovered at 2am.
 *
 * WHY NOT NULL AND NOT NULLABLE. Nullable was the first instinct — "NULL means written before the
 * column existed" — and it is wrong precisely because there IS no such history. With a nullable
 * column, a writer that forgets to supply a value is forever indistinguishable from history that
 * predates the column. NOT NULL makes a forgetful writer fail at the database instead of writing a
 * silent gap, and closing that gap before term one rather than after is the entire point of doing
 * this now.
 *
 * NO DEFAULTS on the five NOT NULL columns. A default would let a writer omit the value and get one
 * anyway, which reintroduces the silent gap NOT NULL is being used to close.
 *
 * ONE MIGRATION, NOT THREE, and the reason is the writers rather than tidiness. The same commit
 * teaches six write sites to supply all five columns. If these were three migrations and only the
 * first had run, every one of those writers would fail on the columns the other two would have
 * added — a half-applied state in which finance cannot record money at all. They are one decision
 * with one precondition (all three tables empty), so they are one unit.
 *
 * THE TABLES ARE APPEND-ONLY, so none of this can be retrofitted later. Measured, not asserted —
 * an UPDATE against each table, inside a rolled-back transaction:
 *
 *   finance_payments               SQLSTATE 45000 / 1644  "is append-only (Constitution §15C): UPDATE is denied."
 *   finance_ledger_transactions    SQLSTATE 45000 / 1644  "... Corrections are reversing entries."
 *   finance_payment_allocations    SQLSTATE 45000 / 1644
 *
 * The `*_reason` columns are the two genuine nullables: a reason exists only when there is
 * something to explain (a received date that is not the day it was keyed; an allocation a human
 * overrode). NULL there means "nothing to explain", not "not recorded".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            // The business date the money was RECEIVED, which is not always the day it was keyed
            // in. A payment handed over on Friday and entered on Monday belongs to Friday.
            $table->date('received_at')->after('origin');

            // Required only when received_at is not the day of entry — U9's spec. A back-dated
            // receipt without a reason is the thing an auditor asks about first.
            $table->string('received_at_reason')->nullable()->after('received_at');
        });

        Schema::table('finance_ledger_transactions', function (Blueprint $table) {
            // TWO DATES, AND THEY ANSWER DIFFERENT QUESTIONS. Do not collapse them.
            //
            //   posted_at     when this row was WRITTEN. A system-clock fact about the record.
            //                 Answers "when did we learn this?" and is what an audit trail walks.
            //   effective_at  the business date the entry BELONGS TO. Answers "which period is
            //                 this in?" and is what a statement, an ageing report and a period
            //                 total are built from.
            //
            // They coincide for an invoice raised today and diverge for every correction, every
            // back-dated receipt and every migrated opening balance. A single column would force a
            // choice between an audit trail that lies about timing and period totals that lie
            // about content. created_at is NOT a substitute for either: it is the system clock,
            // which makes it a worse posted_at (no explicit intent) and an unusable effective_at.
            $table->timestamp('posted_at')->after('narration');
            $table->date('effective_at')->after('posted_at');
        });

        Schema::table('finance_payment_allocations', function (Blueprint $table) {
            // WHICH RULE PRODUCED THIS ROW. Two allocation behaviours exist in the code today and
            // they are genuinely different questions, so one value cannot describe both — see
            // App\Finance\Models\PaymentAllocation for the constants and what each one means.
            // This is a string carrying a named constant, deliberately NOT an enum column: the set
            // is what the code does today, and a database enum would have to be migrated to add
            // the third rule whose screen (U10) has not been built.
            $table->string('allocation_rule', 64)->after('amount_currency');

            // Whether a human overrode what the engine computed. No path can set this true today;
            // every allocation is computed. It is false at both writers, explicitly, because the
            // question "did a person choose this?" must have an answer for every row from the
            // first one — a column added later would leave every earlier row silent about it, and
            // append-only means silent forever.
            $table->boolean('allocation_overridden')->after('allocation_rule');

            // Why the human overrode it. Null whenever allocation_overridden is false.
            $table->string('allocation_override_reason')->nullable()->after('allocation_overridden');
        });
    }

    public function down(): void
    {
        Schema::table('finance_payment_allocations', function (Blueprint $table) {
            $table->dropColumn(['allocation_rule', 'allocation_overridden', 'allocation_override_reason']);
        });

        Schema::table('finance_ledger_transactions', function (Blueprint $table) {
            $table->dropColumn(['posted_at', 'effective_at']);
        });

        Schema::table('finance_payments', function (Blueprint $table) {
            $table->dropColumn(['received_at', 'received_at_reason']);
        });
    }
};

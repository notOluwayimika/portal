<?php

use App\Finance\Enums\ManualInvoiceRunOutcome;
use App\Finance\Enums\ManualInvoiceRunStatus;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use App\Finance\Jobs\ProcessManualInvoiceRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE MANUAL INVOICE RUN — a bursar's own list of enrollments, billed one supplementary invoice
 * each, from lines the operator typed rather than from a fee schedule.
 *
 * FOUR NEW TABLES AND NOT ONE COLUMN ON THE SCHEDULED RUN'S. `finance_bulk_invoice_runs` carries
 * `term_id` and `class_level_id` as NOT NULL constrained FKs (2026_08_18_110000:149-150) because a
 * scheduled run MEANS "the cohort at this (term, class level) slot" — `cohort_count`,
 * `outside_coordinates_count` and the cohort equality are every one of them phrased against that
 * slot. An arbitrary student list spans class levels by definition, so making the slot nullable
 * would make one table mean two things and force every reader to know which
 * (docs/handoff/bulk-manual-invoicing-brief.md §3, option A). This is option B.
 *
 * IT IS ALSO A RISK DECISION WITH A DATE ON IT. {@see ProcessBulkInvoiceRun}
 * issues Term 1's bills on 5 September 2026 and has never run on production. The write ordering it
 * uses is the thing this table's job inverts (below); inverting it in the scheduled job days before
 * its first real execution is risk that job does not need, and it does not need it because it is
 * already protected by `UNIQUE(school_id, active_enrollment_key)` on `finance_invoices` — the
 * backstop the supplementary path lacks entirely. So the scheduled path is FROZEN and this is a
 * sibling, not an edit.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * CLAIM-THEN-BILL, AND THE MEASURED DEFECT IT EXISTS TO NOT INHERIT
 *
 * In the scheduled run the invoice is created at `ProcessBulkInvoiceRun:446` and the row that
 * records it is written at `:593` — AFTER. So `UNIQUE(school_id, run_id, enrollment_id)` on
 * `finance_bulk_invoice_run_rows` sits DOWNSTREAM of the money: on a re-execution the invoice
 * commits first and the row insert then collides with 1062, which `attempt()` (`:386`) only LOGS.
 * The result is a duplicate invoice that NO ROW RECORDS, which also breaks the run's own cohort
 * equality. Nothing has hit it because `tries = 1` (`:147`) stops Laravel retrying — one flag
 * standing between a bulk run and an unrecorded double bill.
 *
 * That is survivable on the scheduled path because the generated-column index refuses the second
 * SCHEDULED invoice anyway. It is NOT survivable here:
 * `docs/handoff/tickets/a-supplementary-invoice-has-no-duplicate-backstop.md` proves positively —
 * `SupplementaryInvoiceWireTest:217-218`, two raw identical supplementary inserts, both driver code
 * NULL — that nothing at any layer refuses a second identical supplementary invoice. The ticket
 * accepted that exposure partly because "the blast radius is one student's balance". Over a list of
 * ninety it is ninety duplicate charges, each recoverable only by its own two-signature void.
 *
 * So {@see ProcessManualInvoiceRun} inverts the order:
 *
 *   1. INSERT the row into `finance_manual_invoice_run_rows` as a CLAIM (`outcome = 'claimed'`), in
 *      its own committed write.
 *   2. `UNIQUE(school_id, run_id, enrollment_id)` refuses a second claim — BEFORE any invoice
 *      exists.
 *   3. Bill.
 *   4. UPDATE the claim row with the real outcome.
 *
 * The index is unchanged in shape from the scheduled run's; what moved is the money, to the other
 * side of it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE COHORT EQUALITY, RE-STATED FOR THIS SHAPE — AND `claimed_count` IS NOT A TERM OF IT
 *
 *     billed_count + failed_count + unplaceable_count == target_count
 *
 * `target_count` is the size of the list the run WALKED (`finance_manual_invoice_run_targets`); the
 * three counts beside it are counted from the rows it PERSISTED. Two independent sources, which is
 * the only reason the equality can fail and therefore the only reason asserting it is worth anything
 * — the same discipline as 2026_08_18_110000, which see for why there is no "something went wrong"
 * flag column.
 *
 * AND `target_count` IS THE NUMBER THE BURSAR TICKED, which is the whole reason the target table is
 * keyed on the STUDENT (below). Keyed on the enrollment it counted what SURVIVED RESOLUTION, so a
 * run could report "90 of 90" after a selection of 96 — balanced, complete, and six families short.
 * Brookstone ruled on 30 August 2026 that this feature issues DIRECTLY, with no maker-checker: there
 * is no second human, and the run report is the only place a wrong selection can surface.
 *
 * A CLAIMED ROW IS NOT ON THE LEFT-HAND SIDE, AND THAT IS THE ENTIRE POINT. A run that finished with
 * a claim outstanding did not bill that enrollment and did not fail it either — it does not know
 * what happened. Adding `claimed_count` to the left would make the equality balance on exactly the
 * runs it exists to catch. `claimed_count` is recorded BESIDE it as the diagnosis (it is precisely
 * the shortfall), never as a term. **Adding it to the left is how you switch this alarm off while
 * appearing to complete it.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE NEW FAILURE MODE, WHICH IS AN IMPROVEMENT, STATED AS A TRADE RATHER THAN HIDDEN
 *
 * If the process dies between step 1 and step 4 the row stays `claimed` forever: that enrollment is
 * NOT billed, and with `tries = 1` it is not retried. A reviewer meeting a stuck claimed row is
 * meeting a real, permanent, un-self-healing state, so the reason it is preferable has to be here
 * rather than re-derived:
 *
 *   BEFORE (bill-then-record): the same death produces an invoice with no row. Money is posted to a
 *   family's balance, the run's counts do not know about it, nothing anywhere says it happened, and
 *   the only detection is a human reading a statement. On a re-execution it becomes a SECOND charge.
 *
 *   AFTER (claim-then-bill): the same death produces a row with no invoice. Nobody is charged, the
 *   equality goes red, the run reports itself incomplete, and the stuck row NAMES the enrollment.
 *
 * A visible unknown in place of a silent double charge. The residual is honest and is named here so
 * nobody has to discover it: **there is no sweeper.** A claim stuck by a SIGKILL stays claimed until
 * a human looks — exactly the residual `ProcessBulkInvoiceRun::failed()` records for a run stranded
 * in `running`, and for the same reason (nothing in the process runs afterwards).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE RUN-LEVEL GUARD, WHICH CLAIM-THEN-BILL DOES NOT PROVIDE
 *
 * Claim-then-bill closes re-execution WITHIN one run. It does nothing about an operator pressing Run
 * twice, which creates two runs and bills everyone on the list twice. So:
 *
 *     active_run_key = IF(status IN ('pending','running'), school_id, NULL)
 *     UNIQUE (active_run_key)
 *
 * At most ONE non-terminal manual run per School, at the ENGINE. Same house pattern as
 * `finance_invoices.active_enrollment_key` (2026_08_18_100000:225) — a stored generated column that
 * is the key while the row is live and NULL otherwise, and NULLs do not collide in a MySQL unique
 * index, so any number of completed/failed runs coexist.
 *
 * NO `COLLATE utf8mb4_bin` IN THE EXPRESSION, and the omission is deliberate rather than an
 * oversight of the collation discipline the sibling migrations follow. Under the table's
 * `utf8mb4_unicode_ci` the `IN` also matches `'PENDING'`, which makes the key MORE often non-NULL,
 * i.e. more collisions, i.e. FAIL CLOSED. The collation trap in the other direction — a guard
 * silently not matching — is the one worth defending against, and it does not exist here. The status
 * domain trigger below constrains the column to the four exact lowercase values regardless.
 *
 * WHAT IT DOES **NOT** STOP, and this stays open in the brief (§4, "across runs"): two runs raised
 * SEQUENTIALLY over the same list. The first completes, its key goes NULL, the second is admitted
 * and bills everyone again. That is a deliberate act rather than an accident — the double-click and
 * the two-operator race are what this closes — and the answer to it (a confirmation naming the count
 * and total, a warning window, an explicit "yes, bill them again") is not decided here and should
 * not be decided by whoever writes the code.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY `targets` AND `rows` ARE TWO TABLES WITH NEARLY THE SAME KEY
 *
 * They are not two copies of one thing. `targets` is the INSTRUCTION — the list the operator asked
 * for, fixed at run creation; `rows` is the RECORD — what the job made of each one. The scheduled
 * run has the same split and does not need a table for the first half because its instruction is a
 * pair of coordinates and its list is COMPUTED at run time by
 * `BillableEnrollmentProvider::listForCohort()`. A manual run's list is GIVEN, so it has to be
 * stored, and storing it is what makes `target_count` a genuinely independent source for the
 * equality above rather than a tally the job kept about itself.
 *
 * THE INSTRUCTION IS IN STUDENTS AND THE RECORD RESOLVES TO EPISODES. `targets` keys on
 * `student_id` (NOT NULL) and carries `enrollment_id` NULLABLE — the resolution outcome, not a
 * precondition of being listed. `rows` carries the same pair for the same reason. Everything a
 * manual run bills is still an ENROLLMENT (`finance_invoices` keys on `student_curriculum_id` and
 * `GenerateInvoice::handle()` takes an enrollment uuid); what changed is that failing to reach one
 * is now a recorded outcome instead of an unrepresentable state.
 *
 * A NULLABLE COLUMN IN A COMPOSITE FK LEAVES THAT FK UNENFORCED FOR THE ROW, AND THAT IS MEASURED
 * RATHER THAN ASSUMED. On 8.0.43 (`information_schema.REFERENTIAL_CONSTRAINTS.MATCH_OPTION = NONE`,
 * i.e. MySQL's only mode, MATCH SIMPLE): a row with `enrollment_id` NULL is ACCEPTED; a row naming
 * ANOTHER School's enrollment is REFUSED 1452; a row naming a non-existent enrollment is REFUSED
 * 1452; and deleting a referenced enrollment is still REFUSED 1451 by RESTRICT.
 *
 * SO NO ROW IS LEFT UNGUARDED, and the reason is `student_id`: it is NOT NULL and carries its OWN
 * composite `(student_id, school_id) -> students (id, school_id)`, which was measured refusing a
 * cross-School student 1452 **on a row whose `enrollment_id` was NULL**. The School binding is
 * therefore carried by a key that can never be absent, and the enrollment FK guards every row that
 * names an enrollment. A nullable component weakens the enrollment check on exactly the rows that
 * have no enrollment to check.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE OUTCOME DOMAIN IS FOUR VALUES, NOT THE SCHEDULED RUN'S FIVE
 *
 *     claimed | billed | failed | unplaceable
 *
 * Every one of them has a producer, which is the whole test a value has to pass. The two that were
 * considered and refused:
 *
 *   `already_billed` — the scheduled run's is a CLASSIFICATION of the refusal
 *     `UNIQUE(school_id, active_enrollment_key)` produces. A supplementary invoice computes NULL for
 *     that key, so nothing ever refuses one, so nothing here could ever write this value. See the
 *     supplementary-backstop ticket: its absence is the reason this table's job claims first.
 *
 *   `sponsored` — the scheduled run excludes sponsored students because an outside body pays their
 *     TERMLY fees off platform. This feature exists partly to bill exactly those students (the C2C
 *     session bills; scholarship-and-cutover-decisions.md §4), and a mid-term charge is not covered
 *     by a scholarship at all (§11). Excluding them here would drop the very students the feature
 *     was built for.
 *
 * `unplaceable` IS PRESENT, AND IT IS THE SCHEDULED RUN'S OWN NAME FOR THIS EXACT CASE — reused
 * rather than re-invented. A selected student who resolves to no current billable enrollment is
 * recorded, not dropped, which is what brief §2 asks for. It has a producer precisely because the
 * target table is keyed on the STUDENT: keyed on the enrollment, an unresolvable student could not
 * be represented at all, and the count of what the bursar ticked would have quietly become the count
 * of what survived.
 *
 * WHAT IS STILL OUT OF REACH, named so it is not mistaken for covered: a STUDENT-LESS EPISODE.
 * `student_curricula.student_id` is nullable and such an episode is schema-legal — it is one of the
 * two shapes the scheduled run's reconciliation exists to surface — but a manual run is driven by
 * students the bursar ticked, so an episode with no student can never be one of its targets. That is
 * correct rather than a gap: this feature bills people, and there is nobody there.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * DOMAINS ARE TRIGGERS, NOT `CHECK`. Production is MySQL 5.7.23, which parses and DISCARDS `CHECK`
 * (docs/finance/check-constraints-on-mysql-5-7.md), so a `CHECK` here would be enforced locally,
 * absent on the server that holds the money, and green in both places. The two bodies below carry
 * the same three load-bearing pieces 2026_08_26_100001 documents: `COALESCE(..., 0)` so a NULL does
 * not sail through `IF NOT (...)`, `COLLATE utf8mb4_bin` so `'Billed'` is not silently a fourth
 * value every `where('outcome', 'billed')` would miss, and a MESSAGE_TEXT counted against the
 * 128-character cap (past it, `SIGNAL` itself fails with 1648 and the guard stops speaking its own
 * refusal). No apostrophes — MySQL stores a trigger body with the escape stripped and the body
 * becomes un-dumpable (`TriggerBodiesAreDumpSafeTest`).
 *
 * THE VALUE LISTS ARE LITERALS, NOT `ManualInvoiceRunOutcome::cases()`. A migration that consults a
 * live enum silently changes its own effect the day a case is added: a fresh install would get a
 * four-value trigger from this file while every already-migrated database kept three, two
 * environments diverging with nothing to say so. The consequence is the one worth having — a fourth
 * case added without its own migration is REFUSED BY THE DATABASE at insert time.
 * {@see ManualInvoiceRunOutcome} and {@see ManualInvoiceRunStatus} are imported for the docblock
 * links only; nothing below reads them.
 *
 * VERIFIED BY SHAPE, NOT BY EXIT CODE (ADR 0052). An `ALTER TABLE` that returned success is not
 * evidence that the right index exists over the right expression — a mis-scoped generated column and
 * a non-unique index are both created just as successfully. The generated column and its index are
 * read back out of `information_schema` and this migration refuses to record itself unless the
 * column is STORED over an expression naming `status`, and the index is present, UNIQUE and covers
 * exactly `(active_run_key)`. Each `CREATE TRIGGER` is read back for name, timing and event.
 */
return new class extends Migration
{
    private const RUNS = 'finance_manual_invoice_runs';

    private const LINES = 'finance_manual_invoice_run_lines';

    private const TARGETS = 'finance_manual_invoice_run_targets';

    private const ROWS = 'finance_manual_invoice_run_rows';

    private const KEY_COLUMN = 'active_run_key';

    private const KEY_INDEX = 'finance_manual_invoice_runs_active_run_unique';

    private const KEY_EXPRESSION = "IF(status IN ('pending','running'), school_id, NULL)";

    private const STATUS_STEM = 'finance_manual_invoice_runs_status_shape';

    private const OUTCOME_STEM = 'finance_manual_invoice_run_rows_outcome_shape';

    public function up(): void
    {
        $this->createRuns();
        $this->installActiveRunKey();
        $this->createLines();
        $this->createTargets();
        $this->createRows();
        $this->installDomainTriggers();
    }

    /**
     * Triggers first, then the three children, then the parent — the reverse of `up()`. Each child's
     * composite FK references the parent's `(id, school_id)` key, so the parent cannot be dropped
     * first.
     *
     * The tables are dropped outright rather than repaired: nothing here modifies an existing
     * object, so the literal inverse IS the honest rollback and both servers end in the same state.
     * The generated column and its unique index go with the table that carries them.
     */
    public function down(): void
    {
        foreach ([self::STATUS_STEM, self::OUTCOME_STEM] as $stem) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$stem.'_bi');
            DB::unprepared('DROP TRIGGER IF EXISTS '.$stem.'_bu');
        }

        Schema::dropIfExists(self::ROWS);
        Schema::dropIfExists(self::TARGETS);
        Schema::dropIfExists(self::LINES);
        Schema::dropIfExists(self::RUNS);
    }

    private function createRuns(): void
    {
        if (! Schema::hasTable(self::RUNS)) {
            Schema::create(self::RUNS, function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();

                // NO term_id AND NO class_level_id, and their absence is the reason this table
                // exists. A manual run's list spans class levels by definition; a nullable slot on
                // the scheduled run's table would have made one table mean two things.
                $table->string('status')->default('pending'); // pending|running|completed|failed

                $table->unsignedBigInteger('started_by_user_id')->nullable(); // LOOKUP, not an FK
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();

                // Why the RUN failed — per-run, never per-enrollment. An enrollment that could not
                // be billed is a row with `outcome = failed` carrying its own `reason`.
                $table->text('failure_reason')->nullable();

                // ── THE RUN'S OWN ACCOUNTING ──────────────────────────────────────────────────
                //
                //     billed_count + failed_count + unplaceable_count == target_count
                //
                // `target_count` is the size of the list WALKED — and, because the targets are keyed
                // on the STUDENT, it is exactly what the bursar ticked rather than what survived
                // resolution. The three beside it are counted from the rows PERSISTED. See the class
                // docblock — and note that `claimed_count` below is deliberately NOT a term.
                $table->unsignedInteger('target_count')->nullable();
                $table->unsignedInteger('billed_count')->nullable();
                $table->unsignedInteger('failed_count')->nullable();

                // A TERM, unlike `claimed_count`. A student the resolver could not place is a
                // finished, reported, correct outcome — nothing is unknown about them — so leaving
                // them off the left-hand side would fire the alarm on a healthy run, which is how an
                // alarm gets learned-around and then ignored (2026_08_26_100001's own reasoning for
                // `sponsored_count`, reached the same way).
                $table->unsignedInteger('unplaceable_count')->nullable();

                // THE DIAGNOSIS, NOT A TERM OF THE EQUALITY. Exactly the shortfall above, recorded
                // so a screen can say WHICH number is missing rather than only that one is. Adding
                // it to the left-hand side balances the equality on precisely the runs it exists to
                // catch — see the class docblock.
                $table->unsignedInteger('claimed_count')->nullable();

                $table->timestamps();
            });
        }

        // Parent key for the three children's composite school-integrity FKs.
        $this->addUniqueIfMissing(self::RUNS, 'finance_manual_invoice_runs_id_school_unique', '(id, school_id)');
    }

    /**
     * ONE `ALTER`, so the column and the index that constrains it are never separately present. A
     * column added first and indexed second has a window in which two pending runs are admissible,
     * and the whole value of this guard is that there is no such window.
     */
    private function installActiveRunKey(): void
    {
        if (! Schema::hasTable(self::RUNS) || Schema::hasColumn(self::RUNS, self::KEY_COLUMN)) {
            return;
        }

        DB::statement(
            'ALTER TABLE '.self::RUNS.'
                ADD COLUMN '.self::KEY_COLUMN.' BIGINT UNSIGNED
                    GENERATED ALWAYS AS ('.self::KEY_EXPRESSION.') STORED,
                ADD UNIQUE '.self::KEY_INDEX.' ('.self::KEY_COLUMN.')'
        );

        $this->assertActiveRunKeyShape();
    }

    /**
     * THE LINES THE OPERATOR TYPED — one set for the WHOLE list, not one per student.
     *
     * Money is `amount_minor` + `amount_currency` (Constitution 10 / ADRs 0002 and 0037): integer
     * minor units and an explicit ISO-4217 code, never a float and never `decimal:`. The shape CHECK
     * matching 2026_08_01_120000's ten columns is added below — a CHECK is inert on the 5.7
     * production server, which is exactly why the discipline is that `Money`'s constructor is the
     * real guard and this is the belt behind it.
     *
     * `bank_account_id` IS NOT NULLABLE HERE, and that is a narrowing of what
     * `InvoiceLineSpec::$bankAccountId` permits. S11 (`d3227c0`) made a destination REQUIRED on every
     * charge line — `GenerateInvoiceRequest::assertDestinationsChosen()` refuses with a 422 and
     * `finance_invoice_lines_destination_guard` is the authority behind it. Every line a manual run
     * writes is a charge, there is no fee item to read a default off, and there is no default to
     * invent; a run whose lines could reach the Action without one would be a run that always fails
     * at the last step, per student, having already claimed them. Refusing it at the run's own table
     * is refusing it before anything is claimed.
     */
    private function createLines(): void
    {
        if (! Schema::hasTable(self::LINES)) {
            Schema::create(self::LINES, function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
                $table->unsignedBigInteger('run_id'); // composite FK below

                $table->string('description');
                $table->bigInteger('amount_minor');
                $table->char('amount_currency', 3);
                $table->unsignedBigInteger('bank_account_id'); // composite FK below

                $table->unsignedInteger('sort_order')->default(0);

                $table->timestamps();

                // The order the operator entered them, held at the engine so two lines cannot claim
                // the same position and the invoice's line order is reproducible.
                $table->unique(['school_id', 'run_id', 'sort_order'], 'finance_manual_invoice_run_lines_school_run_sort_unique');
            });
        }

        $this->addForeignKeyIfMissing(
            self::LINES,
            'finance_manual_invoice_run_lines_run_school_foreign',
            '(run_id, school_id) REFERENCES '.self::RUNS.' (id, school_id) ON DELETE CASCADE'
        );

        $this->addForeignKeyIfMissing(
            self::LINES,
            'finance_manual_invoice_run_lines_bank_account_school_foreign',
            '(bank_account_id, school_id) REFERENCES finance_bank_accounts (id, school_id) ON DELETE RESTRICT'
        );

        $this->addCurrencyShapeCheckIfMissing(self::LINES, 'amount_currency');
    }

    /**
     * THE LIST THE OPERATOR ASKED FOR — the manual run's substitute for
     * `BillableEnrollmentProvider::listForCohort()`, which a scheduled run computes and a manual run
     * is given. `target_count` is counted from here, which is what makes it an independent source
     * for the cohort equality rather than a tally the job kept about itself.
     */
    private function createTargets(): void
    {
        if (! Schema::hasTable(self::TARGETS)) {
            Schema::create(self::TARGETS, function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
                $table->unsignedBigInteger('run_id'); // composite FK below

                // THE PERSON THE BURSAR TICKED. NOT NULL, and the key of this table — so
                // `target_count` is the number on the operator's screen and not the number that
                // survived resolution. A composite (student_id, school_id) FK, so School A's run
                // physically cannot target School B's child, whatever the caller believes.
                $table->unsignedBigInteger('student_id');

                // THE EPISODE THEY RESOLVED TO — NULLABLE, because resolution is an OUTCOME and not
                // a precondition of being listed. NULL means "no current billable enrollment", which
                // the run records as `unplaceable` rather than dropping. The composite
                // (enrollment_id, school_id) FK still refuses another School's episode on every row
                // that names one; MySQL skips the check only where the column is NULL, and the
                // student FK above is what keeps such a row bound to its School anyway.
                $table->unsignedBigInteger('enrollment_id')->nullable();
                // The wire identifier the job passes to GenerateInvoice, kept so a target can be
                // traced to the call it produced without re-reading student_curricula. NULL exactly
                // when `enrollment_id` is.
                $table->char('enrollment_uuid', 36)->nullable();

                $table->timestamps();

                // ONE TARGET PER STUDENT PER RUN. A list that named the same child twice is a list
                // that would bill them twice; the engine refuses the second entry with 1062 at the
                // moment it is written, which is before the run exists to be started.
                $table->unique(['school_id', 'run_id', 'student_id'], 'finance_manual_invoice_run_targets_school_run_student_unique');
            });
        }

        $this->addForeignKeyIfMissing(
            self::TARGETS,
            'finance_manual_invoice_run_targets_run_school_foreign',
            '(run_id, school_id) REFERENCES '.self::RUNS.' (id, school_id) ON DELETE CASCADE'
        );

        $this->addForeignKeyIfMissing(
            self::TARGETS,
            'finance_manual_invoice_run_targets_student_school_foreign',
            '(student_id, school_id) REFERENCES students (id, school_id) ON DELETE RESTRICT'
        );

        $this->addForeignKeyIfMissing(
            self::TARGETS,
            'finance_manual_invoice_run_targets_enrollment_school_foreign',
            '(enrollment_id, school_id) REFERENCES student_curricula (id, school_id) ON DELETE RESTRICT'
        );
    }

    /**
     * THE CLAIM TABLE. Structurally the scheduled run's rows table; what differs is WHEN the row is
     * written relative to the invoice, and that difference is the whole commit.
     *
     * `UNIQUE(school_id, run_id, enrollment_id)` is the same index the scheduled run has. There it
     * sits downstream of the money and its 1062 arrives after a duplicate invoice has already
     * committed. Here the row is inserted BEFORE `GenerateInvoice` is called, so the 1062 arrives
     * while there is still nothing to undo.
     *
     * IT NOW HAS A SIBLING, `UNIQUE(school_id, run_id, student_id)`, AND THE SIBLING IS THE CLAIM.
     * Nothing was removed — the enrollment index is retained exactly as it was — but the UNIT OF
     * WORK is now a target, and a target is a STUDENT. Two consequences, and both are the reason for
     * two indexes rather than a swap:
     *
     *   The student index is what the claim rests on. `enrollment_id` is nullable here (an
     *   `unplaceable` row names no episode) and NULLs DO NOT COLLIDE in a MySQL unique index, so an
     *   enrollment-keyed claim would admit any number of duplicate `unplaceable` rows for one child
     *   — a hole shaped exactly like the new case. `student_id` is NOT NULL, so the claim is refused
     *   for every outcome including that one.
     *
     *   The enrollment index still earns its place. It is the last thing standing between a
     *   resolver bug that maps two ticked students onto ONE episode and that episode being billed
     *   twice inside a single run — on a path whose invoice kind has no duplicate backstop at all.
     *   It constrains only the rows that name an episode, which is exactly the set it is about.
     */
    private function createRows(): void
    {
        if (! Schema::hasTable(self::ROWS)) {
            Schema::create(self::ROWS, function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
                $table->unsignedBigInteger('run_id'); // composite FK below

                // Mirrors the target it came from: the student is the identity and is NOT NULL,
                // the episode is the resolution outcome and is nullable.
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('enrollment_id')->nullable();
                $table->char('enrollment_uuid', 36)->nullable();

                // claimed|billed|failed|unplaceable. `claimed` is the state a row is INSERTED in and
                // the state a row is STUCK in if the process dies before step 4 — see the class
                // docblock for why that is preferable to what it replaces. NOT NULL: a nullable
                // outcome would make a claim indistinguishable from a row whose outcome write was
                // lost.
                $table->string('outcome');

                // Names the invoice this run raised, on `billed` only. NULL on `claimed` (none
                // exists yet, by construction) and on `failed` (none exists at all).
                $table->unsignedBigInteger('invoice_id')->nullable();
                // Why it failed. NULL on every other outcome.
                $table->text('reason')->nullable();

                $table->timestamps();

                // ── THE CLAIM INDEX ───────────────────────────────────────────────────────────
                // The row is written FIRST, so this refuses a re-execution's second attempt at a
                // target before any invoice exists. `created_at` is therefore the claim instant by
                // construction, which is why there is no separate `claimed_at` column to keep in
                // step with it.
                $table->unique(['school_id', 'run_id', 'student_id'], 'finance_manual_invoice_run_rows_school_run_student_unique');

                // RETAINED, AND NULL-PERMISSIVE. It constrains the rows that name an episode and
                // says nothing about the ones that do not, which is the set it is about. See the
                // method docblock for why it is kept beside the claim rather than replaced by it.
                $table->unique(['school_id', 'run_id', 'enrollment_id'], 'finance_manual_invoice_run_rows_school_run_enrollment_unique');
            });
        }

        $this->addForeignKeyIfMissing(
            self::ROWS,
            'finance_manual_invoice_run_rows_run_school_foreign',
            '(run_id, school_id) REFERENCES '.self::RUNS.' (id, school_id) ON DELETE CASCADE'
        );

        $this->addForeignKeyIfMissing(
            self::ROWS,
            'finance_manual_invoice_run_rows_student_school_foreign',
            '(student_id, school_id) REFERENCES students (id, school_id) ON DELETE RESTRICT'
        );

        $this->addForeignKeyIfMissing(
            self::ROWS,
            'finance_manual_invoice_run_rows_enrollment_school_foreign',
            '(enrollment_id, school_id) REFERENCES student_curricula (id, school_id) ON DELETE RESTRICT'
        );

        $this->addForeignKeyIfMissing(
            self::ROWS,
            'finance_manual_invoice_run_rows_invoice_school_foreign',
            '(invoice_id, school_id) REFERENCES finance_invoices (id, school_id) ON DELETE RESTRICT'
        );
    }

    /** The two enum domains, as four triggers. See the class docblock for why not `CHECK`. */
    private function installDomainTriggers(): void
    {
        // 82 characters, counted rather than eyeballed, against the 128-character MESSAGE_TEXT cap.
        $status = $this->guard(
            'NEW.status',
            ['pending', 'running', 'completed', 'failed'],
            'finance_manual_invoice_runs: status must be pending, running, completed or failed.'
        );

        // 88 characters, counted rather than eyeballed.
        $outcome = $this->guard(
            'NEW.outcome',
            ['claimed', 'billed', 'failed', 'unplaceable'],
            'finance_manual_invoice_run_rows: outcome must be claimed, billed, failed or unplaceable.'
        );

        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->installTrigger(self::RUNS, self::STATUS_STEM, $event, $status);
            $this->installTrigger(self::ROWS, self::OUTCOME_STEM, $event, $outcome);
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function guard(string $column, array $values, string $message): string
    {
        $list = "'".implode("','", $values)."'";

        return <<<SQL
            IF NOT COALESCE({$column} COLLATE utf8mb4_bin IN ({$list}), 0) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
            END IF;
            SQL;
    }

    private function installTrigger(string $table, string $stem, string $event, string $body): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $name = $stem.($event === 'INSERT' ? '_bi' : '_bu');

        // Idempotent, so the rollback/re-up leg of bin/quality-clean-db re-asserts rather than 1359.
        DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        DB::unprepared(
            "CREATE TRIGGER {$name} BEFORE {$event} ON {$table}
             FOR EACH ROW
             BEGIN
                {$body}
             END"
        );

        $this->assertTriggerShape($name, $event, $table);
    }

    /**
     * ADR 0052 — read the ALTER back rather than trusting its exit code. Three separate facts,
     * because a green on one says nothing about the others: the column is STORED and its expression
     * names `status` (a key generated over the wrong expression is created just as successfully); the
     * index exists and is UNIQUE (`NON_UNIQUE = 0` — a non-unique index over the same column
     * constrains nothing); and it covers exactly `(active_run_key)` and nothing else.
     */
    private function assertActiveRunKeyShape(): void
    {
        $column = DB::selectOne(
            'SELECT EXTRA AS extra, GENERATION_EXPRESSION AS expr
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::RUNS, self::KEY_COLUMN],
        );

        if ($column === null || ! str_contains((string) $column->extra, 'STORED GENERATED')) {
            throw new RuntimeException(
                'Refusing to record this migration: '.self::RUNS.'.'.self::KEY_COLUMN.' is not a STORED '
                .'generated column after ALTER TABLE returned success. A VIRTUAL column cannot carry a '
                .'unique index, so the one-active-run guard would be absent while the DDL reported fine.'
            );
        }

        if (! str_contains((string) $column->expr, 'status')) {
            throw new RuntimeException(
                'Refusing to record this migration: '.self::KEY_COLUMN.' is generated over an expression '
                .'that does not name `status` ['.(string) $column->expr.']. A key that does not go NULL '
                .'when a run reaches a terminal state would refuse every SECOND run this School ever has.'
            );
        }

        $index = DB::select(
            'SELECT NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq, COLUMN_NAME AS col
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            [self::RUNS, self::KEY_INDEX],
        );

        $columns = array_map(fn (object $row): string => (string) $row->col, $index);

        if ($index === [] || (int) $index[0]->non_unique !== 0 || $columns !== [self::KEY_COLUMN]) {
            throw new RuntimeException(
                'Refusing to record this migration: '.self::KEY_INDEX.' is missing, not UNIQUE, or does '
                .'not cover exactly ('.self::KEY_COLUMN.') — got ['.implode(', ', $columns).']. '
                .'An index with an extra column constrains a wider key and admits the second pending run '
                .'this guard exists to refuse.'
            );
        }
    }

    /** Read the trigger back and refuse to record the migration unless it is what CREATE claimed. */
    private function assertTriggerShape(string $name, string $event, string $table): void
    {
        $read = DB::selectOne(
            'SELECT ACTION_TIMING AS timing, EVENT_MANIPULATION AS event, EVENT_OBJECT_TABLE AS tbl
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$name],
        );

        if ($read === null) {
            throw new RuntimeException(
                "Trigger [{$name}] does not exist after CREATE TRIGGER returned success. Refusing to "
                .'record this migration as applied: the domain it claims to enforce is absent, and on '
                .'MySQL 5.7 there is no CHECK behind it.'
            );
        }

        if ($read->timing !== 'BEFORE' || $read->event !== $event || $read->tbl !== $table) {
            throw new RuntimeException(
                "Trigger [{$name}] exists with the wrong shape: got {$read->timing} {$read->event} on "
                ."{$read->tbl}, expected BEFORE {$event} on {$table}. A trigger with the right name and "
                .'the wrong timing or event fires on writes nobody guarded and misses the ones they did.'
            );
        }
    }

    private function addUniqueIfMissing(string $table, string $name, string $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD UNIQUE {$name} {$columns}");
    }

    private function addForeignKeyIfMissing(string $table, string $name, string $definition): void
    {
        if (! Schema::hasTable($table) || $this->constraintExists($table, $name)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY {$definition}");
    }

    private function addCurrencyShapeCheckIfMissing(string $table, string $column): void
    {
        $name = "{$table}_{$column}_shape";

        if (! Schema::hasTable($table) || $this->constraintExists($table, $name)) {
            return;
        }

        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$name}
             CHECK ({$column} IS NULL OR {$column} COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}\$')"
        );
    }

    private function indexExists(string $table, string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS hit FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $name],
        ) !== null;
    }

    private function constraintExists(string $table, string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS hit FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [$table, $name],
        ) !== null;
    }
};

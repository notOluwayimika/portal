<?php

use App\Finance\Enums\BulkInvoiceRunOutcome;
use App\Finance\Enums\BulkInvoiceRunStatus;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U6 commit 3 — THE RECORD A BULK INVOICE RUN WRITES. Two tables: the run, and one row per
 * enrollment the run saw.
 *
 * NO HTTP ROUTE AND NO SCREEN EXIST FOR THESE YET; commit 4 is their consumer. What exists here is
 * the job ({@see ProcessBulkInvoiceRun}) and the tests that drive it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT WAS COPIED FROM finance_opening_balance_batches / _rows (2026_08_06_100000), AND WHAT WAS NOT
 *
 * COPIED, because the two records are the same shape of thing — a long-running School-scoped job
 * with a parent summary and a child row per unit of work:
 *
 *   - parent + child, the child carrying `school_id` AND a COMPOSITE (parent_id, school_id) FK to
 *     the parent's `(id, school_id)` unique key, ON DELETE CASCADE. A row's School can only ever be
 *     its run's School, unrepresentable-when-violated at the engine rather than by discipline.
 *   - `uuid` as the route key on both, `school_id` restrictOnDelete, `$table->timestamps()`.
 *   - the actor as a LOOKUP column, not an FK — `started_by_user_id` mirrors
 *     `uploaded_by_user_id` / `finance_payments.received_by_user_id`.
 *   - COUNTS ARE NULL UNTIL THE RUN FINISHES, verbatim reasoning from the batch's control totals:
 *     "a batch that aborted mid-parse must not present a total that was never summed". A run that
 *     died in the cohort must not present a reconciliation that was never reconciled.
 *   - the parent's `(id, school_id)` unique key added by raw ALTER, named explicitly.
 *
 * NOT COPIED, each with its reason:
 *
 *   - NO `findings` JSON, on either table. The batch stages a FILE, where one row can fail several
 *     independent §2/§7 rules at once and a list is the honest shape. A run's row has exactly one
 *     outcome and at most one reason for it, so `outcome` + `reason` is the shape — a JSON column
 *     would invite a second reason nobody reads and would put the run's only failure text somewhere
 *     no index can reach.
 *   - NO MONEY COLUMNS ANYWHERE. The batch carries a control total because §1's L2 witness is an
 *     operator-typed figure nothing derived. A run derives everything it does from the fee schedule
 *     it pinned, and the amounts live on the invoices it raised; a `total_billed_minor` here would
 *     be a second, un-reconciled copy of SUM(finance_invoices.total_minor) — a money figure with no
 *     writer's discipline behind it. If commit 4 wants a total on the screen it sums the invoices.
 *   - NO IDEMPOTENCY KEY equivalent to `unique(school_id, batch_reference)`. The batch has one
 *     because re-importing a file would double-post arrears. A bulk run is deliberately RE-RUNNABLE:
 *     the invariant that stops double-billing is
 *     `unique(school_id, active_enrollment_key)` on `finance_invoices`, which is per EPISODE and
 *     therefore survives any number of runs. Refusing the second run would refuse the recovery path.
 *   - NO APPROVAL COLUMNS and therefore no maker/checker trigger. A run raises invoices; it approves
 *     nothing. Deliberately no `submitted_by*` / `decided_by*` pair, which is also what keeps these
 *     tables out of SchemaConventionsTest's derived approval-table set — correctly, since there is
 *     no second signature to enforce.
 *   - NO IMMUTABILITY TRIGGER. `finance_bulk_invoice_runs` is MUTATED by design — pending → running
 *     → completed/failed, then the counts. `finance_bulk_invoice_run_rows` is written once and never
 *     updated by any code here, but it is left mutable rather than given a `no_update` trigger,
 *     because these tables record what a job observed and hold no money; they are not in the
 *     append-only money set (finance_invoices, _invoice_lines, _payments, _payment_allocations,
 *     _ledger_transactions) that the 1.4c triggers defend. SchemaConventionsTest's append-only proof
 *     keys on a hardcoded list of those tables, so this is a deliberate absence and not one hidden
 *     by a loop that never looked.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE TWO ENUM DOMAINS ARE ENFORCED BY TRIGGERS, NOT BY `CHECK`
 *
 * Production is `md-24.webhostbox.net`, MySQL 5.7.23-23, where `CHECK` is parsed and ignored
 * (docs/finance/check-constraints-on-mysql-5-7.md; 2026_08_17_100000 converted the seven that
 * mattered). A `CHECK (status IN (...))` here would be enforced on 8.0.43 locally, absent on the
 * server, and green in both places. So the domain of {@see BulkInvoiceRunStatus}
 * and {@see BulkInvoiceRunOutcome} is held by four triggers — BEFORE INSERT and
 * BEFORE UPDATE on each table — following 2026_08_17_100000's pattern exactly, including:
 *
 *   - `COLLATE utf8mb4_bin`, load-bearing. Under the tables' `utf8mb4_unicode_ci`, `status =
 *     'failed'` also matches `'FAILED'` and `'Failed'` — values every `where('status', 'failed')`
 *     read would MISS while the guard read green.
 *   - `COALESCE(..., 0)`, load-bearing. A NULL makes `IN (...)` evaluate to NULL, `NOT NULL` is
 *     NULL, and a bare `IF NOT (...)` would let it straight through. Both columns are NOT NULL
 *     today, so this is the belt; it survives someone relaxing the column.
 *   - NO APOSTROPHE in any MESSAGE_TEXT. MySQL stores a trigger body with the escape STRIPPED, so an
 *     apostrophe leaves the body un-dumpable (TriggerBodiesAreDumpSafeTest).
 *   - EACH `CREATE TRIGGER` IS READ BACK from `information_schema.TRIGGERS` and the migration THROWS
 *     unless the name, timing, event and table are what was asked for (ADR 0052 — verify by shape,
 *     not by exit code). A mis-timed or mis-evented trigger is created just as successfully as a
 *     right one.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * EVERY DDL STATEMENT HERE IS INDIVIDUALLY RE-RUNNABLE
 *
 * MySQL commits DDL implicitly and Laravel records a migration only after `up()` RETURNS, so an
 * abort part-way leaves the schema changed and the `migrations` table disagreeing with it
 * (docs/handoff/tickets/aborted-migration-leaves-schema-changed-and-unrecorded.md). The read-backs
 * above are exactly the statements that can abort. So: `CREATE TABLE` is guarded on
 * `Schema::hasTable`, the unique key and both composite FKs are guarded on `information_schema`, and
 * every `CREATE TRIGGER` is preceded by `DROP TRIGGER IF EXISTS`. A retry after an abort proceeds
 * instead of dying on 1050 / 1061 / 1826 / 1359.
 *
 * Re-runnability is NOT atomicity, and this migration does not claim to have solved the standing
 * condition that ticket records. It claims only that the retry works.
 */
return new class extends Migration
{
    private const RUNS = 'finance_bulk_invoice_runs';

    private const ROWS = 'finance_bulk_invoice_run_rows';

    public function up(): void
    {
        $this->createRuns();
        $this->createRows();
        $this->installDomainTriggers();
    }

    /**
     * Triggers first, then the child, then the parent — the reverse of `up()`. The child's composite
     * FK references the parent's `(id, school_id)` key, so the parent cannot be dropped first.
     *
     * The tables are dropped outright rather than left behind: unlike the `CHECK`-to-trigger
     * migration, nothing here is a repair of an existing object, so the literal inverse IS the
     * honest rollback and both servers end in the same state.
     */
    public function down(): void
    {
        foreach ([self::RUNS => 'status_shape', self::ROWS => 'outcome_shape'] as $table => $stem) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName($table.'_'.$stem, 'INSERT'));
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName($table.'_'.$stem, 'UPDATE'));
        }

        Schema::dropIfExists(self::ROWS);
        Schema::dropIfExists(self::RUNS);
    }

    private function createRuns(): void
    {
        if (! Schema::hasTable(self::RUNS)) {
            Schema::create(self::RUNS, function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();

                // THE COORDINATES THE RUN NAMED. Real FKs, unlike the batch's `student_id` lookup:
                // a run at a term or class level that does not exist is not a reportable outcome,
                // it is a caller defect, and there is no screen that could act on it.
                $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();
                $table->foreignId('class_level_id')->constrained('class_levels')->restrictOnDelete();

                // THE VERSION THE RUN PINNED — which price list it READ, not which one it succeeded
                // with: a schedule the mapper refused is recorded here too, because on a failed run
                // that is the most useful fact there is. NULL only when no active schedule existed at
                // the coordinates, i.e. when there was nothing to pin. Composite FK below.
                $table->unsignedBigInteger('fee_schedule_id')->nullable();

                $table->string('status')->default('pending'); // pending|running|completed|failed

                $table->unsignedBigInteger('started_by_user_id')->nullable(); // LOOKUP, not an FK
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();

                // Why the RUN failed — per-run, never per-student. A student who cannot be billed is
                // a row with `outcome = failed` and its own `reason`; this column stays NULL.
                $table->text('failure_reason')->nullable();

                // THE RECONCILIATION, written when the run finishes and NULL until then. The four
                // outcome counts are re-derived from the rows actually persisted, not from what the
                // job believed it did, so a row that failed to insert shows up as a discrepancy
                // rather than being papered over by an in-memory tally.
                $table->unsignedInteger('billed_count')->nullable();
                $table->unsignedInteger('already_billed_count')->nullable();
                $table->unsignedInteger('failed_count')->nullable();
                $table->unsignedInteger('unplaceable_count')->nullable();

                // The independently-counted billable population of the School at run time
                // (BillableEnrollmentProvider::countBillableForSchool), and what is left of it after
                // every row above is subtracted. THE ANSWER TO THE TICKET: "how many billable
                // students were neither billed nor flagged".
                $table->unsignedInteger('billable_count')->nullable();

                // SIGNED, on purpose. The subtraction cannot go negative today — the cohort and the
                // unplaceable list are both subsets of the population — so a negative value would
                // mean that stopped being true. An unsigned column would wrap it into a colossal
                // positive and hide exactly the fact worth seeing.
                $table->integer('unaccounted_count')->nullable();

                $table->timestamps();
            });
        }

        // Parent key for the rows' composite school-integrity FK.
        $this->addUniqueIfMissing(self::RUNS, 'finance_bulk_invoice_runs_id_school_unique', '(id, school_id)');

        $this->addForeignKeyIfMissing(
            self::RUNS,
            'finance_bulk_invoice_runs_fee_schedule_school_foreign',
            '(fee_schedule_id, school_id) REFERENCES finance_fee_schedules (id, school_id) ON DELETE RESTRICT'
        );
    }

    private function createRows(): void
    {
        if (! Schema::hasTable(self::ROWS)) {
            Schema::create(self::ROWS, function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
                $table->unsignedBigInteger('run_id'); // composite FK below

                // THE EPISODE. A composite (enrollment_id, school_id) FK, the same shape
                // finance_invoices carries (finance_invoices_episode_school_foreign,
                // 2026_07_19_130001) — so a run of School A physically cannot record a row against
                // School B's episode, whatever the job believes.
                $table->unsignedBigInteger('enrollment_id');
                // The wire identifier the job actually passed to GenerateInvoice, kept so a row can
                // be traced to the call that produced it without re-reading student_curricula.
                $table->char('enrollment_uuid', 36);
                // LOOKUP, and NULLABLE because the episode's student_id is: the column is nullable
                // and MySQL MATCH SIMPLE skips the composite FK when a component is NULL, so a
                // student-less episode is schema-legal (the ticket names it as the shape no
                // coordinate reasoning reaches).
                $table->unsignedBigInteger('student_id')->nullable();

                $table->string('outcome'); // billed|already_billed|failed|unplaceable

                // Names the invoice, for `billed` and for `already_billed` alike. Composite FK below.
                $table->unsignedBigInteger('invoice_id')->nullable();
                // Why it failed. NULL on every other outcome.
                $table->text('reason')->nullable();

                $table->timestamps();

                // ONE ROW PER ENROLLMENT PER RUN, at the engine. A retry inside a single run — or a
                // cohort read that returned a student twice — is refused with 1062 rather than
                // silently double-counted into the reconciliation.
                $table->unique(['school_id', 'run_id', 'enrollment_id'], 'finance_bulk_invoice_run_rows_school_run_enrollment_unique');
            });
        }

        $this->addForeignKeyIfMissing(
            self::ROWS,
            'finance_bulk_invoice_run_rows_run_school_foreign',
            '(run_id, school_id) REFERENCES finance_bulk_invoice_runs (id, school_id) ON DELETE CASCADE'
        );

        $this->addForeignKeyIfMissing(
            self::ROWS,
            'finance_bulk_invoice_run_rows_enrollment_school_foreign',
            '(enrollment_id, school_id) REFERENCES student_curricula (id, school_id) ON DELETE RESTRICT'
        );

        $this->addForeignKeyIfMissing(
            self::ROWS,
            'finance_bulk_invoice_run_rows_invoice_school_foreign',
            '(invoice_id, school_id) REFERENCES finance_invoices (id, school_id) ON DELETE RESTRICT'
        );
    }

    /**
     * The two enum domains, as four triggers. See the class docblock for why not `CHECK`, and why
     * `COLLATE` and `COALESCE` are both load-bearing rather than decoration.
     */
    private function installDomainTriggers(): void
    {
        $runs = <<<'SQL'
            IF NOT COALESCE(NEW.status COLLATE utf8mb4_bin IN ('pending','running','completed','failed'), 0) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'finance_bulk_invoice_runs: status must be pending, running, completed or failed.';
            END IF;
            SQL;

        $rows = <<<'SQL'
            IF NOT COALESCE(NEW.outcome COLLATE utf8mb4_bin IN ('billed','already_billed','failed','unplaceable'), 0) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'finance_bulk_invoice_run_rows: outcome must be billed, already_billed, failed or unplaceable.';
            END IF;
            SQL;

        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->installTrigger(self::RUNS, self::RUNS.'_status_shape', $event, $runs);
            $this->installTrigger(self::ROWS, self::ROWS.'_outcome_shape', $event, $rows);
        }
    }

    /**
     * `{stem}_bi` / `{stem}_bu`, following `guardian_student_same_school_bi` and
     * 2026_08_17_100000. The longest name this produces is 46 characters, under MySQL's 64-char cap.
     */
    private function triggerName(string $stem, string $event): string
    {
        return $stem.($event === 'INSERT' ? '_bi' : '_bu');
    }

    private function installTrigger(string $table, string $stem, string $event, string $body): void
    {
        $name = $this->triggerName($stem, $event);

        DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        DB::unprepared(
            "CREATE TRIGGER {$name} BEFORE {$event} ON {$table}
             FOR EACH ROW
             BEGIN
                {$body}
             END"
        );

        $this->assertTriggerShape($name, $table, $event);
    }

    /**
     * ADR 0052. Read the trigger back and refuse to record the migration unless it is what `CREATE`
     * claimed — a trigger with the right name and the wrong timing or event fires on writes nobody
     * guarded and misses the ones they did.
     */
    private function assertTriggerShape(string $name, string $table, string $event): void
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
                .'record this migration as applied: the enum domain it claims to hold is unenforced, '
                .'and on MySQL 5.7 there is no CHECK behind it.'
            );
        }

        if ($read->timing !== 'BEFORE' || $read->event !== $event || $read->tbl !== $table) {
            throw new RuntimeException(
                "Trigger [{$name}] exists with the wrong shape: got {$read->timing} {$read->event} on "
                ."{$read->tbl}, expected BEFORE {$event} on {$table}."
            );
        }
    }

    /** Idempotent unique key — 1061 on a retry otherwise. */
    private function addUniqueIfMissing(string $table, string $name, string $columns): void
    {
        $exists = (int) DB::scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $name],
        );

        if ($exists === 0) {
            DB::statement("ALTER TABLE {$table} ADD UNIQUE {$name} {$columns}");
        }
    }

    /** Idempotent foreign key — 1826 (duplicate constraint name) on a retry otherwise. */
    private function addForeignKeyIfMissing(string $table, string $name, string $definition): void
    {
        $exists = (int) DB::scalar(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $name, 'FOREIGN KEY'],
        );

        if ($exists === 0) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY {$definition}");
        }
    }
};

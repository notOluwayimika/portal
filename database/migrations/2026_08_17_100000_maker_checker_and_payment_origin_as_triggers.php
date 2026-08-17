<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEVEN `CHECK` CONSTRAINTS BECOME TRIGGERS, BECAUSE PRODUCTION IGNORES `CHECK`.
 *
 * Production is `md-24.webhostbox.net`, **MySQL 5.7.23-23**. Local is `Oregs-2.local`, **MySQL
 * 8.0.43**. Both readings were taken 2026-08-17 by the project lead, neither is inferred. MySQL
 * enforces `CHECK` only from **8.0.16**; before that the clause is, in MySQL's own words, "parsed and
 * ignored" — accepted, absent from `SHOW CREATE TABLE`, never evaluated. So every `CHECK` in this
 * repository is enforced locally and silently absent on the server that holds the money. The full
 * audit of all of them, and the reasoning for triggering exactly these seven and documenting the
 * rest, is `docs/finance/check-constraints-on-mysql-5-7.md`, which ships with this migration.
 *
 * `TRIGGER` + `SIGNAL SQLSTATE '45000'` is MySQL 5.5-era and works on both servers. It is not a
 * workaround: it is the mechanism this schema already uses in more than twenty places
 * (`finance_ledger_transactions_no_update`, `finance_invoice_lines_reduction_guard`,
 * `guardian_student_same_school_bi`, …). This migration makes the two servers agree.
 *
 * THE SEVEN, AND WHY THESE:
 *
 *   1-6. maker ≠ checker on the six approval documents — `subject_result_statuses`,
 *        `finance_credit_notes`, `finance_void_requests`, `finance_discount_policy_changes`,
 *        `finance_fee_schedule_changes`, and `finance_opening_balance_batches` (which names the same
 *        fact `submitted_by_user_id` / `decided_by_user_id`). Six Policies, ten Actions and
 *        `DutySeparation` all refuse self-approval through the application, so what the missing
 *        `CHECK` costs is the fourth line — a write that never passes through the application at
 *        all: a SQL console, a future job, a bulk correction, a restored dump. That is exactly the
 *        case the constraint was written for (`VoidRequestPolicy:20` says so in as many words), and
 *        it is the audit-facing control on the whole approval architecture.
 *
 *   7.   the `finance_payments` origin/bank-account pairing. This one has no second layer at all —
 *        `Payment::ORIGIN_PORTAL` / `ORIGIN_MIGRATED` and `PostOpeningBalanceBatch:254` writing the
 *        literal `'migrated'` are a convention, not a guard. It is also the most time-sensitive of
 *        the seven: the Brookstone cutover writes migrated payments in bulk, at volume, on the
 *        server where the constraint does not exist.
 *
 * RULE 7 DROPS **TWO** CONSTRAINTS AND INSTALLS **ONE** PREDICATE. `finance_payments_origin_shape`
 * (`origin IN ('portal','migrated')`) is subsumed by `finance_payments_bank_account_origin_shape`
 * (the pairing): an origin outside the pair fails BOTH arms of the pairing and is refused by it
 * alone. Keeping a separate domain trigger would be a second object firing on every payment write to
 * re-decide something the pairing already decided.
 *
 * `COALESCE(…, 0)` IS LOAD-BEARING IN RULE 7 AND IS NOT DECORATION. A `NULL` origin makes both arms
 * `NULL`, `NULL OR NULL` is `NULL`, and `NOT NULL` is `NULL` — which is not true, so a bare
 * `IF NOT (…)` would let a NULL origin straight through. (`origin` is `NOT NULL` today, so this is
 * the belt, not the braces; it costs nothing and it survives someone relaxing the column.) The
 * `CHECK` did not need it because SQL treats an unknown `CHECK` result as satisfied — the same
 * three-valued logic, arriving at the opposite default. This is the one place where a faithful
 * translation of the clause would have been a behaviour change.
 *
 * `COLLATE utf8mb4_bin` IS ALSO LOAD-BEARING, and is carried over from the `CHECK` verbatim. Under
 * the table's default `utf8mb4_unicode_ci`, `origin = 'portal'` also matches `'PORTAL'` and
 * `'Portal'` — a case variant that every `origin = 'migrated'` report filter would ALSO match, so
 * the guard would read green while admitting values nobody wrote a filter for. Same reasoning as
 * `2026_08_01_120000_add_currency_shape_checks.php:24` and `2026_08_07_110000:49`.
 *
 * ORDERING, MEASURED ON 8.0.43 RATHER THAN ASSUMED (a scratch table with a `CHECK` and a
 * `BEFORE INSERT` trigger both violated by the same row):
 *
 *     row violating BOTH  → 1644, the TRIGGER's message.  The BEFORE trigger fires FIRST; the CHECK
 *                                                          is evaluated after every BEFORE trigger.
 *     two BEFORE INSERT triggers, no FOLLOWS/PRECEDES → ACTION_ORDER = creation order, first-created
 *                                                        fires first.
 *
 * Both halves matter here. Five of the seven tables ALREADY carry a `BEFORE UPDATE` trigger
 * (`finance_credit_notes_update_guard`, `finance_void_requests_update_guard`,
 * `finance_discount_policy_changes_update_guard`, `finance_fee_schedule_changes_update_guard`,
 * `finance_opening_balance_batches_no_unpost`, plus `finance_payments_no_update`), and
 * `finance_credit_notes` also carries a `BEFORE INSERT` one. Multiple triggers per table with the
 * same timing and event are legal from **5.7.2**, so these are ADDED rather than folded into the
 * existing bodies — a table's existing guard keeps its own name, its own message and its own tests.
 *
 * The ordering conclusion is that ADDING IS POSITIONALLY EQUIVALENT TO THE `CHECK` IT REPLACES. The
 * new triggers are created last, so they fire last among the BEFORE triggers; a `CHECK` is evaluated
 * after ALL BEFORE triggers, so it was already last. Every row that reaches the `CHECK` today
 * reaches the new trigger, and every row refused today by an earlier guard is still refused by that
 * same earlier guard, with that same guard's code and message. No test that asserts another guard's
 * refusal changes meaning.
 *
 * ONE CONSEQUENCE OF THAT WORTH NAMING: on `finance_payments`, `finance_payments_no_update`
 * (`ACTION_ORDER = 1`, append-only) signals on EVERY update, so the new `BEFORE UPDATE` trigger there
 * is unreachable — exactly as the `CHECK` it replaces already was (`PaymentProvenanceTest:144-154`
 * records the same fact). It is created anyway, so the pairing does not quietly become
 * insert-only if that table is ever given an update path.
 *
 * VERIFIED BY SHAPE, NOT BY EXIT CODE (ADR 0052). `CREATE TRIGGER` returning success is not evidence
 * that the right trigger exists — a mis-named, mis-timed or mis-evented trigger is created just as
 * successfully. So after each `CREATE`, `information_schema.TRIGGERS` is read back and the migration
 * throws unless the trigger is there with the expected `ACTION_TIMING` and `EVENT_MANIPULATION`,
 * which leaves it unrecorded rather than recording a green that means nothing.
 *
 * BOTH GUARDS ARE LOAD-BEARING, same reason as `2026_08_13_100000`: skip a table that does not exist
 * AND skip a column that does not exist. `brookstone_portal_db` is behind on migrations, and a
 * half-applied, unrecorded migration — the run that died on `1054 Unknown column` after an earlier
 * statement had already committed — is the failure mode this whole change exists to avoid. On a
 * correctly-ordered run neither guard fires; both are for the environment that is mid-catch-up,
 * which is precisely where a release breaks.
 *
 * THE DROP IS GUARDED because `DROP CHECK` is 8.0.16 syntax and the constraint does not exist on
 * 5.7: 8.0.43 rejects `DROP CHECK … IF EXISTS` (1064) and a blind drop of an absent constraint 1091s
 * and aborts. `information_schema.CHECK_CONSTRAINTS` has no `TABLE_NAME`, so the lookup goes through
 * `TABLE_CONSTRAINTS` — the guard shape is copied from the `down()` of
 * `2026_08_07_110000_add_provenance_to_finance_payments.php:119`. On production it returns 0 and the
 * drop is skipped; the `ALTER` is never issued and the version difference never surfaces.
 *
 * NO EXISTING ROW VIOLATES ANY OF THE SEVEN. All twelve violation counts (six maker≠checker, plus
 * the pairing's arms) were read on production on 2026-08-17 and every one is 0. A trigger only
 * inspects rows being written, so a legacy violator would not have blocked this migration — it would
 * have blocked the next UPDATE of that row instead, which is worse. The counts were taken for that
 * reason.
 */
return new class extends Migration
{
    /**
     * The six approval documents. `finance_opening_balance_batches` names the same fact with
     * different columns, which is why this is a table of columns rather than a list of tables.
     *
     * Constraint names are read out of the migrations that created them, not re-derived from the
     * table name: `2026_07_21_210000:39`, `2026_07_25_120000:62`, `2026_07_25_140000:51`,
     * `2026_07_26_140001:62`, `2026_07_28_120000:63`, `2026_08_09_100000:74`.
     *
     * @var list<array{table: string, maker: string, checker: string, check: string}>
     */
    private const MAKER_CHECKER = [
        [
            'table' => 'subject_result_statuses',
            'maker' => 'submitted_by',
            'checker' => 'decided_by',
            'check' => 'subject_result_statuses_maker_ne_checker',
        ],
        [
            'table' => 'finance_credit_notes',
            'maker' => 'submitted_by',
            'checker' => 'decided_by',
            'check' => 'finance_credit_notes_maker_ne_checker',
        ],
        [
            'table' => 'finance_void_requests',
            'maker' => 'submitted_by',
            'checker' => 'decided_by',
            'check' => 'finance_void_requests_maker_ne_checker',
        ],
        [
            'table' => 'finance_discount_policy_changes',
            'maker' => 'submitted_by',
            'checker' => 'decided_by',
            'check' => 'finance_discount_policy_changes_maker_ne_checker',
        ],
        [
            'table' => 'finance_fee_schedule_changes',
            'maker' => 'submitted_by',
            'checker' => 'decided_by',
            'check' => 'finance_fee_schedule_changes_maker_ne_checker',
        ],
        [
            'table' => 'finance_opening_balance_batches',
            'maker' => 'submitted_by_user_id',
            'checker' => 'decided_by_user_id',
            'check' => 'finance_opening_balance_batches_maker_ne_checker',
        ],
    ];

    private const PAYMENTS_TABLE = 'finance_payments';

    /**
     * Both are dropped and ONE trigger predicate replaces them — see the docblock.
     *
     * @var list<string>
     */
    private const PAYMENTS_CHECKS = [
        'finance_payments_origin_shape',
        'finance_payments_bank_account_origin_shape',
    ];

    private const PAYMENTS_TRIGGER_STEM = 'finance_payments_origin_pairing';

    public function up(): void
    {
        foreach (self::MAKER_CHECKER as $rule) {
            $this->applyMakerChecker($rule['table'], $rule['maker'], $rule['checker'], $rule['check']);
        }

        $this->applyPaymentOriginPairing();
    }

    /**
     * DELIBERATELY DOES NOT RESTORE THE `CHECK`s, and that is the whole decision rather than an
     * omission. A restore is REAL on 8.0.43 and a SILENT NO-OP on 5.7.23 — MySQL parses and ignores
     * the clause — so a `down()` that restores means two different things on the two servers: local
     * rolls back to an enforced constraint, production rolls back to nothing at all while reporting
     * success. The asymmetry this migration exists to remove would be reintroduced by its own
     * rollback, in the one direction nobody would think to check. Dropping the triggers and stopping
     * leaves BOTH servers in the same state (unenforced), which is honest, and it is the state
     * production has been in throughout.
     *
     * Precedent for a `down()` that is not the literal inverse: `2026_08_02_100000`,
     * `2026_08_03_100000` and `2026_08_13_100000`. Roll forward with a new named migration if the
     * `CHECK`s are ever wanted back — and note that on an 8.0 server the `ALTER` will be REFUSED
     * unless the existing data already satisfies the clause.
     *
     * Consequence for the rollback/re-up leg of `bin/quality-clean-db`: `down()` leaves the tables
     * trigger-free and `up()` is idempotent (`DROP TRIGGER IF EXISTS` before each `CREATE`, and the
     * `CHECK` lookup skips what is already gone), so a re-up re-asserts every trigger and
     * re-verifies all fourteen by shape.
     */
    public function down(): void
    {
        foreach (self::MAKER_CHECKER as $rule) {
            if (! Schema::hasTable($rule['table'])) {
                continue;
            }

            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName($rule['table'].'_maker_ne_checker', 'INSERT'));
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName($rule['table'].'_maker_ne_checker', 'UPDATE'));
        }

        if (Schema::hasTable(self::PAYMENTS_TABLE)) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName(self::PAYMENTS_TRIGGER_STEM, 'INSERT'));
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->triggerName(self::PAYMENTS_TRIGGER_STEM, 'UPDATE'));
        }
    }

    /**
     * One approval document: drop its `CHECK` if present, install the same predicate as a
     * BEFORE INSERT and a BEFORE UPDATE trigger, and read both back.
     */
    private function applyMakerChecker(string $table, string $maker, string $checker, string $check): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $maker) || ! Schema::hasColumn($table, $checker)) {
            return;
        }

        $this->dropCheckIfPresent($table, $check);

        // The exact negation of the clause being replaced —
        // `submitted_by IS NULL OR decided_by IS NULL OR submitted_by <> decided_by`. A NULL on
        // either side is "unknown maker" or "not yet decided", which is not evidence of a violation;
        // the Policy layer agrees (`MakerCheckerSeparationTest`'s NULL-case test pins both).
        //
        // Both columns are integer FKs to `users.id`, so there is no collation dimension here — the
        // `COLLATE` clause rule 7 needs does not apply and is deliberately absent.
        $body = <<<SQL
            IF NEW.{$maker} IS NOT NULL AND NEW.{$checker} IS NOT NULL AND NEW.{$maker} = NEW.{$checker} THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table}: the checker must not be the maker.';
            END IF;
            SQL;

        $this->installTrigger($table, $table.'_maker_ne_checker', 'INSERT', $body);
        $this->installTrigger($table, $table.'_maker_ne_checker', 'UPDATE', $body);
    }

    /**
     * Rule 7 — the origin/bank-account pairing, replacing BOTH payment `CHECK`s with one predicate.
     */
    private function applyPaymentOriginPairing(): void
    {
        if (! Schema::hasTable(self::PAYMENTS_TABLE)
            || ! Schema::hasColumn(self::PAYMENTS_TABLE, 'origin')
            || ! Schema::hasColumn(self::PAYMENTS_TABLE, 'bank_account_id')) {
            return;
        }

        foreach (self::PAYMENTS_CHECKS as $check) {
            $this->dropCheckIfPresent(self::PAYMENTS_TABLE, $check);
        }

        // COALESCE and COLLATE are both explained in the class docblock; neither is stylistic.
        // MESSAGE_TEXT is capped at 128 characters by MySQL and silently truncated past it — the
        // sentence below is 97, counted rather than eyeballed.
        $body = <<<'SQL'
            IF NOT COALESCE(
                   (NEW.origin COLLATE utf8mb4_bin = 'portal'   AND NEW.bank_account_id IS NOT NULL)
                OR (NEW.origin COLLATE utf8mb4_bin = 'migrated' AND NEW.bank_account_id IS NULL), 0) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'finance_payments: a portal payment needs a bank account and a migrated payment must not have one.';
            END IF;
            SQL;

        $this->installTrigger(self::PAYMENTS_TABLE, self::PAYMENTS_TRIGGER_STEM, 'INSERT', $body);
        $this->installTrigger(self::PAYMENTS_TABLE, self::PAYMENTS_TRIGGER_STEM, 'UPDATE', $body);
    }

    /**
     * `{stem}_bi` / `{stem}_bu`, following `guardian_student_same_school_bi` / `_bu`
     * (`2026_07_16_000003`). The longest name this produces is 51 characters, under MySQL's 64-char
     * identifier cap.
     */
    private function triggerName(string $stem, string $event): string
    {
        return $stem.($event === 'INSERT' ? '_bi' : '_bu');
    }

    /**
     * Create one trigger idempotently, then PROVE it is there — name, timing and event — from
     * `information_schema`. ADR 0052: a statement that returned success is not evidence of a shape.
     */
    private function installTrigger(string $table, string $stem, string $event, string $body): void
    {
        $name = $this->triggerName($stem, $event);

        // Idempotent, so the rollback/re-up leg of bin/quality-clean-db re-asserts rather than 1359s
        // on an existing trigger.
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
     * Read the trigger back and refuse to record the migration unless it is what `CREATE` claimed.
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
                .'record this migration as applied: the guard it claims to install is absent, and on '
                .'5.7 there is no CHECK behind it.'
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

    /**
     * Guard shape from `2026_08_07_110000_add_provenance_to_finance_payments.php:119`. Returns 0 on
     * 5.7 — where the constraint was parsed and discarded and there is nothing to drop — so the
     * 8.0.16-only `DROP CHECK` is never issued there.
     */
    private function dropCheckIfPresent(string $table, string $check): void
    {
        $exists = (int) DB::scalar(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $check, 'CHECK'],
        );

        if ($exists > 0) {
            DB::statement("ALTER TABLE `{$table}` DROP CHECK {$check}");
        }
    }
};

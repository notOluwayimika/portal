<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §9 step 4a — the FILE FORMAT half of commit 4 (docs/handoff/opening-balance-import-spec.md Rev 4).
 * It realigns the STAGING tables onto R5's balance-forward, one-row-per-(student × fee type) file.
 * Nothing here posts, and nothing here gates: the posting Action is 4b, the approval gate is 4c.
 *
 * THIS IS NOT A REWRITE OF LIVE DATA, and a reviewer should not read it as one. Every fact in that
 * sentence is checkable:
 *
 *   - `finance_opening_balance_rows` is CASCADE-deleted from its batch by
 *     `finance_opening_balance_rows_batch_school_foreign`
 *     (2026_08_06_100000_create_finance_opening_balance_tables.php:156-161) — it is scratch by
 *     construction.
 *   - NEITHER staging table carries an immutability trigger. They are absent from
 *     SchemaConventionsTest's trigger list on purpose; only the money tables are append-only.
 *   - NOTHING HAS EVER POSTED FROM THEM. The posting Action does not exist in this repository —
 *     `finance:import-opening-balances` refuses without `--dry-run`
 *     (app/Finance/Console/ImportOpeningBalances.php:86-90) and that refusal is unchanged by 4a.
 *
 * So there are no rows worth keeping and no data migration to design. The staged rows ARE deleted
 * here, deliberately: they were parsed under a file format (Rev 2/3's four money columns) that R5
 * withdrew, they cannot satisfy the new NOT NULL `fee_type_label` or the new key, and a row staged
 * against a dead format is a false record of what a file said. Both tables were empty on the local
 * production copy when this was written (verified: 0 batches, 0 rows), so on that database the
 * delete is a no-op; it is written for the environments where it may not be.
 *
 * WHAT CHANGES, and why each one:
 *
 *  1. `fee_type_label` (NOT NULL) and the per-fee-type `balance` pair arrive. R5's file carries one
 *     signed balance per (student × fee type); `balance` is SIGNED BY DESIGN — positive is owed,
 *     negative is credit (R8 decides the instrument from the sign) — so it gets NO non-negative
 *     constraint. One would reject every student who is in credit.
 *  2. `student_total_balance` arrives. It is L1's per-row witness (§1) and R12 makes it a required
 *     column: without a stated total the checksum degrades to "the lines sum to themselves", which is
 *     true of any arithmetic whatsoever and therefore detects nothing.
 *  3. The key becomes unique(school_id, batch_id, admission_number, fee_type_label). The old
 *     unique(school_id, batch_id, admission_number) permitted exactly one row per student per batch,
 *     which is one row per student — the shape R5 replaced (R9).
 *  4. The four Rev 2/3 money pairs, the `expected_billed` pair, the batches' three total_* pairs and
 *     `rows.last_payment_date` are RETIRED, the money ones with their CHECK constraints. R5 withdrew
 *     the file those columns came from and §5's comparison went with it; R12 froze the format at six
 *     columns and `last_payment_date` is not among them. A column no rule writes is the decoration §7
 *     refuses, and it is the same reasoning in both directions.
 *  5. `batches.control_total_minor` + `_currency` arrive — §1's L2 witness, recorded on EVERY run.
 *     It is the one figure here that no code derived: a human read it off WCBS's own report and
 *     typed it (§12 decision 2), which is the only reason L2 checks anything L1 does not. An
 *     attestation nobody stored cannot be reviewed, and §11 asks for exactly this to be kept with
 *     the go/no-go.
 *
 * §12 DECISION 3 — `fee_type_label` NORMALISATION, CLOSED HERE: 'Tuition' and 'tuition' are THE SAME
 * FEE TYPE. The column is utf8mb4_unicode_ci (verified against information_schema, not assumed), so
 * the new key collides them at the engine whatever PHP believes. That is the wanted behaviour — a
 * WCBS export that spells one fee type two ways has two lines for one fee type, which is an extract
 * defect (§7) — so the decision is to KEEP the collision and make the in-PHP duplicate detection
 * AGREE with the index rather than disagree with it: ImportOpeningBalances::normaliseLabel() folds
 * case before comparing. The residual is stated where it lives, in that method's docblock: a ci
 * collation also folds accents, and PHP's fold does not, so an accent-only pair is caught by the
 * index rather than by the in-PHP pass.
 *
 * THE NEW *_currency COLUMNS carry the same `^[A-Z]{3}$` CHECK the surviving eight do
 * (2026_08_01_120000_add_currency_shape_checks.php). `COLLATE utf8mb4_bin` is load-bearing: under the
 * table's default utf8mb4_unicode_ci, 'ngn' matches the pattern and the CHECK is a false all-clear.
 *
 * NOT TOUCHED HERE, and named so the omissions are visible rather than discovered:
 *   - `batches.term_id` — §9's OPEN decision (nullable, or repurposed to name the term being closed
 *     out) is DEFERRED, not overlooked. §9 assigns it to "commit 4's migration", which is this file,
 *     and it is left open on the merits: the column's MEANING cannot be settled until posting exists
 *     to say what a batch's term is for. 4b/4c closes it, and §9 now records that deferral.
 */
return new class extends Migration
{
    /**
     * The CHECK constraints on the retiring *_currency columns. MySQL refuses to drop a column a
     * CHECK still names, so these come off first.
     *
     * @var list<array{0:string,1:string}>
     */
    private const RETIRED_CHECKS = [
        ['finance_opening_balance_batches', 'ob_batches_total_prior_arrears_currency_shape'],
        ['finance_opening_balance_batches', 'ob_batches_total_paid_to_date_currency_shape'],
        ['finance_opening_balance_batches', 'ob_batches_total_wcbs_billed_currency_shape'],
        ['finance_opening_balance_rows', 'ob_rows_prior_arrears_currency_shape'],
        ['finance_opening_balance_rows', 'ob_rows_wcbs_billed_total_currency_shape'],
        ['finance_opening_balance_rows', 'ob_rows_paid_to_date_currency_shape'],
        ['finance_opening_balance_rows', 'ob_rows_wcbs_total_balance_currency_shape'],
        ['finance_opening_balance_rows', 'ob_rows_expected_billed_currency_shape'],
    ];

    /**
     * The new *_currency columns and their CHECK names.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private const NEW_CHECKS = [
        ['finance_opening_balance_rows', 'balance_currency', 'ob_rows_balance_currency_shape'],
        ['finance_opening_balance_rows', 'student_total_balance_currency', 'ob_rows_student_total_balance_currency_shape'],
        ['finance_opening_balance_batches', 'control_total_currency', 'ob_batches_control_total_currency_shape'],
    ];

    /** The retiring money column pairs, by table. */
    private const RETIRED_COLUMNS = [
        'finance_opening_balance_batches' => [
            'total_prior_arrears_minor', 'total_prior_arrears_currency',
            'total_paid_to_date_minor', 'total_paid_to_date_currency',
            'total_wcbs_billed_minor', 'total_wcbs_billed_currency',
        ],
        'finance_opening_balance_rows' => [
            'prior_arrears_minor', 'prior_arrears_currency',
            'wcbs_billed_total_minor', 'wcbs_billed_total_currency',
            'paid_to_date_minor', 'paid_to_date_currency',
            'wcbs_total_balance_minor', 'wcbs_total_balance_currency',
            'expected_billed_minor', 'expected_billed_currency',
            // Not a money pair, and retired for the same reason they are: R12 froze the file at six
            // columns and `last_payment_date` is not among them, so nothing writes it any more.
            'last_payment_date',
        ],
    ];

    private const OLD_KEY = 'ob_rows_school_batch_admission_unique';

    private const NEW_KEY = 'ob_rows_school_batch_admission_fee_type_unique';

    public function up(): void
    {
        // Rows first, then batches — the CASCADE would take the rows anyway, but an explicit delete
        // says what is happening rather than leaving it to a constraint the reader has to look up.
        DB::table('finance_opening_balance_rows')->delete();
        DB::table('finance_opening_balance_batches')->delete();

        foreach (self::RETIRED_CHECKS as [$table, $name]) {
            $this->dropCheckIfPresent($table, $name);
        }

        Schema::table('finance_opening_balance_rows', function (Blueprint $table) {
            // NOT NULL for an ingested row: every line of R5's file names its fee type, and a line
            // that does not is an extract defect the validator rejects rather than stages as absent.
            $table->string('fee_type_label')->after('wcbs_student_ref');

            // SIGNED. Positive is owed, negative is credit — R8 reads the instrument off the sign.
            // Nullable for the same reason every other staged amount is: §2's "blank ≠ zero" means a
            // blank or unparseable cell stages as ABSENT and is rejected with a named finding, never
            // coerced to a zero the file did not state.
            $table->bigInteger('balance_minor')->nullable()->after('fee_type_label');
            $table->char('balance_currency', 3)->nullable()->after('balance_minor');

            // L1's per-row witness (§1): the student's STATED total, repeated identically on every
            // row of that student's group. Nullable for the same "blank ≠ zero" reason.
            $table->bigInteger('student_total_balance_minor')->nullable()->after('balance_currency');
            $table->char('student_total_balance_currency', 3)->nullable()->after('student_total_balance_minor');
        });

        // THE NEW KEY GOES ON BEFORE THE OLD ONE COMES OFF, and the order is not cosmetic: the
        // school_id FK (finance_opening_balance_rows_school_id_foreign) is satisfied by whichever
        // index has school_id leftmost, and on this table that was the old unique. Dropping it first
        // is refused 1553 "needed in a foreign key constraint" (watched, not assumed). The new key
        // has the same leading column, so once it exists the old one is free to go.
        Schema::table('finance_opening_balance_rows', function (Blueprint $table) {
            $table->unique(['school_id', 'batch_id', 'admission_number', 'fee_type_label'], self::NEW_KEY);
        });

        Schema::table('finance_opening_balance_rows', function (Blueprint $table) {
            $table->dropUnique(self::OLD_KEY);
            $table->dropColumn(self::RETIRED_COLUMNS['finance_opening_balance_rows']);
        });

        Schema::table('finance_opening_balance_batches', function (Blueprint $table) {
            $table->dropColumn(self::RETIRED_COLUMNS['finance_opening_balance_batches']);

            // L2's witness, ON THE BATCH — §2 names `batch_control_total` as the batch-level figure
            // of the frozen format, and this is where it lands. It is NOT a column of the FILE: §12
            // decision 2 is that the operator types it from WCBS's own report, because a total
            // carried inside the file shares the export's failure mode and witnesses nothing.
            //
            // Recorded because it is an ATTESTATION, not a derived figure. A human read it off
            // another system and typed it; §11 asks for exactly that to be kept with the go/no-go,
            // and 4c's approval screen reads this column rather than asking for the number again.
            //
            // NULLABLE, and not because the figure is optional — the command requires it — but
            // because a batch staged before this column existed must still be readable. MoneyCast
            // returns null only when both columns are null, so "never recorded" and "recorded as
            // zero" stay distinct.
            $table->bigInteger('control_total_minor')->nullable()->after('file_row_count');
            $table->char('control_total_currency', 3)->nullable()->after('control_total_minor');
        });

        foreach (self::NEW_CHECKS as [$table, $column, $name]) {
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$name}
                 CHECK ({$column} IS NULL OR {$column} COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}\$')"
            );
        }
    }

    /**
     * Restores the SHAPE, not the data. The rows up() deleted are gone and cannot be reconstructed —
     * they were staged under a withdrawn file format, so there is nothing to reconstruct them from.
     * That is stated rather than papered over: this down() leaves a schema a Rev 2/3 file could be
     * re-staged into, and two empty staging tables.
     */
    public function down(): void
    {
        DB::table('finance_opening_balance_rows')->delete();
        DB::table('finance_opening_balance_batches')->delete();

        // Old key back on before the new one comes off — same 1553 reason as up().
        Schema::table('finance_opening_balance_rows', function (Blueprint $table) {
            $table->unique(['school_id', 'batch_id', 'admission_number'], self::OLD_KEY);
        });

        Schema::table('finance_opening_balance_rows', function (Blueprint $table) {
            $table->dropUnique(self::NEW_KEY);
        });

        foreach (self::NEW_CHECKS as [$table, , $name]) {
            $this->dropCheckIfPresent($table, $name);
        }

        Schema::table('finance_opening_balance_rows', function (Blueprint $table) {
            $table->dropColumn([
                'fee_type_label',
                'balance_minor', 'balance_currency',
                'student_total_balance_minor', 'student_total_balance_currency',
            ]);

            $table->bigInteger('prior_arrears_minor')->nullable()->after('wcbs_student_ref');
            $table->char('prior_arrears_currency', 3)->nullable()->after('prior_arrears_minor');
            $table->bigInteger('wcbs_billed_total_minor')->nullable()->after('prior_arrears_currency');
            $table->char('wcbs_billed_total_currency', 3)->nullable()->after('wcbs_billed_total_minor');
            $table->bigInteger('paid_to_date_minor')->nullable()->after('wcbs_billed_total_currency');
            $table->char('paid_to_date_currency', 3)->nullable()->after('paid_to_date_minor');
            $table->bigInteger('wcbs_total_balance_minor')->nullable()->after('paid_to_date_currency');
            $table->char('wcbs_total_balance_currency', 3)->nullable()->after('wcbs_total_balance_minor');
            $table->bigInteger('expected_billed_minor')->nullable();
            $table->char('expected_billed_currency', 3)->nullable();
            $table->date('last_payment_date')->nullable()->after('wcbs_bill_reference');
        });

        Schema::table('finance_opening_balance_batches', function (Blueprint $table) {
            $table->dropColumn(['control_total_minor', 'control_total_currency']);

            $table->bigInteger('total_prior_arrears_minor')->nullable()->after('row_count');
            $table->char('total_prior_arrears_currency', 3)->nullable()->after('total_prior_arrears_minor');
            $table->bigInteger('total_paid_to_date_minor')->nullable()->after('total_prior_arrears_currency');
            $table->char('total_paid_to_date_currency', 3)->nullable()->after('total_paid_to_date_minor');
            $table->bigInteger('total_wcbs_billed_minor')->nullable()->after('total_paid_to_date_currency');
            $table->char('total_wcbs_billed_currency', 3)->nullable()->after('total_wcbs_billed_minor');
        });

        foreach (self::RETIRED_CHECKS as [$table, $name]) {
            $column = str_replace(['ob_batches_', 'ob_rows_', '_shape'], '', $name);
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$name}
                 CHECK ({$column} IS NULL OR {$column} COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}\$')"
            );
        }
    }

    /**
     * Drop a CHECK only if it is there. MySQL 8.0.43 rejects both `DROP CHECK … IF EXISTS` and
     * `DROP CONSTRAINT … IF EXISTS` (1064), and a blind drop of an absent constraint 1091s and
     * aborts the run. `information_schema.CHECK_CONSTRAINTS` has no TABLE_NAME, so the lookup goes
     * through TABLE_CONSTRAINTS — the same shape as
     * 2026_08_07_110000_add_provenance_to_finance_payments.php's down().
     */
    private function dropCheckIfPresent(string $table, string $name): void
    {
        $exists = (int) DB::scalar(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $name, 'CHECK'],
        );

        if ($exists > 0) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
        }
    }
};

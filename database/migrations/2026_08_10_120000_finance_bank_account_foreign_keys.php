<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S6/S11 COMMIT 2 OF 2 — money now says which account it landed in, and a charge says which
 * account it is destined for.
 *
 * Commit 1 (2026_08_10_100000) created finance_bank_accounts and the screen that fills it, so that
 * this migration has something to point at on the day it ships. That split is the CODE half of the
 * problem; see docs/handoff/post-deploy-tasks.md for the DATA half, which the split does not solve:
 * a school with no ACTIVE bank account cannot record a payment at all once this constraint is live,
 * and nothing here makes one exist.
 *
 * ─── finance_payments.bank_account_id ────────────────────────────────────────────────────────────
 *
 * THE COLUMN IS NULLABLE AND THE RULE IS A CHECK, which is not a weaker NOT NULL — it is a
 * DIFFERENT and stronger statement. A portal payment MUST name the account it landed in; a MIGRATED
 * payment must NOT, because money WCBS collected before cutover never entered one of our accounts
 * and pointing it at one would assert a fact that is false. A plain NOT NULL cannot say that; it
 * would force every imported row to name an account it never touched.
 *
 * The predicate is `origin`, which already exists as a CHECK-constrained 'portal'|'migrated' column
 * (2026_08_07_110000). The precedent is on this same table in the OPPOSITE direction:
 * `external_reference` is populated for migrated rows and null for portal ones. This is that rule's
 * mirror, and the two together make `origin` the single axis every provenance question turns on.
 *
 * TWO THINGS CARRIED FROM THAT MIGRATION'S DOCBLOCK, both learned expensively:
 *
 *   - COLLATE utf8mb4_bin is load-bearing. Under the table's default utf8mb4_unicode_ci,
 *     `origin = 'portal'` also matches 'PORTAL' and 'Portal', so a case variant would satisfy this
 *     CHECK by a branch nobody wrote a filter for. Binary collation pins the literals exactly.
 *
 *   - finance_payments is append-only: `finance_payments_no_update` (BEFORE UPDATE, SIGNAL 45000,
 *     driver 1644) fires AHEAD of CHECK evaluation. So on this table the CHECK's live door is
 *     INSERT — an UPDATE that violated it is refused 1644 before 3819 is ever reachable. Both doors
 *     are closed, by different mechanisms, and the tests assert each by its own code.
 *
 * ─── finance_fee_items.bank_account_id ───────────────────────────────────────────────────────────
 *
 * NOT NULL with no default. The table is empty (verified: 0 rows), which is what makes that
 * available — and a default would let a writer omit the destination and get one anyway, which is
 * the fabrication NOT-NULL-with-no-default exists to prevent. A fee item that does not say where
 * its money should go is not a configured charge.
 *
 * ─── finance_invoice_lines — DELIBERATELY NOT IN SCOPE ───────────────────────────────────────────
 *
 * S11's design is that the account travels from the fee item onto the line as a SNAPSHOT. It cannot
 * yet: new-invoice-modal.tsx states in its own docblock that line entry is manual with NO fee
 * catalog, so every line today is free text with no fee item behind it. A NOT NULL column would
 * force either a per-line selector on a modal with nothing to select from, or a per-school default —
 * and a default here fabricates a destination nobody chose, which is exactly what this commit's
 * other two columns are shaped to prevent.
 *
 * NOT ADDED NULLABLE EITHER, and that is the argued half. A nullable column with no writer is a
 * primitive ahead of its consumer: it would be null on every row until U1 ships, indistinguishable
 * from "nobody recorded it", and it would invite a reader to treat the absence as data. It arrives
 * when fee items are actually the source of lines, and it arrives NOT NULL then, because at that
 * point every line has a fee item to inherit from.
 *
 * ─── The FOREIGN KEYS are COMPOSITE, and that is this repository's uniform pattern ───────────────
 *
 * Ten existing finance FKs to a School-owned parent are (child_id, school_id) -> parent(id,
 * school_id); there is not one plain single-column FK among them. The composite is what makes a
 * cross-School reference impossible AT THE DATABASE rather than merely improbable: a payment in
 * school A cannot name school B's bank account, because the pair does not exist in the parent.
 *
 * That requires a UNIQUE (id, school_id) on the parent, which finance_bank_accounts does not have —
 * commit 1 had no referrer, so it needed none. Added here, named to match its nine siblings
 * ({table}_id_school_unique).
 *
 * ON DELETE RESTRICT, deliberately, and it is belt to commit 1's braces. There is no destroy route
 * and there must never be one — an account that has received money must stay nameable forever. The
 * restrict makes that true even for a hand-written DELETE.
 */
return new class extends Migration
{
    private const PAYMENTS_CHECK = 'finance_payments_bank_account_origin_shape';

    public function up(): void
    {
        // The parent side of every composite FK below.
        Schema::table('finance_bank_accounts', function (Blueprint $table) {
            $table->unique(['id', 'school_id'], 'finance_bank_accounts_id_school_unique');
        });

        Schema::table('finance_payments', function (Blueprint $table) {
            // Nullable at the column; the origin-keyed CHECK below is the real rule.
            $table->foreignId('bank_account_id')->nullable()->after('origin');

            $table->foreign(['bank_account_id', 'school_id'], 'finance_payments_bank_account_school_foreign')
                ->references(['id', 'school_id'])->on('finance_bank_accounts')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE finance_payments ADD CONSTRAINT '.self::PAYMENTS_CHECK."
             CHECK (
                (origin COLLATE utf8mb4_bin = 'portal'   AND bank_account_id IS NOT NULL)
                OR
                (origin COLLATE utf8mb4_bin = 'migrated' AND bank_account_id IS NULL)
             )"
        );

        Schema::table('finance_fee_items', function (Blueprint $table) {
            // NOT NULL, no default — the table is empty, so this is available and honest.
            $table->foreignId('bank_account_id')->after('school_id');

            $table->foreign(['bank_account_id', 'school_id'], 'finance_fee_items_bank_account_school_foreign')
                ->references(['id', 'school_id'])->on('finance_bank_accounts')
                ->restrictOnDelete();
        });
    }

    /**
     * Drop an index only if it is still there.
     *
     * ORDER IS EVERYTHING HERE, and this migration was not reversible until the audit proved it.
     *
     * Creating a composite FK makes MySQL build a supporting index named after the constraint, over
     * (bank_account_id, school_id). `dropForeign` removes the constraint and LEAVES that index. If
     * the column is dropped next, the index SHRINKS to (school_id) instead of disappearing — and
     * MySQL then adopts it as the supporting index for `finance_fee_items_school_id_foreign`, after
     * which it can never be dropped at all: `DROP INDEX` fails 1553, "needed in a foreign key
     * constraint". The name is now permanently taken, so the next `up()` cannot create its foreign
     * key and reports success having produced a column with no constraint behind it.
     *
     * So the index is dropped BETWEEN the foreign key and the column, while it is still the full
     * composite and no other constraint has claimed it. Measured, not reasoned: the 1553 above is
     * what the wrong order actually produces.
     */
    private function dropIndexIfPresent(string $table, string $index): void
    {
        $exists = (int) DB::scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index],
        );

        if ($exists > 0) {
            DB::statement('ALTER TABLE '.$table.' DROP INDEX '.$index);
        }
    }

    public function down(): void
    {
        Schema::table('finance_fee_items', function (Blueprint $table) {
            $table->dropForeign('finance_fee_items_bank_account_school_foreign');
        });
        $this->dropIndexIfPresent('finance_fee_items', 'finance_fee_items_bank_account_school_foreign');
        Schema::table('finance_fee_items', function (Blueprint $table) {
            $table->dropColumn('bank_account_id');
        });

        // Drop only if present, so down() survives a partial up(): MySQL 8.0.43 rejects both
        // `DROP CHECK … IF EXISTS` and `DROP CONSTRAINT … IF EXISTS` (1064), and a blind drop of an
        // absent constraint 1091s and aborts the rollback. information_schema.CHECK_CONSTRAINTS has
        // no TABLE_NAME, so the lookup goes through TABLE_CONSTRAINTS.
        $exists = (int) DB::scalar(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            ['finance_payments', self::PAYMENTS_CHECK, 'CHECK'],
        );

        if ($exists > 0) {
            DB::statement('ALTER TABLE finance_payments DROP CHECK '.self::PAYMENTS_CHECK);
        }

        Schema::table('finance_payments', function (Blueprint $table) {
            $table->dropForeign('finance_payments_bank_account_school_foreign');
        });
        $this->dropIndexIfPresent('finance_payments', 'finance_payments_bank_account_school_foreign');
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->dropColumn('bank_account_id');
        });

        Schema::table('finance_bank_accounts', function (Blueprint $table) {
            $table->dropUnique('finance_bank_accounts_id_school_unique');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance on finance_payments — the opening-balance import's export boundary (spec §4, §9 step 3).
 *
 * The table is finance_payments, not fee_payments: 2026_07_19_110000_rename_fee_tables_to_finance.php
 * renamed all five, and the create migration still says fee_payments. Read the rename, not the create.
 *
 * TWO COLUMNS, NOTHING ELSE.
 *
 *  - `origin` ('portal' | 'migrated', NOT NULL, DEFAULT 'portal') is the ONE predicate every collections
 *    report and every general-ledger export filters on. R4 (§12) made it structural: without it the GL
 *    double-counts the cutover, because an imported payment is real money that never arrived through this
 *    system.
 *
 *    THIS COLUMN MUST EXIST BEFORE THE FIRST IMPORTED ROW, and that ordering is load-bearing on its own,
 *    not merely a consequence of §9's build order. The objection to expect — it has been raised once
 *    already — is that `NOT NULL DEFAULT 'portal'` back-fills every pre-existing row anyway, so the
 *    ordering is free. It does not. The DEFAULT retro-marks UNIFORMLY, not CORRECTLY, and the two
 *    coincide only while no migrated row exists — which is exactly the precondition this ordering
 *    enforces, not an independent property of the default. Run the import first and add the column
 *    after, and every migrated payment is silently stamped 'portal', joins the WCBS export, and
 *    double-counts the cutover: precisely the failure R4 exists to prevent. No later ALTER can repair it,
 *    because the rows no longer carry the distinction it would have to mark — you cannot correctly
 *    retro-mark a distinction the data has already lost. `origin` is not a label applied to rows; it is a
 *    fact that has to be captured at write time.
 *
 *    §9's build order (provenance at step 3, posting at step 4) is a SECOND reason, not a replacement for
 *    that one. Do not descope this column on the strength of the DEFAULT.
 *
 *  - `external_reference` (nullable) is the WCBS receipt reference, the only handle back to the source
 *    system for an imported row. Null for everything portal-issued.
 *
 * There is deliberately NO bank_account_id. No table in this repository has such a column and no code
 * reads one — S6 has not happened, and a column ahead of its writer is an abstraction shaped by
 * imagination. §4's "bank_account_id stays null" row is corrected in the same commit as this migration.
 * (Confirmed by grep over database/migrations and app/ before this file existed; the recipe is not
 * repeated here because this docblock would now be its own only hit.)
 *
 * THE CHECK IS NOT OPTIONAL. §11's G1 finding was that a status column with no CHECK is releasable by one
 * UPDATE; `origin` carries strictly more weight than a status, because it is the boundary an export
 * decides on. Two further notes on the constraint, both learned the expensive way:
 *
 *  - COLLATE utf8mb4_bin is load-bearing, exactly as in 2026_08_01_120000_add_currency_shape_checks.php:24.
 *    Under the table's default utf8mb4_unicode_ci, `IN ('portal','migrated')` also admits 'PORTAL' and
 *    'Migrated' — a case-variant that every `origin = 'migrated'` filter would ALSO match, so the CHECK
 *    would read green while admitting values nobody wrote a filter for. Binary collation pins the two
 *    literals exactly.
 *
 *  - finance_payments is append-only: `finance_payments_no_update` (BEFORE UPDATE, SIGNAL 45000, driver
 *    1644) fires ahead of any CHECK evaluation. So on THIS table the CHECK's live door is INSERT — an
 *    UPDATE to a third value is refused 1644 by the trigger before 3819 is ever reachable. Both refusals
 *    are at the database and both are proved in PaymentProvenanceTest; the CHECK is not redundant, it is
 *    what makes the INSERT door as closed as the UPDATE door already was. Same shape as
 *    CurrencyShapeConstraintTest's path 3.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SEED TRAP — read this before touching the payment reference sequence.
 *
 * Migrated payments take `reference` from a reserved high band (>= Payment::MIGRATED_REFERENCE_FLOOR,
 * 900,000,000) so an imported row never interleaves with a portal-issued receipt under
 * UNIQUE (school_id, reference).
 *
 * That band is safe for ONE reason and it is not written down in the sequence: both live call sites —
 * RecordPayment::handle and RecordAccountPayment::handle — call `Sequences::next('finance_payment', …)`
 * with NO seed closure, so on first use the counter starts at 0 rather than adopting MAX(reference).
 * The absence of the third argument is the invariant.
 *
 * The codebase's DOMINANT pattern is the opposite one. HasAdmissionNumber and HasStaffNumber both pass a
 * seed and deliberately adopt the domain maximum, and their docblocks explain why that is correct for
 * THEM (a switch onto the counter must never reissue an existing identifier). Anyone "hardening" the
 * payment sequence to match those two — a one-line change that looks like consistency — makes the counter
 * adopt a migrated reference of 900,000,001 on a school whose sequence row does not yet exist, and every
 * subsequent portal receipt for that school is issued inside the reserved band. Permanently: the payments
 * table is append-only, so the receipts cannot be renumbered.
 *
 * The two Actions share ONE counter (same scope, same key) and a seed is evaluated on first use only, so
 * seeding either one alone corrupts the band through both doors. Both are pinned separately.
 *
 * Stated at Payment::MIGRATED_REFERENCE_FLOOR and at both call sites, and pinned by
 * tests/Feature/Finance/PaymentProvenanceTest.php's seed test. See that test's header for the honest
 * answer on whether this can be ENFORCED rather than merely asserted.
 */
return new class extends Migration
{
    private const CHECK_NAME = 'finance_payments_origin_shape';

    public function up(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            // NOT NULL with a default, so every row that already exists becomes 'portal' in the ALTER
            // itself. There is no backfill and no window in which the predicate is null — which is the
            // whole point of shipping it before the first imported row.
            $table->string('origin', 16)->default('portal')->after('method');
            $table->string('external_reference')->nullable()->after('origin');
        });

        // Raw CHECK, following 2026_08_01_120000_add_currency_shape_checks.php:66-71. The constraint name
        // is 29 characters — MySQL's identifier cap is 64, which that migration's generated
        // {table}_{column}_shape names sit under only because the finance_ table names are short.
        DB::statement(
            'ALTER TABLE finance_payments ADD CONSTRAINT '.self::CHECK_NAME."
             CHECK (origin COLLATE utf8mb4_bin IN ('portal', 'migrated'))"
        );
    }

    public function down(): void
    {
        // Drop only if present, so down() survives a partial up(): MySQL 8.0.43 rejects both
        // `DROP CHECK … IF EXISTS` and `DROP CONSTRAINT … IF EXISTS` (1064), and a blind drop of an absent
        // constraint 1091s and aborts the rollback. information_schema.CHECK_CONSTRAINTS has no
        // TABLE_NAME, so the lookup goes through TABLE_CONSTRAINTS.
        $exists = (int) DB::scalar(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            ['finance_payments', self::CHECK_NAME, 'CHECK'],
        );
        if ($exists > 0) {
            DB::statement('ALTER TABLE finance_payments DROP CHECK '.self::CHECK_NAME);
        }

        Schema::table('finance_payments', function (Blueprint $table) {
            $table->dropColumn(['origin', 'external_reference']);
        });
    }
};

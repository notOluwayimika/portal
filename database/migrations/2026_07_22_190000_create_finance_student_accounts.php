<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet W1 — the per-student account row: a PROJECTION + LOCK ANCHOR over the
 * existing signed ledger, NOT a new source of money truth.
 *
 * Grain is per-(school_id, student_id) with UNIQUE(school_id, student_id) — never
 * per-guardian; the guardian roll-up is a read model, not this row. The invariant
 * this table maintains is exactly one thing:
 *
 *     balance_minor == SUM(signed ledger amount_minor)  for that (school, student)
 *
 * A charge is positive, a payment/reversal negative (finance_ledger_transactions),
 * so a NEGATIVE balance means the school owes the student — available credit is
 * DERIVED `max(0, -balance_minor)`, never a second stored column (the design's fork
 * 3: one signed column, one invariant, no drift-prone pair to keep in step).
 *
 * THIS IS THE FIRST MUTABLE FINANCE TABLE. Every other finance_* table is
 * append-only (Constitution §15C: no_update/no_delete triggers + the AppendOnly
 * trait). This one legitimately mutates — the balance moves as the ledger grows —
 * so it carries NO append-only trigger and NO AppendOnly trait. Its integrity is
 * the atomic upsert-increment in SubledgerPoster::post (`balance = balance + delta`,
 * skew-free at InnoDB without an app lock) plus finance:reconcile-accounts as the
 * drift detector. NO `version` column: W2 has no optimistic read-modify-write to
 * guard; the pessimistic lockForUpdate arrives in W3, where applying credit is a
 * genuine read-modify-write. The intentional mutable exception is pinned in
 * tests/Feature/Finance/SchemaConventionsTest.php so a future "every finance table
 * must be append-only" tightening is forced to confront it rather than silently break.
 *
 * NO trigger here, so NO collation trap: the 1267 hazard is a trigger DECLARE
 * variable inheriting the database default collation; there is no trigger on this
 * table. balance_currency is a Laravel-created char(3) (utf8mb4_unicode_ci, the
 * canonical collation the #95 assertions already cover).
 *
 * BACKFILL: seed each existing (school, student) that has ledger activity from the
 * authoritative SUM — the projection is derived from truth at creation, so
 * finance:reconcile-accounts passes immediately after this migration. Students with
 * no ledger history get no row; RecordPayment get-or-creates lazily (seeding from
 * the same SUM) on their first payment.
 *
 * A student references `students` by a plain FK, exactly as the other TOP-LEVEL
 * finance tables do (invoices/payments/ledger own school_id directly and reference
 * students by a single-column FK). The composite (child, school) → parent(id,
 * school) template freeze applies to CHILD rows (lines, allocations); a top-level
 * account is not a child of another finance row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_student_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Uniform school_id on every Finance table (arch rule 5).
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();

            // The signed projection (ADR 0038 money columns). One column, sign carries
            // credit-vs-debt; available credit is DERIVED max(0, -balance_minor).
            $table->bigInteger('balance_minor')->default(0);
            $table->char('balance_currency', 3)->default('NGN');

            $table->timestamps();

            // The grain. One account per student per school — never per guardian.
            $table->unique(['school_id', 'student_id']);
        });

        // Seed from the authoritative signed ledger. A student's ledger is
        // single-currency by construction, so MAX(amount_currency) is that currency.
        // UUID() (server-side) is fine for a backfill — the AddUuid trait only governs
        // the app-created path. NOW() stamps both timestamps identically. From here,
        // SubledgerPoster::post keeps each balance moving with the ledger.
        DB::statement(
            'INSERT INTO finance_student_accounts
                (uuid, school_id, student_id, balance_minor, balance_currency, created_at, updated_at)
             SELECT UUID(), school_id, student_id, SUM(amount_minor), MAX(amount_currency), NOW(), NOW()
               FROM finance_ledger_transactions
              GROUP BY school_id, student_id'
        );
    }

    public function down(): void
    {
        // Drops exactly this table (no trigger, no schedule row — scheduling lives in
        // routes/console.php, not a migration). #85: this migration is the branch's
        // latest by timestamp; the reversibility bite-proof finds it by NAME and
        // asserts finance_student_accounts is gone, never trusting a bare --step=N.
        Schema::dropIfExists('finance_student_accounts');
    }
};

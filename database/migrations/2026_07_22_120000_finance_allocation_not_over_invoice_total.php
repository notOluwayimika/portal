<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Over-allocation guard: Σ(allocations to an invoice) ≤ that invoice's total.
 *
 * The payment route is deployed and was unguarded beyond amount>0 + not-void, so a
 * payment larger than the invoice was accepted and its full amount allocated — writing
 * an allocation of 100001 against a 100000 invoice and driving the ledger to −1. The
 * allocations table is append-only, so such a row is permanent (never deletable), and an
 * over-allocated invoice breaks the reconciliation identity the statement phase needs.
 *
 * THIS TRIGGER IS THE REAL GUARANTEE (the 1.4c family): a BEFORE INSERT on
 * finance_payment_allocations that reads the invoice's total and the already-allocated
 * sum and rejects any insert that would push the total over. It makes the illegal state
 * unrepresentable against a single raw/tamper write — not merely an Action-level check.
 *
 * WHAT IT DOES NOT DO — stated so no one mistakes it for the whole guarantee:
 *   - It is NOT concurrency-safe on its own. Its SELECT SUM misses another transaction's
 *     uncommitted allocation (the §12.2 write-skew), so two concurrent allocations could
 *     both pass. The concurrency guarantee is a lockForUpdate on the INVOICE ROW in
 *     RecordPayment, which serialises allocations to the same invoice. Trigger = the
 *     single-write/tamper backstop; invoice-row lock = the concurrency anchor. Both are
 *     needed; neither alone is sufficient.
 *   - It does not retroactively fix existing over-allocated rows — the deploy pre-flight
 *     (docs/runbooks) must assert zero exist before this lands.
 *
 * A NEW migration, not an edit of the deployed allocations table's original migration.
 * Additive: one trigger, no column, no touch of F6/F7 or the append-only triggers.
 */
return new class extends Migration
{
    private const TRIGGER = 'finance_allocation_not_over_invoice_total';

    public function up(): void
    {
        DB::unprepared(
            'CREATE TRIGGER '.self::TRIGGER.' BEFORE INSERT ON finance_payment_allocations
             FOR EACH ROW
             BEGIN
                DECLARE v_total BIGINT;
                DECLARE v_currency CHAR(3);
                DECLARE v_already BIGINT;

                SELECT total_minor, total_currency INTO v_total, v_currency
                  FROM finance_invoices WHERE id = NEW.invoice_id;

                -- Defense in depth: an allocation must share the invoice\'s currency, so
                -- the sum below compares like with like and can never net across kinds.
                --
                -- BINARY, not a plain <>: a routine variable takes the connection\'s
                -- collation while the column takes the table\'s, and when a database was
                -- created with a different default collation those two disagree — MySQL
                -- then raises 1267 "Illegal mix of collations" on EVERY insert, matching
                -- currency or not, turning this guard into a total outage. A currency
                -- code is a 3-letter ASCII token, so a byte comparison is exactly right
                -- and is collation-agnostic. (Found by the concurrency harness on a
                -- freshly-created DB whose default collation differed from the dev DB.)
                IF BINARY NEW.amount_currency <> BINARY v_currency THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_payment_allocations.amount_currency must match the invoice currency.\';
                END IF;

                -- Sum of ALL prior allocations to this invoice (append-only, so this is
                -- the whole history). Coalesce because the first allocation sees none.
                SELECT COALESCE(SUM(amount_minor), 0) INTO v_already
                  FROM finance_payment_allocations WHERE invoice_id = NEW.invoice_id;

                -- ≤, not <: an allocation exactly filling the outstanding balance is legal.
                IF v_already + NEW.amount_minor > v_total THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'Allocation would exceed the invoice total: Σ(allocations) must be ≤ finance_invoices.total_minor.\';
                END IF;
             END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
    }
};

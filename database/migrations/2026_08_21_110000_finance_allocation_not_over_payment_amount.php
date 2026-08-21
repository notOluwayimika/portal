<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Over-allocation guard, PAYMENT AXIS: Σ(allocations of a payment) ≤ that payment's amount.
 *
 * The sibling guard installed in July —
 * `2026_07_22_120000_finance_allocation_not_over_invoice_total` — enforces the same
 * inequality one axis over (Σ(allocations to an INVOICE) ≤ that invoice's total). Until
 * this migration that was the ONLY over-allocation constraint on
 * `finance_payment_allocations`, so a reader who saw it in the schema and concluded
 * "over-allocation is handled at the database" was right on one axis of two. Nothing —
 * trigger, CHECK, foreign key or generated column — stopped Σ(allocations of one PAYMENT)
 * from exceeding `finance_payments.amount_minor`. Ticket:
 * `docs/handoff/tickets/nothing-constrains-allocations-to-a-payments-amount.md`.
 *
 * WHY IT MATTERS EVEN THOUGH NO PATH PRODUCES IT TODAY. The table is APPEND-ONLY (no
 * DELETE, no UPDATE — enforced by its own triggers), so an over-allocated row is
 * permanent and can only be compensated around, never corrected. And the state it
 * produces is not silent: `PaymentReceiptController::document()` computes
 * `$unallocated = $payment->amount->minus($allocated)`, `Money::minus` does not floor at
 * zero, and the receipt renders on `! $unallocated->isZero()` — so an over-allocated
 * payment prints a NEGATIVE credit ("The remaining -N5,000.00 is held as credit ...") on
 * the one document a parent keeps.
 *
 * A TRIGGER, NOT A `CHECK`, AND THAT IS NOT A STYLE CHOICE. Production is MySQL
 * 5.7.23-23; MySQL enforces `CHECK` only from 8.0.16 and before that "parses and ignores"
 * it. See `2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php` and
 * `docs/finance/check-constraints-on-mysql-5-7.md`. `TRIGGER` + `SIGNAL SQLSTATE '45000'`
 * is documented 5.5+ and is the mechanism this schema already uses in ~49 places. As in
 * that migration, the 5.7 standing of the mechanism here is DOCUMENTED, not measured —
 * no 5.7 server was available to the author. Everything below described as measured was
 * measured on the local MySQL 8.0.43.
 *
 * ── WHAT THIS TRIGGER DOES NOT DO. Stated so no one mistakes it for the whole guarantee,
 *    in the same voice as the sibling's docblock, because it has the same blindness.
 *
 *   - IT IS NOT CONCURRENCY-SAFE ON ITS OWN, and this is measured rather than asserted
 *     (`tests/Feature/Finance/PaymentAxisConcurrencyTest.php`, PROOF f3). Its `SELECT
 *     SUM` is a plain read: under the connection's REPEATABLE READ it cannot see another
 *     transaction's UNCOMMITTED allocation against the same payment, so two writers each
 *     inserting half the payment plus one kobo BOTH pass the trigger and the invariant is
 *     violated after both commit. The trigger is the single-write / tamper / restored-dump
 *     backstop. It is NOT the concurrency anchor.
 *
 *   - THE CONCURRENCY ANCHOR FOR THIS AXIS IS THE ACCOUNT-ROW LOCK IN `GenerateInvoice`,
 *     not anything in this file. Both live writers were measured (PROOFS f1/f2):
 *       * `RecordPayment` CREATES the payment inside its own transaction and writes at
 *         most one allocation against it, capped at `min(amount, outstanding)`. No other
 *         transaction can address a payment id that has not been committed yet, so the
 *         payment axis is vacuous for it — it is safe by exclusivity, not by a lock.
 *       * `GenerateInvoice::applyCreditForward` is a genuine read-then-write with NO lock
 *         on the payment row: it sums that payment's allocations, subtracts, and inserts —
 *         app/Finance/Actions/GenerateInvoice.php:479 (applyCreditForward). It
 *         is nevertheless serialised, because `GenerateInvoice`'s FIRST statement is a
 *         locking read of the student's account row —
 *         app/Finance/Actions/GenerateInvoice.php:256 (lockForUpdate) — held for the whole
 *         transaction, and every payment it can draw from belongs to that one student. So
 *         the account row is a strictly
 *         COARSER serialisation point than the payment row, and it covers this axis.
 *         The ticket recorded this as never established; PROOF f1 establishes it.
 *
 *     THE RESIDUAL, NAMED: that coverage is a property of the two writers that exist
 *     today, not of the schema. A future writer that allocates against a payment without
 *     joining the account-row lock — a job, a bulk correction, a second student's path —
 *     would race, and this trigger would not catch it. Closing that in the database would
 *     need `SELECT ... FOR UPDATE` on `finance_payments` inside the writer's transaction
 *     (a trigger cannot take a lock that outlives the statement).
 *
 * A NEW migration, not an edit of the deployed table's original. Additive: one trigger,
 * no column, no touch of the append-only triggers or of the invoice-axis sibling. It is
 * created AFTER the sibling, so on MySQL 5.7.2+ / 8.0 it fires SECOND for the same
 * BEFORE INSERT event — leaving the invoice-axis message as the one a bursar sees when
 * both would refuse.
 */
return new class extends Migration
{
    private const TRIGGER = 'finance_allocation_not_over_payment_amount';

    public function up(): void
    {
        DB::unprepared(
            'CREATE TRIGGER '.self::TRIGGER.' BEFORE INSERT ON finance_payment_allocations
             FOR EACH ROW
             BEGIN
                DECLARE v_amount BIGINT;
                DECLARE v_currency CHAR(3);
                DECLARE v_already BIGINT;

                SELECT amount_minor, amount_currency INTO v_amount, v_currency
                  FROM finance_payments WHERE id = NEW.payment_id;

                -- Defense in depth: an allocation must share the payment\'s currency, so the
                -- sum below compares like with like. Without it the comparison is not merely
                -- weak, it is undefined — minor units of two currencies summed into one
                -- total and measured against a third quantity.
                --
                -- BINARY, not a plain <>, for the reason the sibling trigger records at
                -- length: a routine variable takes the connection collation while the column
                -- takes the table collation, and on a database created with a different
                -- default collation those disagree and MySQL raises 1267 on EVERY insert,
                -- matching currency or not. A currency code is a 3-letter ASCII token, so a
                -- byte comparison is exactly right and is collation-agnostic.
                IF BINARY NEW.amount_currency <> BINARY v_currency THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_payment_allocations.amount_currency must match the payment currency.\';
                END IF;

                -- Sum of ALL prior allocations of this payment. The table is append-only, so
                -- this is the whole history. COALESCE because the first allocation sees none
                -- and SUM over no rows is NULL, not 0.
                SELECT COALESCE(SUM(amount_minor), 0) INTO v_already
                  FROM finance_payment_allocations WHERE payment_id = NEW.payment_id;

                -- <=, not <: an allocation exactly exhausting the payment is legal and is the
                -- ordinary case (a payment that settles its invoice to the kobo).
                IF v_already + NEW.amount_minor > v_amount THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'Allocation would exceed the payment amount: Σ(allocations) must be ≤ finance_payments.amount_minor.\';
                END IF;
             END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
    }
};

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
 * ─────────────────────────────────────────────────────────────────────────────────────
 * THE SUM IS SCOPED TO ONE CURRENCY, AND A MIXED-CURRENCY PAYMENT IS REFUSED OUTRIGHT.
 * Both are load-bearing and neither is defensive decoration. This is the correction of a
 * real defect in the first version of this trigger, found by cold review and reproduced
 * before it was changed.
 *
 * THE DEFECT. The first version summed `amount_minor` across ALL allocations of a payment
 * regardless of currency. Measured on a planted fixture: an NGN payment of 10000 carrying
 * legacy allocations of 5000 NGN and 5000 USD sums to 10000, so a 1-kobo NGN allocation
 * was refused with "Allocation would exceed the payment amount" — on a payment holding
 * 5000 NGN of genuine room. Naira were added to dollars and the answer was reported as a
 * total. The schema permits that state to exist: `applyCreditForward` selects a student's
 * payments with NO currency filter and stamps the INVOICE's currency on the allocation
 * (app/Finance/Actions/GenerateInvoice.php:576 (applyCreditForward)), so before this
 * trigger existed nothing stopped it, and on 5.7 no `CHECK` ever ran.
 *
 * WHY BOTH ARMS, AND WHAT EACH ONE ALONE WOULD COST:
 *
 *   - SCOPING THE SUM ALONE is worse than the defect it fixes. With a per-currency sum and
 *     nothing else, EACH currency could be allocated up to the FULL payment amount — an
 *     NGN payment of 10000 would accept 10000 NGN *and* 10000 USD. That converts a wrong
 *     refusal into a wrong acceptance on a money table, which is the worse direction.
 *
 *   - REFUSING THE MIXED PAYMENT ALONE would leave the addition in the code, one deleted
 *     line away from returning.
 *
 *   Together they are exact: the mixed-currency arm guarantees every surviving allocation
 *   of a payment shares its currency, and the scoped sum guarantees that even if that arm
 *   were removed the trigger still cannot add two currencies together. The property
 *   "THIS TRIGGER NEVER ADDS TWO CURRENCIES" therefore holds LOCALLY, from the WHERE
 *   clause, and does not depend on a check three statements earlier.
 *
 * AND THE MESSAGE NAMES THE ACTUAL FAULT. A payment carrying two currencies is corrupt
 * data, not an over-allocation, and telling a bursar it is an over-allocation sends them
 * to look at amounts when they need to look at currencies.
 * ─────────────────────────────────────────────────────────────────────────────────────
 *
 * `CAST(x AS BINARY)` AND NOT `BINARY x`, MEASURED ON 8.0.43. `BINARY expr` is deprecated
 * and emits warning 1287, twice — once per operand. `CAST(… AS BINARY)` is MySQL's own
 * documented replacement, is valid on 5.7, and emits none.
 *
 * WHEN IT FIRES, corrected against a measurement rather than carried from the review that
 * asked for this change: the warnings are raised when the body is PARSED, at `CREATE
 * TRIGGER`, and NOT on each insert. Measured on a scratch table, emulated prepares on so
 * `SHOW WARNINGS` is reachable at all (over the binary protocol it answers 1295 instead):
 *
 *     body using `BINARY expr`        CREATE TRIGGER: 2 warnings (1287)   INSERT: 0
 *     body using `CAST(… AS BINARY)`  CREATE TRIGGER: 0                   INSERT: 0
 *
 * That makes it a smaller operational nuisance than "twice per insert" would be, and it is
 * why the arm that pins this (`PaymentAxisGuardTest`, PROOF g1) re-creates the stored body
 * under a scratch name and reads the warnings from the CREATE — an arm watching an insert
 * stays green with the deprecated form in place, which is to say it proves nothing. The
 * deprecation itself is unchanged: the form is going away, and this is a money table.
 *
 * The three-way reading that settled the SUBSTITUTION, taken the same way:
 *
 *     comparing 'NGN' COLLATE utf8mb4_general_ci with 'NGN' COLLATE utf8mb4_unicode_ci
 *       plain <>            ERROR 1267 Illegal mix of collations ... for operation '<>'
 *       BINARY expr         OK, equal, 2 warnings (1287 x2)
 *       CAST(… AS BINARY)   OK, equal, 0 warnings
 *     and CAST still discriminates: 'NGN' <> 'USD' is 1, 'NGN' <> 'ngn' is 1
 *
 * So the byte comparison is preserved exactly — including its case sensitivity — and the
 * 1267 outage it exists to prevent is still prevented. That outage is not hypothetical:
 * a routine variable takes the connection collation while the column takes the table
 * collation, and on a database created with a different default collation those disagree
 * and MySQL raises 1267 on EVERY insert, matching currency or not. The July sibling still
 * uses `BINARY expr` twice; that is a merged trigger on a money table, so it gets its own
 * migration and its own proof —
 * `docs/handoff/tickets/binary-expr-is-deprecated-in-the-july-allocation-trigger.md`.
 *
 * ── DEPLOY PRE-FLIGHT. It lives HERE, in the migration, and not only in a runbook or a
 *    report, for the reason the July sibling puts its equivalent in its own docblock: a
 *    `BEFORE INSERT` trigger does not inspect existing rows and will install cleanly over
 *    a violating one, so the assertion has to travel with the thing that makes it matter.
 *    BOTH clauses must return zero rows before this lands on a server holding real money.
 *
 *    1. Over-allocated WITHIN a currency. Note the scope: the naive
 *       `HAVING SUM(amount_minor) > amount_minor` is the same cross-currency addition this
 *       trigger no longer performs, and on the fixture above it returns ZERO ROWS and
 *       reports a corrupt payment as clean.
 *
 *         SELECT a.payment_id, a.amount_currency, SUM(a.amount_minor) AS allocated,
 *                MIN(p.amount_minor) AS payment_amount
 *           FROM finance_payment_allocations a
 *           JOIN finance_payments p ON p.id = a.payment_id
 *          WHERE a.amount_currency = p.amount_currency
 *          GROUP BY a.payment_id, a.amount_currency
 *         HAVING SUM(a.amount_minor) > MIN(p.amount_minor);
 *
 *    2. Mixed currency — any allocation whose currency differs from its payment's. These
 *       rows are refused going forward and any payment holding one will refuse all further
 *       allocations, so they must be found and decided on BEFORE the trigger lands rather
 *       than discovered by a bursar who cannot take a payment.
 *
 *         SELECT DISTINCT a.payment_id, a.amount_currency, p.amount_currency AS payment_currency
 *           FROM finance_payment_allocations a
 *           JOIN finance_payments p ON p.id = a.payment_id
 *          WHERE a.amount_currency <> p.amount_currency;
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
 *         app/Finance/Actions/GenerateInvoice.php:576 (applyCreditForward). It
 *         is nevertheless serialised, because `GenerateInvoice`'s FIRST statement is a
 *         locking read of the student's account row —
 *         app/Finance/Actions/GenerateInvoice.php:263 (lockForUpdate) — held for the whole
 *         transaction, and every payment it can draw from belongs to that one student. So
 *         the account row is a strictly COARSER serialisation point than the payment row,
 *         and it covers this axis. The ticket recorded this as never established; PROOF f1
 *         establishes it.
 *
 *     THE RESIDUAL, NAMED: that coverage is a property of the two writers that exist
 *     today, not of the schema. A future writer that allocates against a payment without
 *     joining the account-row lock — a job, a bulk correction, a second student's path —
 *     would race, and this trigger would not catch it. Closing that in the database would
 *     need `SELECT ... FOR UPDATE` on `finance_payments` inside the writer's transaction
 *     (a trigger cannot take a lock that outlives the statement).
 *
 *   - IT DOES NOT READ `allocation_rule`, DELIBERATELY. The ceiling is a property of the
 *     payment, not of why the allocation was written, so both writers meet the identical
 *     rule. That is asserted rather than assumed: `PaymentAxisGuardTest` runs its refusal
 *     and boundary arms under BOTH `payment_against_named_invoice` and
 *     `credit_applied_forward_oldest_first`. The first version of this migration shipped
 *     with every arm hardcoding the former, and a mutation narrowing the guard to that one
 *     rule — which would have disabled it for `applyCreditForward`, the writer with no
 *     payment-row lock — left all 676 Finance tests green.
 *
 * VERIFIED BY SHAPE **AND BY MESSAGE TEXT**, NOT BY EXIT CODE (ADR 0052). `CREATE TRIGGER`
 * returning success is not evidence the right trigger exists. `2026_08_17_100000` reads
 * back `ACTION_TIMING` and `EVENT_MANIPULATION`; this migration reads those AND the
 * `SIGNAL` message texts out of `ACTION_STATEMENT`, which is a superset and is here for a
 * specific reason. `PaymentAxisGuardTest` covers shape and behaviour on 8.0.43, but the
 * over-allocation message is 99 characters / 102 BYTES — it carries `Σ` and `≤` — and
 * every `Σ` and `≤` elsewhere in this schema sits inside a `--` comment, where mangling is
 * invisible. A latin1 client on the 5.7 production server could therefore corrupt exactly
 * this message with nothing in the suite able to go red. Reading it back at migrate time
 * puts the check on the server that actually runs it.
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

    /** Refusal 1 of 3. 76 characters, counted rather than eyeballed (MySQL caps MESSAGE_TEXT at 128). */
    private const MSG_CURRENCY = 'finance_payment_allocations.amount_currency must match the payment currency.';

    /** Refusal 2 of 3. 119 characters, pure ASCII — the fault is data, not arithmetic, and it says so. */
    private const MSG_MIXED = 'This payment carries allocations in more than one currency; no total is comparable. Investigate before allocating more.';

    /** Refusal 3 of 3. 99 characters / 102 bytes — carries the only non-ASCII in any SIGNAL here. */
    private const MSG_OVER = 'Allocation would exceed the payment amount: Σ(allocations) must be ≤ finance_payments.amount_minor.';

    public function up(): void
    {
        // Idempotent, so the rollback/re-up leg of bin/quality-clean-db re-asserts rather
        // than 1359s on an existing trigger.
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);

        DB::unprepared(
            'CREATE TRIGGER '.self::TRIGGER.' BEFORE INSERT ON finance_payment_allocations
             FOR EACH ROW
             BEGIN
                DECLARE v_amount BIGINT;
                DECLARE v_currency CHAR(3);
                DECLARE v_already BIGINT;
                DECLARE v_foreign BIGINT;

                SELECT amount_minor, amount_currency INTO v_amount, v_currency
                  FROM finance_payments WHERE id = NEW.payment_id;

                -- ARM 1 — the incoming allocation must be in the payment\'s currency.
                --
                -- CAST(x AS BINARY) and not BINARY x: the latter is deprecated and emits
                -- warning 1287 twice per insert. Both forms are collation-agnostic, which
                -- is what this comparison needs — a routine variable takes the connection
                -- collation and the column takes the table collation, and where those
                -- disagree a plain <> raises 1267 on EVERY insert, matching currency or
                -- not, turning this guard into a total outage. A currency code is a
                -- 3-letter ASCII token, so a byte comparison is exactly right.
                IF CAST(NEW.amount_currency AS BINARY) <> CAST(v_currency AS BINARY) THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'finance_payment_allocations.amount_currency must match the payment currency.\';
                END IF;

                -- ARM 2 — the payment must not ALREADY carry an allocation in some other
                -- currency. Arm 1 stops new ones; this stops a payment that legacy data
                -- (or a 5.7 server on which no CHECK ever ran) left holding two. Without
                -- it, scoping the sum below would let EACH currency be allocated up to the
                -- full payment amount, which is a worse failure than the one being fixed.
                SELECT COUNT(*) INTO v_foreign
                  FROM finance_payment_allocations
                 WHERE payment_id = NEW.payment_id
                   AND CAST(amount_currency AS BINARY) <> CAST(v_currency AS BINARY);

                IF v_foreign > 0 THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'This payment carries allocations in more than one currency; no total is comparable. Investigate before allocating more.\';
                END IF;

                -- ARM 3 — the ceiling. SCOPED TO THE PAYMENT CURRENCY, so this statement
                -- cannot add two currencies together even if arm 2 were deleted. The table
                -- is append-only, so this is the whole history. COALESCE because the first
                -- allocation sees no rows and SUM over none is NULL, not 0 — and NULL + x
                -- > y is NULL, which is not true, so the row would be accepted.
                --
                -- NO READ OF allocation_rule ANYWHERE ABOVE, deliberately: the ceiling is a
                -- property of the payment, not of why the allocation was written.
                SELECT COALESCE(SUM(amount_minor), 0) INTO v_already
                  FROM finance_payment_allocations
                 WHERE payment_id = NEW.payment_id
                   AND CAST(amount_currency AS BINARY) = CAST(v_currency AS BINARY);

                -- <=, not <: an allocation exactly exhausting the payment is legal and is the
                -- ordinary case (a payment that settles its invoice to the kobo).
                IF v_already + NEW.amount_minor > v_amount THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'Allocation would exceed the payment amount: Σ(allocations) must be ≤ finance_payments.amount_minor.\';
                END IF;
             END'
        );

        $this->assertTriggerShapeAndMessages();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
    }

    /**
     * Read the trigger back and refuse to record the migration unless it is what `CREATE`
     * claimed — name, timing, event, table, AND all three `SIGNAL` message texts.
     *
     * The message half is not ceremony. `MSG_OVER` carries `Σ` and `≤`; a client connected
     * in latin1 can mangle those on the way in, and the resulting trigger still fires, still
     * refuses the right rows, and still passes every shape and behaviour assertion in the
     * suite. The only place that corruption is visible is the stored body.
     */
    private function assertTriggerShapeAndMessages(): void
    {
        $read = DB::selectOne(
            'SELECT ACTION_TIMING AS timing, EVENT_MANIPULATION AS event,
                    EVENT_OBJECT_TABLE AS tbl, ACTION_STATEMENT AS body
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [self::TRIGGER],
        );

        if ($read === null) {
            throw new RuntimeException(
                'Trigger ['.self::TRIGGER.'] does not exist after CREATE TRIGGER returned success. '
                .'Refusing to record this migration as applied: the guard it claims to install is '
                .'absent, and on 5.7 there is no CHECK behind it.'
            );
        }

        if ($read->timing !== 'BEFORE' || $read->event !== 'INSERT' || $read->tbl !== 'finance_payment_allocations') {
            throw new RuntimeException(
                'Trigger ['.self::TRIGGER."] exists with the wrong shape: got {$read->timing} {$read->event} "
                ."on {$read->tbl}, expected BEFORE INSERT on finance_payment_allocations. A trigger with "
                .'the right name and the wrong timing or event fires on writes nobody guarded and misses '
                .'the ones they did.'
            );
        }

        foreach ([self::MSG_CURRENCY, self::MSG_MIXED, self::MSG_OVER] as $message) {
            if (! str_contains((string) $read->body, $message)) {
                throw new RuntimeException(
                    'Trigger ['.self::TRIGGER.'] is stored without the expected SIGNAL message text ['
                    .$message.']. The guard would still fire and still refuse the right rows, so no '
                    .'behavioural test can see this — it is what a latin1 client does to a message '
                    .'carrying Σ and ≤. Refusing to record the migration.'
                );
            }
        }
    }
};

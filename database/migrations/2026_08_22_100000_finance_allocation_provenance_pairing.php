<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U10 — AN ALLOCATION A HUMAN DIRECTED MUST NAME THE HUMAN, AND AN OVERRIDE MUST CARRY ITS REASON.
 *
 * One column and one trigger, and they are one decision with one precondition, which is why they are
 * one migration rather than two: the column without the pairing is an attribution a writer may
 * forget, and the pairing without the column has nothing to pair.
 *
 * ── WHY THE COLUMN NOW ──
 *
 * `2026_07_26_120000_add_created_by_to_finance_invoice_lines` closed the same hole one table over,
 * and its sentence is this migration's sentence: "Every other Finance document names its human —
 * `cancelled_by_user_id`, `received_by_user_id`, `created_by_user_id`, `submitted_by`/`decided_by`.
 * The one gap was the discretionary reduction a bursar applies by hand." Until U10 there was no
 * discretionary allocation: both writers of `finance_payment_allocations` are engines
 * ({@see RecordPayment} caps at the named invoice's outstanding,
 * {@see GenerateInvoice::applyCreditForward} draws oldest-payment-first), and an
 * engine has no human to name. U10's Action is the first row a person chooses, so the column arrives
 * with its consumer rather than ahead of it.
 *
 * AND IT ARRIVES BEFORE THE FIRST SUCH ROW EXISTS, which is the whole reason not to defer it.
 * `finance_payment_allocations` is append-only — `finance_payment_allocations_no_update` (BEFORE
 * UPDATE, SIGNAL 45000, driver 1644) — so a column added after the first operator-directed
 * allocation leaves that row silent about its author forever. That is the capture-columns
 * migration's argument (`2026_08_09_120000`) applied one column later.
 *
 * ── NULLABLE, AND A LOOKUP RATHER THAN A FOREIGN KEY ──
 *
 * Plain `unsignedBigInteger`, nullable, no `constrained()` — the same convention and the same reason
 * as `finance_invoices.cancelled_by_user_id`, `finance_payments.received_by_user_id`,
 * `finance_credit_notes.created_by_user_id` and `finance_invoice_lines.created_by_user_id`: an
 * attribution must never block a user's lifecycle.
 *
 * NULLABLE HERE CARRIES INFORMATION RATHER THAN ABSENCE, and that is exactly what the trigger below
 * makes true. NULL does not mean "nobody recorded it"; it means "no human directed this row", which
 * is the honest and permanent fact about every allocation either engine writes. The distinction is
 * not left to trust: `allocation_rule` already says which writer produced the row, and the pairing
 * makes the two columns agree at the database.
 *
 * ── THE PAIRING IS A TRIGGER, NOT A CHECK ──
 *
 * Production is MySQL 5.7.23, which PARSES AND IGNORES `CHECK` entirely. That is why
 * `2026_08_17_100000_maker_checker_and_payment_origin_as_triggers` rewrote two live CHECKs on
 * `finance_payments` as triggers, and this rule is written as one from the start rather than
 * shipping a constraint that is decorative on the server it has to hold on. The precedent it copies
 * most closely is `finance_payments_origin_pairing_bi`: one column keying the legality of another,
 * refused at INSERT with SIGNAL 45000 (driver code 1644).
 *
 * ── WHAT THE TRIGGER DOES *NOT* CONSTRAIN, STATED SO NOBODY READS MORE INTO IT ──
 *
 * It says nothing about an `allocation_rule` value it does not recognise. `2026_08_09_120000` chose a
 * plain `varchar(64)` over a database enum deliberately — "the set is what the code does today, and a
 * database enum would have to be migrated to add the third rule whose screen (U10) has not been
 * built" — and turning this trigger's else-branch into a refusal would make the column an enum by the
 * back door, reversing that decision as a side effect of an unrelated one. A fourth rule therefore
 * arrives unconstrained by this trigger and must bring its own decision about attribution. The three
 * arms below are statements about the three rules that exist.
 *
 * ── ARM 2 IS NOT REDUNDANT WITH ARM 1 ──
 *
 * Arm 1 refuses an operator row with no author; arm 2 refuses an ENGINE row WITH one. Only the pair
 * makes NULL mean something: with arm 1 alone, a future writer could stamp a user onto a
 * credit-applied-forward row and the column would stop being readable as "no human chose this".
 *
 * ALTER TABLE is DDL and does not fire the append-only BEFORE-UPDATE trigger on this table — the same
 * fact `2026_08_09_120000` relied on when it added three columns here while that trigger was live.
 */
return new class extends Migration
{
    private const TRIGGER = 'finance_allocation_provenance_pairing_bi';

    private const ACTOR_INDEX = 'finance_alloc_actor_school_index';

    /** The one rule an operator produces. Kept in step with PaymentAllocation::RULE_OPERATOR_DIRECTED_REMAINDER. */
    private const RULE_OPERATOR = 'operator_directed_remainder';

    private const RULE_NAMED_INVOICE = 'payment_against_named_invoice';

    private const RULE_CREDIT_FORWARD = 'credit_applied_forward_oldest_first';

    /** Refusal 1 of 4. 115 characters, counted rather than eyeballed (MySQL caps MESSAGE_TEXT at 128). */
    private const MSG_ACTOR_REQUIRED = 'An operator-directed allocation must name the user who directed it: allocated_by_user_id is required for that rule.';

    /** Refusal 2 of 4. 112 characters, pure ASCII. */
    private const MSG_ACTOR_FORBIDDEN = 'Only an operator-directed allocation may name a user: the two engine rules must leave allocated_by_user_id null.';

    /** Refusal 3 of 4. 102 characters. */
    private const MSG_REASON_PAIRING = 'allocation_override_reason is required when allocation_overridden is 1, and must be null when it is 0.';

    /** Refusal 4 of 4. 113 characters. */
    private const MSG_OVERRIDE_RULE = 'Only an operator-directed allocation may be overridden: the engine rules compute a split, they do not choose one.';

    public function up(): void
    {
        Schema::table('finance_payment_allocations', function (Blueprint $table) {
            // LOOKUP attribution, not an FK — see the docblock. After the two columns it is paired
            // with, so the three provenance columns read together in a `DESCRIBE`.
            $table->unsignedBigInteger('allocated_by_user_id')->nullable()->after('allocation_override_reason');

            // The index the audit question actually asks: "what did this operator direct, in this
            // School". Both predicates are equalities, so either column order serves it.
            //
            // THE ORDER IS (actor, school) AND NOT (school, actor), WHICH IS THE ORDER
            // finance_invoice_lines USES FOR THE SAME PAIR. The deviation is deliberate and it is
            // measured, not reasoned: `finance_payment_allocations` carries a SINGLE-COLUMN foreign
            // key `fee_payment_allocations_school_id_foreign` on `school_id` alone, and InnoDB backs
            // it with an index of that name. Add a composite index whose LEFTMOST column is
            // `school_id` and that index becomes the one serving the foreign key — measured on
            // MySQL 8.0.43, `portal_testing`, by listing SHOW INDEX with and without this migration:
            //
            //   without: fee_payment_allocations_school_id_foreign (school_id)   PRESENT
            //   with:    fee_payment_allocations_school_id_foreign               ABSENT
            //            finance_alloc_..._index (school_id, allocated_by_user_id) PRESENT
            //
            // Two things follow, and the second is what made this a defect rather than a preference.
            // This migration would be silently changing which index backs a foreign key it has
            // nothing to do with. And `down()` could then never drop its own index: MySQL refuses
            // with 1553 "Cannot drop index … needed in a foreign key constraint", so the rollback
            // fails half-done — trigger dropped, column still there, the migration still recorded as
            // run. That is exactly what the four-path down() audit caught before this reordering.
            //
            // Leading with `allocated_by_user_id` makes the index unusable as a prefix for that
            // foreign key, so the pre-existing index stays where it was and this one is this
            // migration's to drop.
            //
            // NAMED EXPLICITLY because the generated name would be 64 characters —
            // `finance_payment_allocations_allocated_by_user_id_school_id_index` — at MySQL's
            // identifier limit to the character. The name is pinned here rather than derived, and
            // down() drops it by that name rather than reconstructing the default.
            $table->index(['allocated_by_user_id', 'school_id'], self::ACTOR_INDEX);
        });

        // Idempotent, so the rollback/re-up leg of bin/quality-clean-db re-asserts rather than 1359s
        // on an existing trigger.
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);

        DB::unprepared(
            'CREATE TRIGGER '.self::TRIGGER.' BEFORE INSERT ON finance_payment_allocations
             FOR EACH ROW
             BEGIN
                -- CAST(x AS BINARY) and not BINARY x throughout: the latter is deprecated and emits
                -- warning 1287 per insert, and both forms are collation-agnostic — which is what a
                -- comparison between a table column and a routine literal needs, since a disagreement
                -- between the table collation and the connection collation raises 1267 on EVERY
                -- insert and turns a guard into an outage. Learned on the payment-axis sibling
                -- (2026_08_21_110000); a rule name is an ASCII token, so a byte comparison is exact.
                -- It is also what makes the match CASE-SENSITIVE, so \'Operator_Directed_Remainder\'
                -- cannot satisfy arm 1 through a branch nobody wrote a filter for.

                -- ARM 1 — an operator-directed row must name its operator.
                IF CAST(NEW.allocation_rule AS BINARY) = CAST(\''.self::RULE_OPERATOR.'\' AS BINARY)
                   AND NEW.allocated_by_user_id IS NULL THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \''.self::MSG_ACTOR_REQUIRED.'\';
                END IF;

                -- ARM 2 — and an ENGINE row must not. Without this, NULL would degrade from "no human
                -- chose this row" to "nobody happened to record one", which is the difference between
                -- a readable column and a silent one.
                IF CAST(NEW.allocation_rule AS BINARY) IN (
                       CAST(\''.self::RULE_NAMED_INVOICE.'\' AS BINARY),
                       CAST(\''.self::RULE_CREDIT_FORWARD.'\' AS BINARY))
                   AND NEW.allocated_by_user_id IS NOT NULL THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \''.self::MSG_ACTOR_FORBIDDEN.'\';
                END IF;

                -- ARM 3 — the marker and its reason move together, in BOTH directions.
                -- 2026_08_09_120000 stated this rule in prose ("Null whenever allocation_overridden is
                -- false") and enforced nothing, which is a wish rather than a rule. A blank string is
                -- refused with the absent one: an override reason that says nothing is the same
                -- audit hole as no reason, reached by pressing the space bar.
                IF (NEW.allocation_overridden = 1
                    AND (NEW.allocation_override_reason IS NULL OR TRIM(NEW.allocation_override_reason) = \'\'))
                   OR (NEW.allocation_overridden = 0 AND NEW.allocation_override_reason IS NOT NULL) THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \''.self::MSG_REASON_PAIRING.'\';
                END IF;

                -- ARM 4 — only a human can depart from a proposal. An engine writer stamping
                -- allocation_overridden = 1 would be asserting a choice nobody made, on a row that can
                -- never be edited.
                IF NEW.allocation_overridden = 1
                   AND CAST(NEW.allocation_rule AS BINARY) <> CAST(\''.self::RULE_OPERATOR.'\' AS BINARY) THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \''.self::MSG_OVERRIDE_RULE.'\';
                END IF;
             END'
        );

        $this->assertTriggerShapeAndMessages();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);

        Schema::table('finance_payment_allocations', function (Blueprint $table) {
            $table->dropIndex(self::ACTOR_INDEX);
            $table->dropColumn('allocated_by_user_id');
        });
    }

    /**
     * Read the trigger back and refuse to record the migration unless it is what `CREATE` claimed —
     * name, timing, event, table, AND all four `SIGNAL` message texts. Per ADR 0052 the migration
     * reporting DONE proves nothing; the server that will run the guard is the only witness.
     *
     * The message half is not ceremony on this table. Its sibling
     * (`2026_08_21_110000_finance_allocation_not_over_payment_amount`) carries `Σ` and `≤`, which a
     * latin1 client can mangle on the way in while the trigger still fires and still refuses the right
     * rows — invisible to every behavioural test. These four are pure ASCII and so cannot fail that
     * way, but the check is kept identical rather than weakened for them: the next message added here
     * will not be, and a guard that is only sometimes read back is one nobody trusts.
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
                .'Refusing to record this migration as applied: the pairing it claims to install is '
                .'absent, and on 5.7 there is no CHECK behind it.'
            );
        }

        if ($read->timing !== 'BEFORE' || $read->event !== 'INSERT' || $read->tbl !== 'finance_payment_allocations') {
            throw new RuntimeException(
                'Trigger ['.self::TRIGGER."] exists with the wrong shape: got {$read->timing} {$read->event} "
                ."on {$read->tbl}, expected BEFORE INSERT on finance_payment_allocations."
            );
        }

        foreach ([self::MSG_ACTOR_REQUIRED, self::MSG_ACTOR_FORBIDDEN, self::MSG_REASON_PAIRING, self::MSG_OVERRIDE_RULE] as $message) {
            if (! str_contains((string) $read->body, $message)) {
                throw new RuntimeException(
                    'Trigger ['.self::TRIGGER.'] is stored without the expected SIGNAL message text ['
                    .$message.']. Refusing to record the migration.'
                );
            }
        }

        if (! Schema::hasColumn('finance_payment_allocations', 'allocated_by_user_id')) {
            throw new RuntimeException(
                'finance_payment_allocations.allocated_by_user_id is absent after this migration added it. '
                .'The trigger above would then refuse every operator-directed allocation on a column that '
                .'does not exist, which is a broken deploy reported as a successful one.'
            );
        }
    }
};

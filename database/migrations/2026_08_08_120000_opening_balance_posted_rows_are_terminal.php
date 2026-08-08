<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §9 step 4b, second pass — the staged rows a posted charge points at become terminal too, and G1b's
 * UPDATE door is widened to cover the OTHER column its key is computed from.
 *
 * WHY THIS IS A SECOND MIGRATION AND NOT AN EDIT TO 2026_08_08_110000. That file has run; ADR 0052's
 * corollary is that an applied migration's executing half is not edited, whatever the branch state.
 * So the no-unpost trigger is DROPPED AND RECREATED here rather than rewritten there.
 *
 * ── 1. finance_opening_balance_rows: UPDATE and DELETE denied while the parent batch is posted ──
 *
 * 2026_08_08_110000 closed both exits from a posted BATCH, and its own docblock named the harm
 * exactly: deleting one "CASCADEs the staged rows away … leaving the posted ledger charges pointing
 * at rows that no longer exist." That guard was one table too high. The rows themselves carried no
 * trigger of any kind, so:
 *
 *   DELETE FROM finance_opening_balance_rows WHERE batch_id = <a posted batch>;
 *
 * reached the same end state by the front door — and an UPDATE was worse in kind: rewriting
 * `balance_minor` or `fee_type_label` after posting silently falsifies the per-fee-type audit trail
 * that is the ENTIRE reason PostOpeningBalanceBatch sources each charge to the staged row, and
 * rewriting `balance_currency` puts finance:audit-ledger-coherence into I7 instead of I2. None of it
 * is repairable: finance_ledger_transactions denies DELETE by trigger
 * (2026_07_19_100001_create_fee_ledger_transactions_table.php:56-60), so the charges cannot be
 * withdrawn and re-posted, and the auditor has no --fix.
 *
 * The general rule, stated so it can be checked rather than left as a habit: A GUARD ON A STATE'S
 * EXIT MUST COVER EVERY STATEMENT THAT CAN REMOVE OR REWRITE THE ROWS THE STATE'S OUTPUT DEPENDS ON —
 * not only the row that carries the state. 110000 applied it to the batch; this applies it to what
 * the batch's output points at.
 *
 * THE CONDITION IS THE PARENT'S STATUS, read per row. That is a SELECT inside a FOR EACH ROW trigger,
 * which is the cost worth naming: it is paid on UPDATE and DELETE of a staged row and NOWHERE ELSE.
 * Neither trigger is an INSERT trigger, and validation only ever INSERTs (ImportOpeningBalances
 * writes rows once and never rewrites them), so the hot path — staging a file of thousands of rows —
 * is untouched. There is no denormalised copy of the parent status onto the row, deliberately: a
 * second copy of a state is a second thing to keep true, and the read is off the batch's primary key.
 *
 * ── 2. G1's key is computed from TWO columns; the UPDATE guard covered one ──
 *
 * `posted_school_key` is `IF(status = 'posted', school_id, NULL)`. The 110000 trigger fired only on a
 * `status` move, so
 *
 *   UPDATE finance_opening_balance_batches SET school_id = <another school> WHERE id = <posted>;
 *
 * freed the origin school's slot without touching `status` — G1's claim is "one posted batch per
 * school", and moving the batch out of the school is a way to break it that never mentions the state.
 *
 * **A foreign key blocks the row-carrying case today, and this migration deliberately does not rely on
 * it.** `finance_opening_balance_rows_batch_school_foreign` is `NO ACTION` on update, so the statement
 * above is refused 1451 for any batch that has staged rows — incidental, unstated anywhere until now,
 * and one line away from being lost: give that FK `ON UPDATE CASCADE`, the obvious-looking symmetry
 * with its existing `ON DELETE CASCADE`, and the door opens for every posted batch with the ledger
 * charges left behind in the original school. A zero-row posted batch is not covered by it at all.
 * The trigger covers both, and covers them because it says so rather than because a constraint
 * written for a different purpose happens to.
 *
 * MESSAGE_TEXT: no apostrophe (MySQL stores a trigger body with the escape stripped — pinned by
 * TriggerBodiesAreDumpSafeTest) and under 128 characters (a longer literal makes the SIGNAL itself
 * fail with 1648 instead of 1644 — watched, 110000's docblock carries the finding).
 */
return new class extends Migration
{
    private const BATCHES = 'finance_opening_balance_batches';

    private const ROWS = 'finance_opening_balance_rows';

    private const NO_UNPOST_TRIGGER = 'finance_opening_balance_batches_no_unpost';

    private const ROWS_NO_UPDATE_TRIGGER = 'finance_opening_balance_rows_no_update_when_posted';

    private const ROWS_NO_DELETE_TRIGGER = 'finance_opening_balance_rows_no_delete_when_posted';

    public function up(): void
    {
        // 1 — the rows.
        DB::unprepared(
            'CREATE TRIGGER '.self::ROWS_NO_UPDATE_TRIGGER.' BEFORE UPDATE ON '.self::ROWS.'
             FOR EACH ROW
             BEGIN
                IF (SELECT status FROM '.self::BATCHES.' WHERE id = OLD.batch_id) = \'posted\' THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'A staged row of a posted opening-balance batch is terminal: the ledger charges cite it.\';
                END IF;
             END'
        );

        DB::unprepared(
            'CREATE TRIGGER '.self::ROWS_NO_DELETE_TRIGGER.' BEFORE DELETE ON '.self::ROWS.'
             FOR EACH ROW
             BEGIN
                IF (SELECT status FROM '.self::BATCHES.' WHERE id = OLD.batch_id) = \'posted\' THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'A staged row of a posted opening-balance batch cannot be deleted: the ledger charges cite it.\';
                END IF;
             END'
        );

        // 2 — the widened batch guard. Dropped and recreated; 110000 is applied and is not edited.
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::NO_UNPOST_TRIGGER);
        DB::unprepared(
            'CREATE TRIGGER '.self::NO_UNPOST_TRIGGER.' BEFORE UPDATE ON '.self::BATCHES.'
             FOR EACH ROW
             BEGIN
                IF OLD.status = \'posted\'
                   AND (NEW.status <> \'posted\' OR NEW.school_id <> OLD.school_id) THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'A posted opening-balance batch is terminal (G1b): neither its status nor its School can move.\';
                END IF;
             END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::ROWS_NO_DELETE_TRIGGER);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::ROWS_NO_UPDATE_TRIGGER);

        // Restore 110000's narrower trigger verbatim, so a rollback lands on the state that migration
        // left rather than on no guard at all.
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::NO_UNPOST_TRIGGER);
        DB::unprepared(
            'CREATE TRIGGER '.self::NO_UNPOST_TRIGGER.' BEFORE UPDATE ON '.self::BATCHES.'
             FOR EACH ROW
             BEGIN
                IF OLD.status = \'posted\' AND NEW.status <> \'posted\' THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'A posted opening-balance batch is terminal (G1b): its status cannot move out of posted.\';
                END IF;
             END'
        );
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §9 step 4b — the POSTING half of commit 4 (docs/handoff/opening-balance-import-spec.md Rev 4).
 * It adds the terminal state a batch reaches when its balances have been written to the subledger,
 * and the two database guards that make "one posted batch per school" mean what it says.
 *
 * IT DOES NOT ADD THE APPROVAL GATE. §8's maker-checker seam is 4c, and it is indivisible from a
 * Permission case and a Submit* action by two lints (bin/ci-boundary-lint.php's approval-seam-count,
 * and bin/ci-grants-convergence-lint.php). So there is no `approved` state here: today's transition is
 * validated → posted, and 4c inserts approval between the two. A state no code path can reach is a
 * claim about a feature that does not exist — the same reason `posted` itself was absent until now.
 *
 * WHAT CHANGES, and why each one:
 *
 *  1. `posted_at` (nullable timestamp) and `posted_by_user_id` (nullable) record WHEN the batch posted
 *     and WHO posted it. `posted_by_user_id` is a LOOKUP, not an FK, matching
 *     finance_payments.received_by_user_id and this table's own uploaded_by_user_id: attribution must
 *     survive the deletion of a user account, and an FK would turn a departed bursar into a restrict
 *     error on an unrelated write.
 *
 *  2. G1 — AT MOST ONE POSTED BATCH PER SCHOOL, AT THE DATABASE (§9, §11). A STORED generated column
 *     that IS the school when the batch is posted and NULL otherwise, plus a UNIQUE index on it. MySQL
 *     exempts NULLs from a unique index, so unlimited draft / validated / rejected batches coexist and
 *     at most one posted batch per school can exist. The shape is the invoice precedent's
 *     (2026_07_19_120000_slice2_invoice_total_immutable_and_active_enrollment_guard.php:76-81).
 *
 *     THE INDEX IS ON THE GENERATED COLUMN ALONE, not on (school_id, posted_school_key) as the invoice
 *     precedent has it. There the key was the ENROLLMENT and school_id was the partition; here the key
 *     already IS the school, so adding school_id would be redundant and would read as though the
 *     constraint were per-something-else.
 *
 *     `status` collates utf8mb4_unicode_ci, so `status = 'posted'` also matches 'Posted' and 'POSTED'.
 *     That is the safe direction — a case-variant status cannot slip past the key — and it is stated
 *     here rather than found.
 *
 *  3. G1b — THE POSTED STATE IS TERMINAL. Two triggers, and the second one is not in §9's text; see
 *     below for why it had to be. G1 alone is NOT "ever": the generated key is computed from `status`,
 *     so anything that changes or removes the posted row frees the key and a SECOND batch posts —
 *     while the first post's ledger charges CANNOT be removed, because finance_ledger_transactions
 *     denies DELETE by trigger (2026_07_19_100001_create_fee_ledger_transactions_table.php:56-60). The
 *     arrears are then double-counted permanently. That was the Rev 3 finding, and it is why §11's
 *     claim had to be weakened to "releasable by an UPDATE until G1b lands".
 *
 *       - `..._no_unpost` (BEFORE UPDATE) denies any transition OUT of posted. Other columns stay
 *         updatable; only the status move is denied, because 4c's approval screen and U12b will want
 *         to annotate a posted batch without being able to un-post it.
 *       - `..._no_delete_posted` (BEFORE DELETE) denies deleting a posted batch. THIS IS AN ADDITION
 *         TO §9's SHAPE and it is deliberate: an UPDATE guard alone leaves the identical release
 *         reachable through DELETE, which frees the unique key just as completely and additionally
 *         CASCADEs the staged rows away (finance_opening_balance_rows_batch_school_foreign), leaving
 *         the posted ledger charges pointing at rows that no longer exist. Guarding one door and
 *         naming the invariant absolute is the same mistake in the same section twice — and it is the
 *         same lesson as §9's withTrashed note: a guard written against one TOKEN, not against the
 *         BEHAVIOUR, has a hole shaped exactly like the other spelling.
 *
 *     THE INVOICE PRECEDENT IS NOT A COUNTER-EXAMPLE. A voided invoice SHOULD free its active-enrollment
 *     slot, and app/Finance/Enums/InvoiceStatus.php:14-16 calls that correct. A posted batch must NEVER
 *     free its slot. Same mechanism, opposite requirement.
 *
 *  4. `term_id`'s MEANING is RULED — §9's open decision, closed here because this is the first commit
 *     with a caller. THE COLUMN IS REPURPOSED, NOT NULLIFIED: it names THE TERM BEING CLOSED OUT — the
 *     last term, whose closing position the file carries — and keeps its NOT NULL and its FK. The
 *     reasoning is in the ruling below; the column comment is set here so the schema itself says what
 *     the column means, and `--term` is renamed `--closing-term` in the same commit
 *     (app/Finance/Console/ImportOpeningBalances.php) or the column becomes a lie.
 *
 * THE RULING ON `term_id`, in full, because §9 asks for it to be recorded where the change is made:
 * R5 puts the cutover on a term boundary, so there is no cutover term T to name — that is what made
 * the column look wrong. But the file IS the closing position of a SPECIFIC term, and recording WHICH
 * term is exactly the provenance a cutover needs: it is the one fact that lets a reader a year later
 * say what period the opening charges represent. Nulling the column discards that for no gain, and it
 * also discards the FK's referential guarantee and the option's per-School validation. The cost of
 * repurposing is a comment and a rename; the cost of nullifying is a permanent loss of provenance and
 * an ALTER. Option 2 wins on the merits, not on the smaller diff.
 *
 * NOTHING HERE IS APPEND-ONLY. finance_opening_balance_batches carries no immutability trigger and is
 * not meant to — it is a lifecycle DOCUMENT (the shape of finance_invoices / finance_credit_notes /
 * finance_void_requests), so its status moves. The two triggers added here are the ONLY restrictions
 * on that movement, and both exist for the same one-way reason.
 */
return new class extends Migration
{
    private const TABLE = 'finance_opening_balance_batches';

    private const KEY_COLUMN = 'posted_school_key';

    private const UNIQUE_INDEX = 'ob_batches_posted_school_unique';

    private const NO_UNPOST_TRIGGER = 'finance_opening_balance_batches_no_unpost';

    private const NO_DELETE_TRIGGER = 'finance_opening_balance_batches_no_delete_posted';

    /**
     * The repurposed meaning, carried in the schema itself. A comment is not a constraint and is not
     * dressed as one — it is what a reader of `SHOW CREATE TABLE` sees, which is where someone who
     * doubts the column's meaning actually looks.
     */
    private const TERM_COMMENT = 'The term being CLOSED OUT: the last term, whose closing position this file carries (spec §9, ruled in step 4b). NOT a cutover term T — R5 puts the cutover on a term boundary.';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            // Both nullable and both NULL until the batch posts. A non-null posted_at on a batch whose
            // status is not `posted` would be a contradiction, but it is not constrained here: the only
            // writer is PostOpeningBalanceBatch, which sets all three in one statement inside one
            // transaction, and a CHECK over a column the app already writes atomically would be
            // decoration. The invariant that matters — you cannot leave `posted` — is a trigger below.
            $table->timestamp('posted_at')->nullable()->after('findings');
            $table->unsignedBigInteger('posted_by_user_id')->nullable()->after('posted_at'); // LOOKUP, not an FK
        });

        // G1. STORED (not VIRTUAL): a unique index over a virtual column is legal in MySQL 8 but the
        // stored form matches the invoice precedent byte for byte, and the value is read by U12b.
        DB::statement(
            'ALTER TABLE '.self::TABLE.'
                ADD COLUMN '.self::KEY_COLUMN." BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = 'posted', school_id, NULL)) STORED"
        );
        DB::statement(
            'ALTER TABLE '.self::TABLE.'
                ADD UNIQUE '.self::UNIQUE_INDEX.' ('.self::KEY_COLUMN.')'
        );

        // G1b, door 1 — the status cannot move OUT of posted.
        //
        // TWO CONSTRAINTS ON THE MESSAGE TEXT, both learned by watching them bite:
        //
        //  - NO APOSTROPHE. MySQL stores a trigger body with the escape STRIPPED, so an apostrophe
        //    survives CREATE and then breaks mysqldump — pinned by TriggerBodiesAreDumpSafeTest.
        //  - AT MOST 128 CHARACTERS. MESSAGE_TEXT is a varchar(128); a longer literal makes the
        //    SIGNAL itself fail with driver code 1648 ("Data too long for condition item") INSTEAD OF
        //    1644. The write is still refused, so the guard looks fine — but every assertion, log and
        //    runbook that reads 1644 as "a house trigger refused this" sees an unrecognised error.
        //    The first draft of these two messages carried the full reasoning inline and did exactly
        //    that. The reasoning belongs in this docblock; the message names the rule and stops.
        DB::unprepared(
            'CREATE TRIGGER '.self::NO_UNPOST_TRIGGER.' BEFORE UPDATE ON '.self::TABLE.'
             FOR EACH ROW
             BEGIN
                IF OLD.status = \'posted\' AND NEW.status <> \'posted\' THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'A posted opening-balance batch is terminal (G1b): its status cannot move out of posted.\';
                END IF;
             END'
        );

        // G1b, door 2 — a posted batch cannot be deleted. See the header: DELETE frees the same unique
        // key the UPDATE guard protects, and additionally CASCADEs the staged rows the posted ledger
        // charges point at.
        DB::unprepared(
            'CREATE TRIGGER '.self::NO_DELETE_TRIGGER.' BEFORE DELETE ON '.self::TABLE.'
             FOR EACH ROW
             BEGIN
                IF OLD.status = \'posted\' THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'A posted opening-balance batch is terminal (G1b): it cannot be deleted.\';
                END IF;
             END'
        );

        // The term_id ruling, in the schema. BIGINT UNSIGNED NOT NULL is restated exactly as
        // foreignId() built it (2026_08_06_100000:102) — MODIFY replaces the whole definition, so a
        // narrower type here would silently alter the column under its own FK.
        DB::statement(
            'ALTER TABLE '.self::TABLE." MODIFY term_id BIGINT UNSIGNED NOT NULL COMMENT '".self::TERM_COMMENT."'"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.self::TABLE.' MODIFY term_id BIGINT UNSIGNED NOT NULL COMMENT \'\'');

        DB::unprepared('DROP TRIGGER IF EXISTS '.self::NO_DELETE_TRIGGER);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::NO_UNPOST_TRIGGER);

        // Index before the column it is built on.
        DB::statement('ALTER TABLE '.self::TABLE.' DROP INDEX '.self::UNIQUE_INDEX);
        DB::statement('ALTER TABLE '.self::TABLE.' DROP COLUMN '.self::KEY_COLUMN);

        // A batch that reached `posted` cannot be rolled back to a schema with no posted state and
        // still mean anything, so this down() says what it is doing rather than pretending otherwise:
        // it restores the SHAPE. Any row left at `posted` keeps that string in a column whose enum no
        // longer has the case, which is exactly what a rollback of a state-adding migration leaves
        // behind everywhere in this repository — it is visible, not silent.
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn(['posted_at', 'posted_by_user_id']);
        });
    }
};

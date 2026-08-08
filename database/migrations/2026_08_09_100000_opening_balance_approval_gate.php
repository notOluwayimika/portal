<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §9 step 4c — the APPROVAL half of commit 4 (docs/handoff/opening-balance-import-spec.md Rev 4, §8).
 * It adds the four decision columns a maker-checker document needs, and the one constraint that makes
 * maker ≠ checker a database fact rather than a code convention.
 *
 * THE STATE IT SERVES IS `submitted`, ADDED IN THE ENUM AND NOT HERE, because `status` is a plain
 * `string` column with no CHECK and no MySQL ENUM behind it (2026_08_06_100000:89). There is therefore
 * nothing to widen: the schema already accepts any string, and the set of legal values is
 * OpeningBalanceBatchStatus. That is stated rather than left for a reader to verify, because the
 * absence of a migration for a new state looks like an omission until you know why.
 *
 * WHAT CHANGES:
 *
 *  1. `submitted_by_user_id` / `submitted_at` — WHO proposed the cutover and WHEN. `submitted_at` is a
 *     real column rather than a reuse of `created_at`: the batch row is created by the VALIDATOR, at
 *     upload, and submission happens later (a batch can sit `validated` while a human reads its
 *     findings). On the other four request tables `created_at` IS the submission time, which is why
 *     none of them carries this column — the difference is real, not stylistic.
 *
 *  2. `decided_by_user_id` / `decided_at` / `rejection_reason` — the checker's half, the same three
 *     columns finance_fee_schedule_changes / _void_requests / _discount_policy_changes carry.
 *
 *     `rejection_reason` DOUBLES AS THE DISCRIMINATOR between the two ways a batch reaches `rejected`:
 *     the validator's structural rejection (reason NULL, decided_by NULL) and a checker's governance
 *     rejection (both non-null). 4c reuses `rejected` rather than coining `declined` because §8 asks
 *     for one terminal refusal state and a second one would double every "did this batch post?" query
 *     for no gain — but the two paths must remain distinguishable, and these columns are how.
 *
 *  3. THE MAKER ≠ CHECKER CHECK — `submitted_by_user_id <> decided_by_user_id` where both are present.
 *     Byte-for-byte the predicate the other four approval tables carry
 *     (2026_07_28_120000:64), and the reason it is at the database is that the Action's own refusal and
 *     the DutySeparation grant guard are both PHP: one deleted `if` and self-approval of an
 *     irreversible posting becomes reachable. ApprovalRequirement's docblock depends on this CHECK
 *     existing on EVERY approval table — a straight-through rule can never be "the maker approves
 *     itself" because the row is unrepresentable.
 *
 * WHY THE TWO USER COLUMNS ARE `*_user_id` LOOKUPS AND NOT `submitted_by` / `decided_by` FKs, which is
 * where this table diverges from the other four and the divergence is deliberate. This table already
 * carries `uploaded_by_user_id` and `posted_by_user_id`, both nullable lookups with no FK, for the
 * reason 2026_08_08_110000 records: "attribution must survive the deletion of a user account, and an
 * FK would turn a departed bursar into a restrict error on an unrelated write". A cutover is a
 * once-in-a-school's-lifetime event whose audit trail must outlive the staff who ran it by years.
 * Two columns on one table following one convention and two following another would be the worse
 * outcome, so 4c follows the table it is on. The CHECK does not need an FK to work.
 *
 * `posted_by_user_id` STAYS, and is not replaced by `decided_by_user_id`. On the approve path they are
 * the same person and the same instant by construction — approval posts in one transaction — but they
 * answer different questions ("who authorised this?" vs "who ran the write?") and only one of them is
 * set on the reject path. PostOpeningBalanceBatch is still callable with an explicit actor, so
 * collapsing them would put an assumption about the caller into the schema.
 *
 * G1 AND G1b ARE UNTOUCHED. The generated `posted_school_key` reads `status`, and `submitted` is not
 * `posted`, so an unlimited number of submitted batches coexist exactly as validated ones do — the
 * gate constrains WHO, not HOW MANY, and one-posted-per-school is still the database's answer to that.
 * The `..._no_unpost` trigger fires only when OLD.status = 'posted', so validated → submitted →
 * posted/rejected all pass it untouched.
 *
 * REVERSIBILITY. down() drops the CHECK first (a constraint over columns that are about to go), then
 * the five columns. A batch left at `submitted` keeps that string in a column whose enum no longer has
 * the case — the same visible, non-silent residue 2026_08_08_110000's own down() leaves for `posted`,
 * and for the same reason: a state-adding migration cannot un-say a state a row already reached.
 */
return new class extends Migration
{
    private const TABLE = 'finance_opening_balance_batches';

    private const MAKER_NE_CHECKER = 'finance_opening_balance_batches_maker_ne_checker';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('submitted_by_user_id')->nullable()->after('findings'); // LOOKUP, not an FK
            $table->timestamp('submitted_at')->nullable()->after('submitted_by_user_id');
            $table->unsignedBigInteger('decided_by_user_id')->nullable()->after('submitted_at');  // LOOKUP, not an FK
            $table->timestamp('decided_at')->nullable()->after('decided_by_user_id');
            $table->string('rejection_reason')->nullable()->after('decided_at');
        });

        // The real maker ≠ checker control. The Action's refusal is the friendly layer above it.
        DB::statement(
            'ALTER TABLE '.self::TABLE.'
                ADD CONSTRAINT '.self::MAKER_NE_CHECKER.'
                CHECK (submitted_by_user_id IS NULL
                    OR decided_by_user_id IS NULL
                    OR submitted_by_user_id <> decided_by_user_id)'
        );
    }

    public function down(): void
    {
        // 8.0.43 rejects `DROP CHECK … IF EXISTS` (1064), so this is unconditional — and it must run
        // BEFORE the columns it constrains are dropped.
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CHECK '.self::MAKER_NE_CHECKER);

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn([
                'submitted_by_user_id',
                'submitted_at',
                'decided_by_user_id',
                'decided_at',
                'rejection_reason',
            ]);
        });
    }
};

<?php

use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Models\Concerns\AppendOnly;
use App\Finance\Services\AllocationProposal;
use App\Finance\Services\FeeScheduleLineMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S11 — WHERE THIS LINE'S MONEY WAS DESTINED, snapshotted onto the line at issue.
 *
 * COMMIT 1 OF 2. This migration adds the column NULLABLE and both writers start populating it. The
 * TRIGGER that requires it on charge lines is commit 2 and is deliberately not here: a trigger added
 * ahead of its writers breaks the generate modal the moment it lands. That is the same
 * expand-then-contract split `2026_08_10_100000` (table and screen) → `2026_08_10_120000` (the
 * constraint) already used on this same subject.
 *
 * ─── WHY NOW, AND WHY IT COULD NOT BE NOW BEFORE ─────────────────────────────────────────────────
 *
 * `2026_08_10_120000` §"finance_invoice_lines — DELIBERATELY NOT IN SCOPE" refused this column for
 * two reasons, and BOTH HAVE EXPIRED:
 *
 *   - "line entry is manual with NO fee catalog, so every line today is free text with no fee item
 *     behind it" — {@see FeeScheduleLineMapper::linesFor()} now turns a fee
 *     schedule into lines, and every line a bulk run issues cites an item;
 *   - "a per-line selector on a modal with nothing to select from" — bank-accounts.tsx shipped, and
 *     the modal now has a catalog to offer.
 *
 * What that migration got RIGHT and this one keeps is the refusal to fabricate: it would not accept
 * a per-school default, because "a default here fabricates a destination nobody chose". Neither
 * writer here defaults. The schedule-derived line INHERITS the item's account; the manual line
 * carries the one the operator SELECTED; a reduction line carries null.
 *
 * ─── WHAT THE LIVE LOOKUP CANNOT ANSWER, WHICH IS THE WHOLE POINT ────────────────────────────────
 *
 * Until this column, the only available destination was a live join through
 * `finance_invoice_lines.fee_item_id` → `finance_fee_items.bank_account_id`, and
 * {@see AllocationProposal}'s docblock is blunt about what that answers:
 * "where would this charge's money go if it were billed from the catalog as it stands today" — NOT
 * "where was it destined when it was billed". `finance_invoice_lines` is append-only, so a line can
 * never be corrected afterwards, which is what makes the gap permanent rather than merely wrong.
 *
 * TWO THINGS ARE WRONG WITH THAT JOIN AND ONLY ONE OF THEM IS THE ONE USUALLY CITED. The measured
 * version, because the difference decides how urgent each half is:
 *
 *   - ABSENCE, which is the LARGER half and is not about mutation at all. `fee_item_id` is nullable
 *     with NO foreign key, and every line the bursar's generate modal writes today has none. For
 *     those lines the join cannot answer at all — AllocationProposal reports `unrecorded` — and no
 *     amount of immutability upstream would change that.
 *
 *   - MUTATION, which is real but NARROWER THAN THE TICKET AND AllocationProposal BOTH SAY. They
 *     say a fee item's account "can be edited" after billing; measured, through the application it
 *     cannot. `finance_fee_items_parent_state_guard_upd` refuses any UPDATE whose parent schedule is
 *     not a `draft`, only `active` schedules are billable
 *     ({@see FeeScheduleStatus::billable()}), and nothing returns an active
 *     schedule to draft (`RejectFeeScheduleChange` restores only `pending_approval`).
 *
 * The mutation half is kept as a reason anyway, and deliberately, because that freeze is a
 * COINCIDENCE OF TWO INDEPENDENT RULES rather than a stated one: the trigger's rule is "the parent
 * must be a draft" and nothing anywhere ties it to "cited by an invoice". It holds only while
 * `billable()` is exactly `[active]` — and that method's own docblock says the set MOVES — and while
 * no correction path returns a schedule to draft. Either change turns a silent rewrite of history
 * back on with nothing going red, because no test asserts the two rules as one property. That is the
 * same shape as the mapper's `isBillable` / `where('status', 'active')` pair, which two docblocks
 * called "one rule, not two" and which were in fact two rules that happened to agree. A raw UPDATE,
 * a migration or tinker meets no such guard at all.
 *
 * The deadline is real and it is why this lands today rather than after the constraint work:
 * production has issued no invoices (measured 28 August 2026 — zero bank accounts, zero fee items,
 * zero schedules; docs/handoff/reply-to-developer-2-destination-accounts.md §4). Every invoice
 * issued before this column exists is permanently silent about its destination, and that window
 * closes at the first bulk run.
 *
 * ─── NULLABLE, AND NOT AS A WEAKER NOT NULL ──────────────────────────────────────────────────────
 *
 * Nullable is what a nullable column means here and nothing more: the RULE arrives in commit 2 as a
 * trigger, keyed on `kind`, because NOT NULL cannot state it. A reduction line has no destination —
 * whether a waiver should inherit the account of the charge it offsets is UNMODELLED and UNANSWERED
 * — and a NOT NULL column with no defensible answer for an entire line kind is a column that forces
 * an invented one. The precedent is `finance_payments.bank_account_id` on this same subject: a
 * nullable column whose real rule is an origin-keyed CHECK, which "is not a weaker NOT NULL — it is
 * a DIFFERENT and stronger statement" (`2026_08_10_120000`:20-24). Same shape, keyed on `kind`
 * rather than `origin`, and expressed as a trigger because the reduction guard next door
 * (`2026_07_26_140002`) already reads `kind` that way.
 *
 * ─── DO NOT BACKFILL. ────────────────────────────────────────────────────────────────────────────
 *
 * Lines issued before this column existed have no recorded destination and never will have one.
 * NULL is the honest answer and it is the intended permanent state of those rows.
 *
 * A backfill through `fee_item_id` would be worse than the gap it closes, because it would look like
 * a record. It would write TODAY'S catalog reading into a column whose entire purpose is to say what
 * was true AT ISSUE — manufacturing exactly the false history this column exists to prevent, and
 * doing it on an append-only table where it can never be undone. A free-text line has no fee item to
 * read at all, so a backfill could not even be uniform. Leave them null.
 *
 * The consequence for readers, stated so it is not rediscovered as a bug: a NULL here means "not
 * recorded", never "no destination" and never "the default account". Commit 2's trigger is what
 * makes NULL-on-a-charge-line impossible for lines written AFTER it; it says nothing about lines
 * written before, and it deliberately does not try to.
 *
 * ─── THE FOREIGN KEY IS COMPOSITE ────────────────────────────────────────────────────────────────
 *
 * (bank_account_id, school_id) -> finance_bank_accounts (id, school_id), which is this repository's
 * uniform pattern for a child pointing at a School-owned parent — argued at `2026_08_10_120000`:64-73
 * (the convention lives in that migration's docblock, NOT in docs/finance-data-ownership.md, whose
 * six day-one rules say nothing about composite keys). A plain single-column FK would be satisfied
 * by one school's invoice line naming another school's account: the composite makes that pair
 * non-existent in the parent, so the DATABASE refuses it (1452) rather than the application being
 * trusted to.
 *
 * THE PARENT KEY ALREADY EXISTS — `finance_bank_accounts_id_school_unique` on (id, school_id) was
 * added by `2026_08_10_120000`:87 for the payments and fee-items FKs, and
 * `2026_08_29_100000` reused it for settlement. This migration adds NO key to the parent; it is the
 * fourth referrer of that one, named to match its siblings ({table}_{column}_school_foreign).
 *
 * ON DELETE RESTRICT, per docs/finance-data-ownership.md rule 1 and the nine sibling FKs. There is
 * no destroy route for a bank account and there must never be one — an account that has received
 * money must stay nameable forever, and retirement is `deactivated_at` (`2026_08_10_100000`
 * docblock). RESTRICT is belt to that braces: it makes the rule true against a hand-written DELETE,
 * and on THIS table it is the stronger half of the pair, because an invoice line cannot be repointed
 * or removed afterwards. A cascade here would silently erase the destination of a billed charge; a
 * SET NULL would rewrite it into the "not recorded" state the paragraph above reserves for history.
 * Both would be an append-only table quietly losing a fact. RESTRICT refuses the DELETE instead.
 *
 * ─── THE APPEND-ONLY GUARD NEEDS NOTHING ─────────────────────────────────────────────────────────
 *
 * Surveyed rather than assumed. Both layers are BLANKET and neither enumerates columns:
 * {@see AppendOnly} throws on every `updating`/`deleting`, and
 * `finance_invoice_lines_no_update` / `_no_delete` are bare SIGNAL '45000' triggers
 * (`2026_07_19_110000`:36-37). So there is no whitelist to extend — the column is settable at INSERT
 * and frozen thereafter, which IS the snapshot. And ALTER TABLE is DDL: it does not fire a DML
 * trigger, the precedent being `2026_07_26_120000`:21-22 adding `created_by_user_id` to this same
 * table.
 */
return new class extends Migration
{
    private const FOREIGN = 'finance_invoice_lines_bank_account_school_foreign';

    public function up(): void
    {
        Schema::table('finance_invoice_lines', function (Blueprint $table) {
            // Beside `fee_item_id`, the provenance it supersedes as a destination answer.
            $table->foreignId('bank_account_id')->nullable()->after('fee_item_id');

            $table->foreign(['bank_account_id', 'school_id'], self::FOREIGN)
                ->references(['id', 'school_id'])->on('finance_bank_accounts')
                ->restrictOnDelete();
        });
    }

    /**
     * Drop an index only if it is still there.
     *
     * ORDER IS EVERYTHING HERE, and it is carried from `2026_08_10_120000`'s audit rather than
     * re-reasoned. Creating a composite FK makes MySQL build a supporting index named after the
     * constraint over (bank_account_id, school_id). `dropForeign` removes the constraint and LEAVES
     * that index; if the column is dropped next, the index SHRINKS to (school_id) instead of
     * disappearing, MySQL adopts it as the supporting index for `finance_invoice_lines`'s existing
     * school FK, and it can then never be dropped at all (1553, "needed in a foreign key
     * constraint"). The name is permanently taken, so the next up() cannot create its foreign key
     * and reports success having produced a column with no constraint behind it.
     *
     * So the index is dropped BETWEEN the foreign key and the column, while it is still the full
     * composite and nothing else has claimed it.
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
        Schema::table('finance_invoice_lines', function (Blueprint $table) {
            $table->dropForeign(self::FOREIGN);
        });

        $this->dropIndexIfPresent('finance_invoice_lines', self::FOREIGN);

        Schema::table('finance_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('bank_account_id');
        });
    }
};

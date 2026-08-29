<?php

use App\Finance\Services\SettlementBankAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE GATEWAY MONEY SETTLES — the one datum the gateway path cannot be built without.
 *
 * `2026_08_25_100000` added a third payment origin, and its trigger pair
 * (`finance_payments_origin_pairing_bi` / `_bu`) requires `bank_account_id IS NOT NULL` on the
 * `gateway` arm exactly as it does on `portal`. And the recording path demands one: a non-nullable
 * `int $bankAccountId` on handle (app/Finance/Actions/RecordPayment.php:77). A portal payment gets
 * that id from a bursar choosing it on a screen; a gateway payment has no operator in the room. Nothing
 * anywhere said WHICH account, so the gateway path had a required argument with no source. This
 * column is that source.
 *
 * ─── NULLABLE, AND THAT IS THE HONEST SHAPE ──────────────────────────────────────────────────────
 *
 * "No settlement account configured" is a real and CURRENT state: production was measured on
 * 28 August 2026 with zero bank accounts, zero fee items and no prefix set — the finance module has
 * never been configured there (docs/handoff/reply-to-developer-2-destination-accounts.md §4). A
 * NOT NULL column would have to invent a value for every existing settings row, which is the
 * fabrication that NOT-NULL-with-no-default exists to prevent, and it would be inventing the
 * destination of real money.
 *
 * So the column admits null and the RESOLVER refuses loudly
 * ({@see SettlementBankAccount::forSchool()}). The refusal is the feature: a
 * null that throws is a school that has not chosen, which is different from and better than a
 * column pretending someone did.
 *
 * ─── THE FOREIGN KEY IS COMPOSITE, AND NOT FOR THE USUAL REASON ──────────────────────────────────
 *
 * `finance_school_settings` is a TOP-LEVEL Finance table: it owns `school_id` directly under a plain
 * RESTRICT FK and `UNIQUE(school_id)`, and the composite `(child_id, school_id) -> parent(id,
 * school_id)` pattern that `2026_08_10_120000` describes belongs to CHILD tables pointing back at a
 * school-owned parent. On the table's own `school_id` this table is correctly not composite.
 *
 * Composite is still right HERE, and the reason is the TARGET rather than the table.
 * `finance_bank_accounts` is school-scoped. A plain single-column FK to `finance_bank_accounts (id)`
 * would let school A's settings row name school B's account, and every gateway payment school A
 * recorded would then settle into school B's bank with NOTHING refusing it — not the FK, which was
 * satisfied, and not the application, which had already read the id out of its own settings row.
 * The composite makes that pair non-existent in the parent, so the database refuses the write
 * (1452) rather than the money going to the wrong school.
 *
 * The parent side is already there: `finance_bank_accounts_id_school_unique` on (id, school_id) was
 * added by `2026_08_10_120000` for the payments and fee-items FKs. This migration adds no key to
 * the parent — it reuses the one the siblings established, and is named to match them
 * ({table}_{column}_school_foreign).
 *
 * ─── ON DELETE RESTRICT, ARGUED ──────────────────────────────────────────────────────────────────
 *
 * There is no destroy route for a bank account and there must never be one
 * (`2026_08_10_100000` docblock): an account that has received money must stay nameable forever, and
 * retirement is `deactivated_at`. RESTRICT is belt to that braces — it makes the rule true even for
 * a hand-written DELETE, and it is what the nine sibling FKs already carry.
 *
 * The two alternatives are both worse, and each in a specific way:
 *
 *   - CASCADE would delete THE SETTINGS ROW. Not the column — the row. So deleting a bank account
 *     would silently take `invoice_number_prefix` with it, and every invoice already issued by that
 *     school would re-render with a different display number (the number is stored bare and the
 *     prefix applied at render — Invoice::displayNumber()). A bank-account delete wiping a school's
 *     invoice numbering is a configuration loss nobody could predict from the gesture that caused
 *     it.
 *
 *   - SET NULL would quietly unconfigure settlement. The resolver would then throw, so the failure
 *     is at least loud — but it makes the hard delete POSSIBLE, which is the thing commit 1 spent a
 *     docblock forbidding, and it converts "an operator chose this account" into "nobody has chosen
 *     yet" with no trace that a choice was ever made.
 *
 * A deactivated account is the ordinary retirement case and does not delete, so RESTRICT costs
 * nothing operationally. It only ever fires on the delete that should not have been attempted.
 *
 * ─── ADDITIVE, AND SAFE AGAINST LIVE DATA ────────────────────────────────────────────────────────
 *
 * One nullable column and one foreign key. `finance_school_settings` carries no immutability trigger
 * (it is configuration, not an event log — it is deliberately absent from the append-only family),
 * so nothing here contends with one. Existing rows get NULL and behave exactly as they do today:
 * the prefix path is untouched and the gateway path was not reachable before this landed.
 */
return new class extends Migration
{
    private const FOREIGN = 'finance_school_settings_settlement_bank_account_school_foreign';

    public function up(): void
    {
        Schema::table('finance_school_settings', function (Blueprint $table) {
            $table->foreignId('settlement_bank_account_id')->nullable()->after('invoice_number_prefix');

            $table->foreign(['settlement_bank_account_id', 'school_id'], self::FOREIGN)
                ->references(['id', 'school_id'])->on('finance_bank_accounts')
                ->restrictOnDelete();
        });
    }

    /**
     * Drop an index only if it is still there.
     *
     * ORDER IS EVERYTHING, and this is carried verbatim in spirit from `2026_08_10_120000`, whose
     * rollback audit measured the failure rather than reasoning about it.
     *
     * Creating a composite FK makes MySQL build a supporting index named after the constraint, over
     * (settlement_bank_account_id, school_id). `dropForeign` removes the constraint and LEAVES that
     * index. If the column is dropped next the index SHRINKS to (school_id) rather than
     * disappearing, and MySQL can then adopt it as the supporting index for the table's own
     * `school_id` constraints — after which `DROP INDEX` fails 1553, "needed in a foreign key
     * constraint", the name is permanently taken, and the next `up()` reports success having created
     * a column with no foreign key behind it.
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
        Schema::table('finance_school_settings', function (Blueprint $table) {
            $table->dropForeign(self::FOREIGN);
        });

        $this->dropIndexIfPresent('finance_school_settings', self::FOREIGN);

        Schema::table('finance_school_settings', function (Blueprint $table) {
            $table->dropColumn('settlement_bank_account_id');
        });
    }
};

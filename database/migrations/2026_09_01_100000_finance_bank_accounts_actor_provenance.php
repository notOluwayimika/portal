<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO created, last changed, and retired the account money lands in.
 *
 * `finance_bank_accounts` shipped with `deactivated_at` and no `deactivated_by`, and with no actor
 * on creation or edit at all. Brookstone supply the production account this week; without these
 * columns the row that names where every naira of fee income arrives is authored by nobody.
 *
 * ONE PREMISE IN THE BRIEF FOR THIS CHANGE IS WRONG AND IS CORRECTED HERE: the table is not
 * trigger-less. `2026_08_10_110000_finance_bank_account_identity_is_immutable` installs
 * `finance_bank_accounts_identity_immutable`, a BEFORE UPDATE trigger refusing changes to
 * `bank_name` and `account_number`. What it guards is IDENTITY, not PROVENANCE — it stops the
 * account being restated, and says nothing about who did anything. Both are needed and neither
 * substitutes for the other.
 *
 * ─── THREE COLUMNS, NOT ONE, AND THE THIRD IS THE ONE THAT EARNS ITS KEEP ────────────────────────
 *
 *   created_by_user_id      who added this account
 *   updated_by_user_id      who last changed it, by ANY act — edit, deactivate or reactivate
 *   deactivated_by_user_id  who took it out of use
 *
 * `deactivated_by_user_id` is not redundant against `updated_by_user_id`, and the case that
 * separates them is ordinary: retire an account in March, correct its label in September, and
 * `updated_by_user_id` now names the September editor. "Who stopped us using this account" is the
 * question a reconciliation asks, and it would have no answer left. The column pairs with the
 * `deactivated_at` the create migration already argued for on exactly the same grounds — a
 * timestamp rather than a boolean, because "when did we stop" is a question a reconciliation asks.
 * This is its other half.
 *
 * Reactivation CLEARS both `deactivated_at` and `deactivated_by_user_id`, because the pair
 * describes the CURRENT retirement and there is none. The history of every retirement and
 * restoration lives in `activity_log` — these columns answer "what is true now", the log answers
 * "what has this ever been". Two questions, two readers, deliberately not one mechanism.
 *
 * ─── THE HOUSE CONVENTION FOR AN ATTRIBUTION, FOLLOWED EXACTLY ───────────────────────────────────
 *
 * Plain `unsignedBigInteger`, nullable, NO `constrained()`. Same as
 * `finance_invoices.cancelled_by_user_id`, `finance_payments.received_by_user_id`,
 * `finance_credit_notes.created_by_user_id` and `finance_invoice_lines.created_by_user_id`
 * (`2026_07_26_120000`, which states the rule): an attribution must never block a user's lifecycle.
 * A leaver is deactivated, and a foreign key here would make that deletion fail — or, worse, invite
 * a cascade that erased the attribution to make it succeed.
 *
 * NULLABLE, and the null is honest rather than convenient: every row written before this migration
 * has no known actor, and back-filling a guess would be a fabricated audit trail, which is worse
 * than an admitted gap. Production was measured on 28 August 2026 with ZERO bank accounts
 * (docs/handoff/reply-to-developer-2-destination-accounts.md §4), so in practice the null set is
 * empty there — but the column must be honest on every environment, not just the one.
 *
 * NO INDEX, DELIBERATELY. `2026_07_26_120000` indexes `(school_id, created_by_user_id)` because
 * `finance_invoice_lines` has one row per billed line per pupil and "what did this bursar raise"
 * is a real query over a large table. A school has a handful of bank accounts; an index here would
 * be write cost for a scan that reads three rows.
 *
 * ─── SAFE AGAINST LIVE DATA ──────────────────────────────────────────────────────────────────────
 *
 * Three nullable columns, no constraints, no back-fill. `ALTER TABLE` is DDL and does not fire the
 * identity-immutable BEFORE UPDATE trigger, which is DML — the same precedent `2026_07_26_120000`
 * cites for `finance_invoice_lines` under its own append-only trigger. Existing rows get NULL and
 * behave exactly as they do today.
 */
return new class extends Migration
{
    private const TABLE = 'finance_bank_accounts';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('account_name');
            $table->unsignedBigInteger('updated_by_user_id')->nullable()->after('created_by_user_id');
            $table->unsignedBigInteger('deactivated_by_user_id')->nullable()->after('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn(['created_by_user_id', 'updated_by_user_id', 'deactivated_by_user_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO chose the account a school's gateway money settles into, and WHEN.
 *
 * `2026_08_29_100000` added `settlement_bank_account_id` — the column that decides where every
 * naira of gateway fee income lands. It has had, since the day it landed, NO WRITER ANYWHERE IN THE
 * CODEBASE: the only references are the resolver that reads it and the tests that plant it
 * (`SettlementBankAccount::forSchool()`, `SettlementBankAccountTest`). It is set by direct SQL, and
 * Brookstone's production account is configured this week.
 *
 * A column set by hand, on a table that deliberately carries no immutability trigger, records
 * nothing about who set it.
 *
 * ─── WHY PROVENANCE HERE CANNOT BE "THE ROW CANNOT CHANGE" ───────────────────────────────────────
 *
 * `2026_08_29_100000`'s own docblock states it: `finance_school_settings` carries no immutability
 * trigger, because it is CONFIGURATION and not an event log — it is deliberately absent from the
 * append-only family, and it must stay so, since a school changing where it banks is a legitimate
 * act rather than a restatement of history. So the audit trail here cannot be "this cannot be
 * rewritten". It has to be a RECORDED ACT: who did it, when, and — in `activity_log` — what it was
 * before.
 *
 * ─── TWO COLUMNS PLUS AN ACTIVITY ENTRY IS NOT TWO SPELLINGS OF ONE FACT ─────────────────────────
 *
 * They answer different questions and have different readers:
 *
 *   these columns   "who set the account we are settling into RIGHT NOW, and when"
 *                   — one row, one join, readable by anyone looking at the configuration.
 *   activity_log    "what has this school's settlement account EVER been"
 *                   — the sequence, with from/to on each transition, readable by the auditor.
 *
 * A settings row can only ever hold the current answer; it is one row per school by UNIQUE(school_id)
 * and every write overwrites. The log is the only place the previous destination survives. Neither
 * is derivable from the other, which is why both are written.
 *
 * ─── SHAPE ──────────────────────────────────────────────────────────────────────────────────────
 *
 * `settlement_bank_account_set_by_user_id` is a plain nullable `unsignedBigInteger` with no
 * `constrained()` — the house convention for an attribution, stated at
 * `2026_07_26_120000_add_created_by_to_finance_invoice_lines`: an attribution must never block a
 * user's lifecycle.
 *
 * `settlement_bank_account_set_at` is a separate timestamp rather than leaning on `updated_at`,
 * because `updated_at` moves when `invoice_number_prefix` changes and would then claim the
 * settlement account was chosen on a day nobody touched it. The same reasoning that gave
 * `finance_bank_accounts` its own `deactivated_at` instead of reading `updated_at`.
 *
 * Both nullable: a school that has not configured settlement has no actor and no date, and that is
 * the state production is in today (measured 28 August 2026 — zero bank accounts, no settlement
 * configured). Inventing either would be a fabricated trail.
 *
 * NO INDEX: one row per school by UNIQUE(school_id). There is nothing to narrow.
 *
 * Additive and safe against live data — two nullable columns, no constraints, no back-fill, and no
 * trigger on this table to contend with.
 */
return new class extends Migration
{
    private const TABLE = 'finance_school_settings';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('settlement_bank_account_set_by_user_id')
                ->nullable()->after('settlement_bank_account_id');

            $table->timestamp('settlement_bank_account_set_at')
                ->nullable()->after('settlement_bank_account_set_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn([
                'settlement_bank_account_set_by_user_id',
                'settlement_bank_account_set_at',
            ]);
        });
    }
};

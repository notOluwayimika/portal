<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S6 / U3 — the accounts money actually lands in. COMMIT 1 OF 2, and the split is the point.
 *
 * Commit 2 makes `bank_account_id` NOT NULL on payments, fee items and invoice lines. A required
 * foreign key with nothing to point at breaks the payment path on the day it ships — exactly as a
 * required `received_at` did in #229, and as an .xlsx template the screen's own upload refused did
 * in #223. So this commit creates the thing to point AT and changes nothing else: afterwards the
 * system behaves identically and there is a way to create a bank account.
 *
 * THERE IS NO DELETE, AND THERE MUST NEVER BE ONE. A bank account that has received money has to
 * stay nameable forever: a payment reconciled against it in March is unexplainable in September if
 * the account it names has been erased, and finance_payments is append-only so the reference cannot
 * be rewritten to something else. Retirement is `deactivated_at` — the row survives, stops being
 * offerable, and every historical reference still resolves.
 *
 * So: do NOT add a destroy route. If one is ever proposed, the question to ask is "what does the
 * March statement say when its account is gone", and the answer is why this paragraph exists.
 *
 * WHAT IT CARRIES IS DECIDED BY RECONCILIATION, which is the entire reason the table exists. A
 * bursar sits with a bank statement in one hand and this system in the other; every column here is
 * something they need to match the two. `account_number` and `bank_name` identify the account on the
 * statement; `label` is what the UI shows, because "Zenith — Fees" is what an operator recognises
 * and a ten-digit number is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // School-scoped like every other finance table (arch rule 5 — uniform `school_id`).
            // Bank accounts are a school's own banking arrangements; there is no platform-level one.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();

            // What the OPERATOR recognises. Shown everywhere the account is offered or displayed.
            $table->string('label');

            // What the BANK STATEMENT shows. These two are the reconciliation keys — without them
            // this table is a list of nicknames and a bursar cannot match a line to an account.
            $table->string('bank_name');
            $table->string('account_number');

            // The name the account is held in, which is not always the school's registered name and
            // is what a payer sees on a transfer screen. Nullable: it is a nicety for the payer, not
            // a reconciliation key, and demanding it would block a school that only has the number.
            $table->string('account_name')->nullable();

            // RETIREMENT, NOT DELETION — see the docblock. Null means active and offerable.
            // A timestamp rather than a boolean because "when did we stop using this account" is a
            // question a reconciliation asks, and a boolean cannot answer it.
            $table->timestamp('deactivated_at')->nullable();

            $table->timestamps();

            // Two accounts in one school must not share a number: it is the reconciliation key, and
            // duplicates make a statement line ambiguous. Scoped to the school, because two schools
            // may legitimately bank the same account.
            $table->unique(['school_id', 'account_number']);

            // The list screen's query: a school's accounts, active ones first.
            $table->index(['school_id', 'deactivated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_bank_accounts');
    }
};

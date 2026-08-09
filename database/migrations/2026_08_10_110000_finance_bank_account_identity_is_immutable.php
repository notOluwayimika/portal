<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A bank account's IDENTIFYING fields cannot change, from the moment the row exists.
 *
 * `bank_name` and `account_number` are what a bursar matches a bank statement against. If they can
 * be edited after payments point at the account, changing them SILENTLY REWRITES WHERE HISTORICAL
 * MONEY WENT — the March reconciliation now claims a different bank, and nothing in the audit trail
 * says it ever said otherwise. That is the ledger-immutability argument applied to a lookup table:
 * finance_payments is append-only precisely so a recorded movement cannot be restated, and a
 * mutable account number restates it from the side.
 *
 * FROM CREATION, NOT "ONCE REFERENCED". A rule that switches on at first reference has a window in
 * which it does not hold, and someone has to decide what "referenced" means — allocations? ledger
 * rows? a draft invoice? Immutable from creation has no window and needs no definition. A school
 * that changes its banking details deactivates the account and creates a new one, which is also the
 * honest record: it IS a different account.
 *
 * `label` and `account_name` stay editable. They are display and courtesy, not reconciliation keys:
 * correcting a misspelt label changes what an operator reads, never what a statement line means.
 *
 * THIS IS THE DATABASE LAYER OF THREE. The FormRequest refuses with a sentence naming the way out,
 * and the screen does not render the fields as editable at all — a disabled input that still posts
 * is not a guard. Each layer has its own watched red, because a guard that only ever fires in
 * concert with two others is a guard nobody has actually tested.
 *
 * TWO SIGNAL CONSTRAINTS, LEARNED THE HARD WAY AND ENCODED HERE SO THEY ARE NOT REDISCOVERED:
 *   - MESSAGE_TEXT must contain NO APOSTROPHE. mysqldump breaks on one, which turns a backup into
 *     an unrestorable file — a defect that surfaces at the worst possible moment.
 *   - MESSAGE_TEXT must be ≤ 128 CHARACTERS. Longer and SIGNAL itself fails with 1648 instead of
 *     raising the intended 1644, so the guard reports the wrong error and reads as a bug in the
 *     trigger rather than a refusal.
 * The message below is 96 characters and apostrophe-free; BankAccountTest asserts both properties
 * against the LIVE trigger definition rather than against this file.
 */
return new class extends Migration
{
    private const TABLE = 'finance_bank_accounts';

    private const TRIGGER = 'finance_bank_accounts_identity_immutable';

    public function up(): void
    {
        DB::unprepared(
            'CREATE TRIGGER '.self::TRIGGER.' BEFORE UPDATE ON '.self::TABLE.'
             FOR EACH ROW
             BEGIN
                IF NEW.bank_name <> OLD.bank_name OR NEW.account_number <> OLD.account_number THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'Bank name and account number are immutable: deactivate this account and create a new one.\';
                END IF;
             END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
    }
};

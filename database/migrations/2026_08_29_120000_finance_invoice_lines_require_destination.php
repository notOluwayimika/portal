<?php

use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Models\Concerns\AppendOnly;
use App\Finance\Services\FeeScheduleLineMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S11 COMMIT 2 OF 2 — a CHARGE line must record where its money is destined.
 *
 * `2026_08_29_110000` added `finance_invoice_lines.bank_account_id` NULLABLE and made both writers
 * populate it: {@see FeeScheduleLineMapper::linesFor()} snapshots the fee
 * item's account, and the bursar's generate modal carries the account the operator selected. This
 * migration is the CONTRACT that column was added for. Split from it deliberately — a trigger
 * landing ahead of its writers refuses the generate modal the moment it ships, which is the same
 * expand-then-contract order `2026_08_10_100000` → `2026_08_10_120000` used on this same subject.
 *
 * ─── WHAT IT SAYS, AND WHAT IT DELIBERATELY DOES NOT ─────────────────────────────────────────────
 *
 * A `charge` line with a NULL destination is refused. Every OTHER kind — `waiver`, `discount`, and
 * anything the {@see InvoiceLineKind} enum gains later — may carry NULL, and that
 * is not a gap being left open for later. A reduction sends money NOWHERE. Whether a waiver should
 * inherit the account of the charge it offsets is UNMODELLED and UNANSWERED, and a rule requiring a
 * destination there would be this migration inventing the relationship the S11 design left open.
 * Permitting null on non-charge lines is what keeps that question askable.
 *
 * This is why the rule is a TRIGGER and not `NOT NULL`. A NOT NULL column cannot say "required on
 * one kind" — it would force an invented destination onto every reduction. The precedent is
 * `finance_payments.bank_account_id`: a nullable column whose real rule is an origin-keyed CHECK,
 * which `2026_08_10_120000`:20-24 argues "is not a weaker NOT NULL — it is a DIFFERENT and stronger
 * statement". Same shape here, keyed on `kind` rather than `origin`.
 *
 * AND IT IS A PRESENCE GUARD, NOT AN EXISTENCE GUARD — the layering is deliberate, not a hole to be
 * discovered later. This trigger tests `IS NULL` and nothing else, so a caller that cast a null
 * destination to `0` would satisfy it while naming no account at all; what refuses THAT is the
 * composite foreign key `(bank_account_id, school_id) -> finance_bank_accounts (id, school_id)` from
 * `2026_08_29_110000`, which has no `(0, school_id)` row to match and answers 1452. Each layer says
 * one thing: the trigger says a charge line must NAME a destination, the foreign key says the named
 * one must EXIST and belong to this School. Unreachable through either writer today —
 * `finance_fee_items.bank_account_id` is NOT NULL so the mapper cannot produce a zero, and the wire
 * carries a uuid the FormRequest resolves or refuses — which is exactly why it is stated here rather
 * than guarded twice.
 *
 * ─── BEFORE INSERT ONLY. THIS IS A DECISION; DO NOT "TIDY" IT BY ADDING AN UPDATE ARM ────────────
 *
 * Every `finance_invoice_lines` row issued before `2026_08_29_110000` has a NULL destination, and
 * NULL is the honest permanent record for them: they were billed before the column existed, there is
 * no backfill and there will not be one (that migration's docblock argues it at length — writing
 * today's catalog reading into a column that claims to record issue time manufactures a false
 * history on a table that can never be corrected). Those rows are VALID. This trigger is about what
 * may be WRITTEN from now on; it says nothing about what was written before, and it must not.
 *
 * An UPDATE arm would retro-refuse every one of them the moment anything touched the row. There is
 * no UPDATE path today — `finance_invoice_lines_no_update` denies UPDATE outright
 * (`2026_07_19_110000`:36) and {@see AppendOnly} throws on the Eloquent
 * side — so an UPDATE arm would be dead code that reads like a live control, and would become a
 * live *defect* the moment the append-only guard were ever relaxed for a repair path. The reduction
 * guard next door made exactly this call for exactly this reason (`2026_07_26_140002`:27-30), and
 * `InvoiceLineDestinationRequiredTest` pins the timing from information_schema so the decision is a
 * test failure to reverse rather than a comment to ignore.
 *
 * ─── A TRIGGER, BECAUSE PRODUCTION IS PERCONA 5.7.23 ─────────────────────────────────────────────
 *
 * CHECK is PARSED AND IGNORED on 5.7, so a CHECK constraint here would be a rule that exists in the
 * migration, passes locally on 8.0.43, and enforces NOTHING on the server that matters. A SIGNAL
 * inside a trigger is the only form that means the same thing on both. Same reasoning, same shape
 * and the same SQLSTATE `'45000'` (driver code 1644) as every other guard in this module.
 *
 * ─── BINARY ON THE KIND COMPARISON ───────────────────────────────────────────────────────────────
 *
 * `BINARY NEW.kind = BINARY 'charge'` rather than a bare `=`, copied from the reduction guard on
 * this same column-set (`2026_07_26_140002`:67) and from `2026_07_25_150000`:30-35 before it. The
 * house discipline is to write BINARY on every status/kind comparison REGARDLESS of whether the
 * connection's collations happen to agree, because a variable inherits the connection collation and
 * a literal the connection charset, and "they happen to agree" is a property of a connection rather
 * than of the schema. The case-collation behaviour was re-measured on 29 August 2026 (open-findings
 * #11) and holds on 5.7, so the existing pattern is safe to copy rather than re-derive.
 *
 * A consequence worth naming rather than leaving to be discovered: BINARY means a row inserted with
 * `kind = 'CHARGE'` is NOT a charge to this trigger and escapes the rule. That is true of the
 * reduction guard too, and it is not a new hole — `finance_invoice_lines.kind` is written only
 * through {@see InvoiceLineKind}, whose values are lowercase, and the same
 * argument is what makes the reduction guard's own BINARY comparisons safe.
 *
 * ─── A SEPARATE, SEPARATELY-NAMED TRIGGER ────────────────────────────────────────────────────────
 *
 * NOT folded into `finance_invoice_lines_reduction_guard`. MySQL 5.7.2+ and 8.0 both permit several
 * triggers with the same timing and event on one table, and keeping them apart means each guard's
 * `down()` removes exactly its own rule: folding this in would make dropping the destination rule
 * require rewriting the reduction rule, and the two have independent lifetimes. Their relative
 * firing order is unspecified without FOLLOWS/PRECEDES and does not matter — neither reads a column
 * the other could have changed, and either refusal aborts the same INSERT.
 */
return new class extends Migration
{
    private const GUARD = 'finance_invoice_lines_destination_guard';

    public function up(): void
    {
        // IF EXISTS so `up()` is idempotent — the audit path and the tests both re-run it to restore
        // the trigger after a deliberate drop, the same discipline `2026_08_26_100000` uses.
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::GUARD);

        DB::unprepared(
            'CREATE TRIGGER '.self::GUARD.' BEFORE INSERT ON finance_invoice_lines
             FOR EACH ROW
             BEGIN
                IF BINARY NEW.kind = BINARY \'charge\' AND NEW.bank_account_id IS NULL THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT =
                        \'A charge line must record the bank account its money is destined for.\';
                END IF;
             END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::GUARD);
    }
};

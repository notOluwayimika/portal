<?php

namespace App\Finance\Services;

use App\Exceptions\BusinessRuleException;
use App\Finance\Models\SchoolFinanceSettings;

/**
 * WHERE A SCHOOL'S GATEWAY MONEY SETTLES — the seam the payments path resolves its destination
 * through.
 *
 * `RecordPayment::handle()` takes a non-nullable `int $bankAccountId` and the `gateway` arm of
 * `finance_payments_origin_pairing_bi` requires one. A portal payment gets that id from a bursar
 * choosing on a screen. A gateway callback has no operator in the room, so it asks here.
 *
 * ─── THERE IS NO FALLBACK, AND THAT IS THE WHOLE DESIGN ──────────────────────────────────────────
 *
 * Not "the first active account". Not "the only account". Not null.
 *
 * A fallback is a guess about where real money goes, made by code, on behalf of a school that never
 * chose. "The only account" is the seductive one because it looks safe today — and it is exactly the
 * rule that changes meaning without anybody editing it: a school with one account this term has two
 * next term, at which point the guess starts silently picking, and the first anyone learns is a
 * reconciliation that will not balance. A refusal is recoverable in a minute by configuring the
 * account. Money in the wrong account is not.
 *
 * So the contract is total: it returns a configured id or it throws. There is no third answer to
 * write a branch for, which is the point — a caller cannot forget to handle a null it never gets.
 *
 * ─── ISOLATION, AND WHICH DIRECTION IT FAILS IN ──────────────────────────────────────────────────
 *
 * The read goes through {@see SchoolFinanceSettings} — the model, with its `SchoolScope` — rather
 * than around it, so this introduces no second access pattern to `finance_school_settings`
 * (`invoiceNumberPrefixFor()` is the first, and reads the same way for the same reason). The
 * explicit `where('school_id', …)` is what makes the answer correct; the scope is what makes a
 * foreign school's row unreachable.
 *
 * The consequence worth naming: asking for a school that is not the active one resolves to nothing
 * and this THROWS. That is the fail-closed direction — a refusal to record, rather than a payment
 * settling somewhere nobody chose. It is also why the exception says "not configured" rather than
 * guessing at causes: from here the two are indistinguishable, and the operator's action is the same.
 *
 * ─── NOT MEMOISED, DELIBERATELY ──────────────────────────────────────────────────────────────────
 *
 * `invoiceNumberPrefixFor()` memoises because serialising an invoice list would otherwise issue one
 * query per invoice for the same school — a hot read of a presentational value. This is neither: it
 * is read once per payment, on the write path, for a value that decides where money lands. A stale
 * memo here would send a payment to the account the school USED to settle into. One query is the
 * right price.
 */
final class SettlementBankAccount
{
    /**
     * The bank account a school's gateway payments settle into.
     *
     * The school is named BY ID in the exception message and never by name: a school name in an
     * error is a cross-school leak into logs, transcripts and API responses, and an id answers every
     * question an operator actually has.
     *
     * @throws BusinessRuleException when the school has not configured one
     */
    public function forSchool(int $schoolId): int
    {
        $accountId = SchoolFinanceSettings::query()
            ->where('school_id', $schoolId)
            ->value('settlement_bank_account_id');

        if ($accountId === null) {
            throw new BusinessRuleException(
                'No settlement bank account is configured for school#'.$schoolId
                .'. Configure one before recording a gateway payment.'
            );
        }

        return (int) $accountId;
    }
}

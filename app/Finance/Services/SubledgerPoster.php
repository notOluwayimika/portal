<?php

namespace App\Finance\Services;

use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\LedgerTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single writer of subledger rows (Engineering Invariant 7: one authoritative
 * entry point). Every charge, payment and reversal posts through here so the row
 * shape is defined once. Finance-private (arch: Services used only in App\Finance).
 *
 * It never opens its own transaction — it is always called INSIDE an Action's
 * transaction, so the ledger post commits atomically with the state change that
 * caused it (a charge can never exist without its invoice, nor a reversal without
 * its cancellation).
 *
 * IT ALSO MAINTAINS THE ACCOUNT PROJECTION. finance_student_accounts.balance_minor
 * is defined as SUM(signed ledger amount_minor) per (school, student); the only way
 * to keep that true CONTINUOUSLY is to move the balance by the SAME delta on the
 * SAME movements the ledger records — which is precisely every call here. Doing it
 * at the single writer (rather than in one Action) is what makes a CHARGE maintain
 * the balance as faithfully as a payment: GenerateInvoice/CancelInvoice/RecordPayment
 * are untouched; they call post() as before and the projection follows for free.
 *
 * The maintenance is an atomic upsert-increment (`balance = balance + :delta`), NOT
 * an app-level read-modify-write: `col = col + delta` is skew-free at InnoDB without
 * any lock, and the ON DUPLICATE KEY resolves the create-or-increment race for a
 * student's first-ever movement in one statement (no get-or-create, no zero-row
 * drift). No account lock is needed here; the pessimistic lock arrives in W3, where
 * applying credit is a genuine read-modify-write of the balance.
 */
final class SubledgerPoster
{
    public function post(
        int $schoolId,
        int $studentId,
        LedgerEntryType $type,
        Money $amount,
        string $sourceType,
        int $sourceId,
        string $narration,
    ): LedgerTransaction {
        $row = LedgerTransaction::create([
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'type' => $type,
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'narration' => $narration,
        ]);

        $this->applyToAccount($schoolId, $studentId, $amount);

        return $row;
    }

    /**
     * Move the account balance by the signed delta of the ledger row just posted,
     * creating the row on a student's first-ever movement. One atomic statement:
     *
     *   - INSERT plants a new account seeded to :delta (the first movement's amount);
     *   - ON DUPLICATE KEY adds :delta to the existing balance, `col = col + delta`,
     *     which InnoDB applies to the current committed value under the row lock — so
     *     two concurrent posts to the same account both land, no read-modify-write
     *     skew and no app-level lock.
     *
     * DB::insert (not DB::table — the boundary lint forbids that escape hatch) with a
     * raw finance_ literal is legal HERE: this file is inside app/Finance, and the
     * finance-table-outside-finance rule only fires on the literal OUTSIDE app/Finance.
     * The write bypasses SchoolScope, so school_id is supplied explicitly.
     */
    private function applyToAccount(int $schoolId, int $studentId, Money $amount): void
    {
        DB::insert(
            'INSERT INTO finance_student_accounts
                (uuid, school_id, student_id, balance_minor, balance_currency, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                balance_minor = balance_minor + VALUES(balance_minor),
                updated_at = NOW()',
            [
                (string) Str::orderedUuid(),
                $schoolId,
                $studentId,
                $amount->toKobo(),      // signed: charge +, payment/reversal −
                $amount->currency,
            ],
        );
    }
}

<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\Payment;
use App\Finance\Services\SubledgerPoster;
use App\Models\Student;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\Sequences\Sequences;
use Illuminate\Support\Facades\DB;

/**
 * Record a payment ON THE ACCOUNT — no invoice named. The sibling of {@see RecordPayment}
 * (Option B, ADR 0048): the schema and RecordPayment's own zero-allocation overpayment path
 * already expressed this — a Payment carries no invoice FK — so this Action is a DOOR onto an
 * existing capability, not a new room. The cash banks as account credit (a negative balance) and
 * settles oldest-first at the next generation via GenerateInvoice::applyCreditForward, which is now
 * the SOLE allocator of unnamed money (docs/finance/accounting-policy.md, ENFORCED).
 *
 * NO LOCK, and that is a decision, not an omission (docs/finance/concurrency.md). RecordPayment
 * locks the invoice row for the #94 ceiling — Σ(allocations) ≤ invoice total — so competing
 * allocations to one invoice serialise. THIS path writes NO allocation, so that invariant is
 * VACUOUS here, not unprotected: there is no per-invoice sum to bound. The only shared state it
 * moves is the account balance, and SubledgerPoster::post moves it with an atomic
 * `balance = balance + delta` (skew-free at InnoDB without an application lock — WalletConcurrencyTest
 * PROOF 4). Nothing in this path is a read-modify-write: the amount comes from the request, never
 * from a prior read. So the fourth money Action deliberately joins no lock — stated here because
 * concurrency.md warns the three-call-site invariant is easy to lose in a refactor, and silence
 * would read as a missing guard.
 *
 * Isolation is ASSERTED, not inherited. RecordPayment got school_id off a SchoolScope-bound invoice;
 * here it comes from ActiveSchool::id() with a fail-closed on null (Constitution rule 13 — context is
 * explicit or absent, never inferred), plus a cross-school guard on the student for the direct/console
 * caller that has no route binding to 404 it.
 */
final class RecordAccountPayment
{
    public function __construct(private readonly SubledgerPoster $ledger) {}

    public function handle(int $studentId, Money $amount, string $payerName, User $actor): Payment
    {
        if ($amount->isZero() || $amount->isNegative()) {
            throw new BusinessRuleException('A payment amount must be positive.');
        }

        // Rule 13: a financial write with no context fails closed rather than inferring one.
        $schoolId = ActiveSchool::id();
        if ($schoolId === null) {
            throw new BusinessRuleException('No active School context: a payment cannot be recorded.');
        }

        // Cross-school guard. Student uses BelongsToSchool, so the {student:uuid} route binding already
        // 404s a foreign student — but a console/direct Action call has no binding, so the guard lives
        // here too. Student::find is SchoolScope-filtered (withoutGlobalScope is boundary-forbidden in
        // app/Finance), so a foreign or unknown id resolves to null under the active School; the
        // explicit school_id compare is the belt to that scope's braces.
        $student = Student::find($studentId);
        if ($student === null || (int) $student->school_id !== $schoolId) {
            throw new BusinessRuleException('That student belongs to another School.');
        }

        // A payment must be in the account's currency — there is no invoice to compare to here, so compare
        // to the existing account's balance_currency, or to the default on a student's first-ever movement.
        // Without this a "USD" payment adds straight into an NGN balance_minor (applyToAccount's ON DUPLICATE
        // KEY never rewrites balance_currency) — silent corruption, no allocation trigger on this path. 422.
        $existing = DB::selectOne(
            'SELECT balance_currency FROM finance_student_accounts WHERE school_id = ? AND student_id = ?',
            [$schoolId, $studentId],
        );
        $expected = $existing->balance_currency ?? Money::DEFAULT_CURRENCY;
        if ($amount->currency !== $expected) {
            throw new BusinessRuleException("A payment must be in the account's currency ({$expected}).");
        }

        return DB::transaction(function () use ($schoolId, $studentId, $amount, $payerName, $actor) {
            // Same sequence scope and key as RecordPayment — one receipt series per school across both
            // doors; UNIQUE(school_id, reference) is the backstop that makes a second counter fail loudly.
            $reference = Sequences::next('finance_payment', (string) $schoolId);

            // The payment belongs to the ACCOUNT. `method` is not a request input and keeps its
            // 'manual' default. NO allocation row is written — ever, not "usually none": there is no
            // invoice to allocate against.
            $payment = Payment::create([
                'school_id' => $schoolId,
                'student_id' => $studentId,
                'reference' => $reference,
                'amount' => $amount,
                'payer_name' => $payerName,
                'received_by_user_id' => $actor->id,
            ]);

            // Credit — the FULL payment reduces the receivable, so the ledger amount is negative.
            // Sourced to the PAYMENT (the cash event). BYTE-IDENTICAL shape to RecordPayment's post:
            // same LedgerEntryType::Payment and source_type 'payment', so finance:audit-ledger-coherence
            // (ADR 0047, assertion I1's vocabulary) needs no change. post() also moves the account balance.
            $this->ledger->post(
                $schoolId,
                $studentId,
                LedgerEntryType::Payment,
                $amount->times(-1),
                'payment',
                (int) $payment->getKey(),
                "Payment #{$reference} received on account",
            );

            return $payment->load('allocations');
        });
    }
}

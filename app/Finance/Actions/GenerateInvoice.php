<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Finance\Models\StudentAccount;
use App\Finance\Services\SubledgerPoster;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\Sequences\Sequences;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Raise one MULTI-LINE invoice for one enrollment and post its charge to the
 * subledger — the whole use case in one transaction (the invoice, all its lines,
 * and the ledger charge commit together or not at all).
 *
 * The enrollment is read through the ACL port ({@see BillableEnrollmentProvider}),
 * never StudentCurriculum — so this file has no academic import, which is the
 * boundary the arch test guards.
 *
 * F6 — total = SUM(lines). The caller supplies LINES and never a total: the total
 * is derived here by exact integer addition (Money::plus) and snapshotted onto the
 * invoice inside this transaction. There is no code path by which a caller can
 * assert a total, and the `finance_invoices_total_immutable` DB trigger denies any
 * later edit of the money columns — so the snapshot cannot drift afterwards.
 * Money::plus() also throws on a currency mismatch, which makes a mixed-currency
 * invoice impossible by construction rather than by validation.
 *
 * DUPLICATE PREVENTION — "at most one ACTIVE invoice per enrollment episode" is a
 * SET-based invariant: it constrains the set of invoices for an enrollment, which
 * no single Invoice aggregate can see. The authoritative guard is therefore the
 * DB's UNIQUE(school_id, active_enrollment_key) over the generated column; the
 * pre-check below exists only to turn the common case into a friendly 422 instead
 * of a duplicate-key error. Under concurrency the pre-check CANNOT hold (both
 * racers read a snapshot in which no invoice exists) — the unique index is what
 * actually holds, which is why the duplicate-key error is translated rather than
 * treated as an impossible case. Proven in InvoiceConcurrencyTest.
 */
final class GenerateInvoice
{
    /** MySQL duplicate-entry error code. */
    private const DUPLICATE_ENTRY = 1062;

    public function __construct(
        private readonly BillableEnrollmentProvider $enrollments,
        private readonly SubledgerPoster $ledger,
    ) {}

    /**
     * @param  list<InvoiceLineSpec>  $lines
     */
    public function handle(string $enrollmentUuid, array $lines): Invoice
    {
        $enrollment = $this->enrollments->findByUuid($enrollmentUuid);

        if ($enrollment === null) {
            throw new BusinessRuleException('No billable enrollment found for the given reference.');
        }

        // CROSS-SCHOOL GUARD. `student_curricula` has no school_id and
        // StudentCurriculum is deliberately unscoped (v10 §14), so the enrollment
        // LOOKUP is not School-constrained: an enrollment uuid belonging to another
        // School resolves perfectly well. Isolation is therefore asserted here, by
        // comparing the episode's own School — resolved from `students.school_id`
        // (falling back to `curricula.school_id`) in BillableEnrollmentAdapter —
        // against the Active School.
        //
        // VERIFIED BEHAVIOUR, which is subtler than "compare A to B": Student and
        // Curriculum are BOTH School-scoped, so under School A's context a School-B
        // episode resolves its relations to null and the adapter reports school 0,
        // not 2. The guard then rejects on 0 ≠ A. It is doubly fail-closed — it
        // refuses both a known-foreign School and an undeterminable one — and the
        // two cases are reported separately so a real failure is diagnosable rather
        // than mislabelled as a cross-School attempt.
        //
        // Constitution rule 1: no cross-School financial operation, ever. Rule 13:
        // context is explicit or absent, never inferred — so a financial write with
        // no context fails closed rather than adopting whatever the row says.
        $activeSchoolId = ActiveSchool::id();

        if ($activeSchoolId === null) {
            throw new BusinessRuleException('No active School context: an invoice cannot be raised.');
        }

        if ($enrollment->schoolId === 0) {
            throw new BusinessRuleException(
                'The School owning this enrollment could not be determined; it cannot be billed.'
            );
        }

        if ($enrollment->schoolId !== $activeSchoolId) {
            throw new BusinessRuleException('That enrollment belongs to another School.');
        }

        if ($lines === []) {
            throw new BusinessRuleException('An invoice must have at least one line.');
        }

        // Resolve percentage reductions into concrete amounts FIRST, so everything below
        // — the throw checks, the fold, the persisted rows — operates on a single,
        // uniform shape: concrete signed lines. A stored line is never "10%"; it is the
        // exact naira reduction that percentage produced, which is what §5 snapshot
        // integrity requires (a historical statement must not recompute a percentage
        // against numbers that may have moved).
        $lines = $this->resolvePercentages($lines);

        // The positivity rule is now SCOPED BY KIND, and each half is stricter than the
        // single rule it replaces — a charge must still be strictly positive, and a
        // reduction must be strictly negative. Neither may be zero: a zero line carries
        // no arithmetic and no information, and silently accepting one would let a
        // "waiver" that waives nothing look applied.
        foreach ($lines as $line) {
            if ($line->resolvedAmount()->isZero()) {
                throw new BusinessRuleException('An invoice line amount may not be zero.');
            }

            if ($line->isReduction()) {
                if (! $line->resolvedAmount()->isNegative()) {
                    throw new BusinessRuleException('A waiver or discount line must be negative.');
                }

                continue;
            }

            if ($line->resolvedAmount()->isNegative()) {
                throw new BusinessRuleException('Every invoice charge line must be positive.');
            }
        }

        // F6: the total is DERIVED, never supplied. Exact integer addition, and a
        // LITERAL SIGNED SUM — it does not branch on kind. Reductions carry a negative
        // amount, so `plus` nets them without any special case: sign carries the
        // arithmetic, kind carries the meaning. This is why F6's trigger needs no change
        // — the equality is still established here and frozen there.
        $total = array_reduce(
            $lines,
            static fn (?Money $carry, InvoiceLineSpec $line) => $carry === null
                ? $line->resolvedAmount()
                : $carry->plus($line->resolvedAmount()),
        );

        // Reductions may bring a total to zero, but never below it. A negative invoice
        // would mean the School owes the student, which is a credit note or refund
        // (§10, later) — never an invoice. Ratified in accounting-policy.md §5.
        if ($total->isNegative()) {
            throw new BusinessRuleException(
                'Reductions may not exceed the charges on an invoice: the total would be negative.'
            );
        }

        try {
            return DB::transaction(function () use ($enrollment, $lines, $total) {
                // W3 apply-forward — the FIRST statement, and a LOCKING read on purpose.
                // A locking read does not establish InnoDB's REPEATABLE READ snapshot, so
                // it forms at the first plain read AFTER this lock (assertNoActiveInvoice),
                // and the credit we read is a CURRENT read of the committed balance rather
                // than a stale snapshot (docs/finance/concurrency.md). ACCOUNT-BEFORE-INVOICE
                // ordering: RecordPayment locks the invoice row and only touches this account
                // through post()'s atomic increment, so there is no opposite-order shared pair
                // — no deadlock (WalletW3ConcurrencyTest). A missing row (first-ever activity)
                // means zero credit; the charge's upsert creates it.
                $account = StudentAccount::query()
                    ->where('student_id', $enrollment->studentId)
                    ->lockForUpdate()
                    ->first();

                // Carry-forward credit = max(0, −balance) from the PRE-charge balance: the
                // true net overpayment, NOT raw unallocated payments (which would wrongly
                // auto-apply while an older invoice sits unpaid — proof 6). Read BEFORE the
                // charge posts, or the charge flips the balance positive and credit reads 0.
                $creditKobo = $account !== null ? max(0, -$account->balance->toKobo()) : 0;

                $this->assertNoActiveInvoice($enrollment->schoolId, $enrollment->enrollmentId);

                $number = Sequences::next('finance_invoice', (string) $enrollment->schoolId);

                $invoice = Invoice::create([
                    'school_id' => $enrollment->schoolId,
                    'student_id' => $enrollment->studentId,
                    'student_curriculum_id' => $enrollment->enrollmentId,
                    'number' => $number,
                    'status' => InvoiceStatus::Issued,
                    'billed_to_name' => $enrollment->studentName,
                    'academic_context' => $enrollment->academicContext,
                    'total' => $total,
                ]);

                foreach ($lines as $line) {
                    $invoice->lines()->create([
                        'school_id' => $enrollment->schoolId,
                        'description' => $line->description,
                        'kind' => $line->kind,
                        'note' => $line->note,
                        'amount' => $line->resolvedAmount(),
                        'fee_item_id' => $line->feeItemId,
                    ]);
                }

                $this->ledger->post(
                    $enrollment->schoolId,
                    $enrollment->studentId,
                    LedgerEntryType::Charge,
                    $total,
                    'invoice',
                    $invoice->id,
                    "Invoice #{$number} — ".count($lines).' line(s)',
                );

                // Apply carry-forward credit to THIS invoice, capped at its own total,
                // oldest payment first. A SETTLEMENT LINK ONLY — it writes allocation rows
                // and does NOT post to the ledger (the money moved when the overpayment was
                // banked in W2), so balance_minor is unchanged; the invoice's outstanding
                // falls by the applied sum. The account lock above serialises this
                // read-credit→apply against a concurrent generation (proof 4).
                if ($creditKobo > 0) {
                    $this->applyCreditForward(
                        $invoice,
                        $enrollment->studentId,
                        min($creditKobo, $total->toKobo()),
                    );
                }

                return $invoice->load('lines');
            });
        } catch (QueryException $e) {
            // The set-based invariant, enforced by the DB, surfacing as a domain error.
            if ($this->isActiveEnrollmentCollision($e)) {
                throw new BusinessRuleException(
                    'This enrollment already has an active invoice. Void it before billing again.'
                );
            }

            throw $e;
        }
    }

    /**
     * Turn every percentage-reduction spec into a concrete-amount spec.
     *
     * SEMANTIC (stated because the brief's "10% off the tuition line" implies per-line
     * targeting and this does NOT do that): a percentage reduction is computed against
     * the invoice's GROSS CHARGES — the signed sum of every charge-kind line — not
     * against one named line. "10% waiver" means 10% off the bill. Per-line targeting is
     * a later refinement with its own design; it is deliberately not invented here on a
     * fragile description/index reference.
     *
     * The magnitude is `grossCharges->percentage($p)` — the banker's-rounded op — and
     * the resulting line stores that concrete negative naira figure, never the percent.
     *
     * @param  list<InvoiceLineSpec>  $lines
     * @return list<InvoiceLineSpec>
     */
    private function resolvePercentages(array $lines): array
    {
        $hasPercentage = false;
        foreach ($lines as $line) {
            if ($line->isPercentage()) {
                $hasPercentage = true;
                if (! $line->isReduction()) {
                    throw new BusinessRuleException('A percentage may only be applied to a waiver or discount line.');
                }
            }
        }

        if (! $hasPercentage) {
            return $lines;
        }

        // Gross = the signed sum of the CHARGE lines only. Percentage reductions reduce
        // the charges; folding other reductions into the base would let two reductions
        // compound in an order-dependent way.
        $grossCharges = null;
        foreach ($lines as $line) {
            if (! $line->isReduction() && ! $line->isPercentage()) {
                $grossCharges = $grossCharges === null
                    ? $line->resolvedAmount()
                    : $grossCharges->plus($line->resolvedAmount());
            }
        }

        if ($grossCharges === null) {
            throw new BusinessRuleException('A percentage reduction needs at least one charge line to reduce.');
        }

        return array_map(
            fn (InvoiceLineSpec $line) => $line->isPercentage()
                // percentage() returns a positive magnitude; a reduction stores it negated.
                ? $line->withAmount($grossCharges->percentage($line->percent)->times(-1))
                : $line,
            $lines,
        );
    }

    /**
     * Friendly-path pre-check only — NOT the guarantee. See the class docblock.
     */
    /**
     * Settle $applyKobo of the just-created invoice from the student's carry-forward
     * credit, sourcing the OLDEST unallocated payment(s) first as REAL
     * payment-allocations — `payment_id` set to those payments, no credit-funded
     * allocation and no touch to payment_id's NOT NULL (fork 6 is §10). Applying
     * credit posts NOTHING to the ledger; it is a settlement link, so the balance is
     * unchanged and only the invoice's outstanding falls.
     *
     * SOURCING INVARIANT: whenever net credit > 0, Σ(unallocated payments) ≥ net
     * credit ≥ $applyKobo, because Σalloc ≤ Σcharges ⇒ Σpay − Σalloc ≥ Σpay − Σcharges
     * = −balance = credit. So there is always enough unallocated payment to draw. The
     * closing guard asserts that invariant held rather than silently under-applying.
     */
    private function applyCreditForward(Invoice $invoice, int $studentId, int $applyKobo): void
    {
        $currency = $invoice->total->currency;
        $remaining = $applyKobo;

        // Oldest first — id is monotonic with creation, so it is a deterministic
        // creation order without depending on second-precision timestamps.
        $payments = Payment::query()
            ->where('student_id', $studentId)
            ->orderBy('id')
            ->get();

        foreach ($payments as $payment) {
            if ($remaining <= 0) {
                break;
            }

            $allocated = (int) PaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->sum('amount_minor');
            $unallocated = $payment->amount->toKobo() - $allocated;

            if ($unallocated <= 0) {
                continue;
            }

            $draw = min($remaining, $unallocated);

            // ≤ invoice total by construction (applyKobo was capped), so the #94
            // over-allocation trigger is never approached.
            $payment->allocations()->create([
                'school_id' => $invoice->school_id,
                'invoice_id' => $invoice->id,
                'amount' => Money::fromKobo($draw, $currency),
            ]);

            $remaining -= $draw;
        }

        if ($remaining > 0) {
            // The sourcing invariant was violated — should be impossible. Fail closed
            // rather than silently applying less credit than the balance said existed.
            throw new BusinessRuleException(
                'Carry-forward credit could not be fully sourced from unallocated payments.'
            );
        }
    }

    private function assertNoActiveInvoice(int $schoolId, int $enrollmentId): void
    {
        $exists = Invoice::query()
            ->where('school_id', $schoolId)
            ->where('student_curriculum_id', $enrollmentId)
            ->excludingVoid()
            ->exists();

        if ($exists) {
            throw new BusinessRuleException(
                'This enrollment already has an active invoice. Void it before billing again.'
            );
        }
    }

    private function isActiveEnrollmentCollision(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === self::DUPLICATE_ENTRY
            && str_contains($e->getMessage(), 'finance_invoices_active_enrollment_unique');
    }
}

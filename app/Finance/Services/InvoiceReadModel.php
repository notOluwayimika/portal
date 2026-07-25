<?php

namespace App\Finance\Services;

use App\Finance\Enums\CreditNoteStatus;
use App\Finance\Enums\VoidRequestStatus;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Finance\Models\StudentAccount;
use App\Finance\Models\VoidRequest;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The invoice read side. Finance-private (arch: Services are used only inside
 * App\Finance).
 *
 * This exists so the exclude-void rule is LOAD-BEARING rather than decorative: a
 * scope nothing consumes proves nothing. Reporting reads default to excluding
 * voided invoices; the audit view opts in explicitly by passing $includeVoid.
 *
 * Voidness is filtered here, in the read model, and NOT by a global scope on the
 * Invoice model — see Invoice::scopeExcludingVoid() for why (a global scope would
 * break route-model binding and turn the double-void 422 into a 404).
 *
 * School isolation is automatic: Invoice uses BelongsToSchool, so every query
 * below is already scoped to the Active School.
 */
final class InvoiceReadModel
{
    /**
     * @return Collection<int, Invoice>
     */
    public function forStudent(int $studentId, bool $includeVoid = false): Collection
    {
        return Invoice::query()
            ->where('student_id', $studentId)
            ->when(! $includeVoid, fn ($q) => $q->excludingVoid())
            ->with('lines')
            // Per-invoice settlement sums (Decision 1: derived, never stored). SQL aggregates,
            // one query for the set — no N+1. `allocated_minor` covers ordinary payments AND
            // applied carry-forward credit; `approved_credit_minor` counts only APPROVED credit
            // notes (a pending proposal moves no money). InvoiceSettlement reads both.
            ->withSum('allocations as allocated_minor', 'amount_minor')
            ->withSum(['creditNotes as approved_credit_minor' => fn ($q) => $q->where('status', CreditNoteStatus::Approved->value)], 'amount_minor')
            ->orderBy('id')
            ->get();
    }

    /**
     * Total billed to a student. Voided invoices are excluded by default — a void
     * was never really billed, so including it would overstate the receivable.
     *
     * Summed with Money::plus rather than a SQL SUM so the currency invariant is
     * carried through: a mixed-currency total is meaningless and throws rather
     * than silently adding kobo to cents.
     */
    public function billedTotalForStudent(int $studentId, bool $includeVoid = false): Money
    {
        return $this->forStudent($studentId, $includeVoid)
            ->reduce(
                static fn (?Money $carry, Invoice $invoice) => $carry === null
                    ? $invoice->total
                    : $carry->plus($invoice->total),
            ) ?? Money::fromKobo(0);
    }

    /**
     * A student's credit notes, for the statement (§5/§7 integrity). Returned as their
     * OWN documents to sit BESIDE the invoices — the caller renders each separately and
     * never nets a credit into an invoice's displayed amount. School isolation is
     * automatic (CreditNote uses BelongsToSchool). Append-only, so ordering by id is
     * stable issue-order.
     *
     * @return Collection<int, CreditNote>
     */
    public function creditNotesForStudent(int $studentId): Collection
    {
        return CreditNote::query()
            ->where('student_id', $studentId)
            ->with('submittedBy')
            ->orderBy('id')
            ->get();
    }

    /**
     * The checker's pending-approvals queue — every credit note awaiting a decision in the
     * active School (School isolation is automatic via BelongsToSchool), newest first, with
     * its invoice and maker eager-loaded for display. Approved / rejected notes are NOT
     * here: a decided note leaves the queue. A pending note has posted no ledger entry, so
     * nothing in this list affects any balance.
     *
     * @return Collection<int, CreditNote>
     */
    public function pendingCreditNotes(): Collection
    {
        return CreditNote::query()
            ->where('status', CreditNoteStatus::Submitted->value)
            ->with(['invoice', 'submittedBy'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The checker's pending VOID requests — Ph3b twin of pendingCreditNotes(). Every void request
     * awaiting a decision in the active School (isolation automatic via BelongsToSchool), newest
     * first, invoice + maker eager-loaded for display. A pending request has NOT touched its
     * invoice: nothing here has moved money or freed an F7 slot — that happens only on approval.
     *
     * @return Collection<int, VoidRequest>
     */
    public function pendingVoidRequests(): Collection
    {
        return VoidRequest::query()
            ->where('status', VoidRequestStatus::Submitted->value)
            ->with(['invoice', 'submittedBy'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * A student's void requests, for the statement — so a pending request is visible ("void
     * requested, awaiting approval") while the invoice is still active, and the decision trail
     * survives after. Newest first; invoice + maker eager-loaded. School isolation automatic.
     *
     * @return Collection<int, VoidRequest>
     */
    public function voidRequestsForStudent(int $studentId): Collection
    {
        return VoidRequest::query()
            ->whereHas('invoice', fn ($q) => $q->where('student_id', $studentId))
            ->with(['invoice', 'submittedBy'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The student's ACCOUNT-level position for the statement. This is where credit-note
     * credit is visible: it carries on the balance, not as a per-invoice line (§10 C1),
     * so a statement that only listed invoices and payments would hide it. Returns the
     * signed balance (positive = owed, negative = the school owes the student) and the
     * derived available credit (max(0, −balance)). A student with no ledger activity has
     * no account row yet — that reads as a zero balance, not an error.
     *
     * @return array{balance: Money, available_credit: Money}
     */
    public function accountPositionForStudent(int $studentId): array
    {
        $account = StudentAccount::query()->where('student_id', $studentId)->first();

        if ($account === null) {
            $zero = Money::fromKobo(0);

            return ['balance' => $zero, 'available_credit' => $zero];
        }

        return ['balance' => $account->balance, 'available_credit' => $account->availableCredit()];
    }

    /**
     * A student's payments for the statement — each with its date, amount, method,
     * reference (the per-School receipt sequence) and allocations. Newest first. School
     * isolation is automatic (Payment uses BelongsToSchool); append-only, so id order is
     * stable receipt order.
     *
     * @return Collection<int, Payment>
     */
    public function paymentsForStudent(int $studentId): Collection
    {
        return Payment::query()
            ->where('student_id', $studentId)
            ->with('allocations')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Does this enrollment episode already have an ACTIVE (issued, non-void) invoice? The
     * F7 "one active invoice per episode" preview — the modal reads it to warn "void first"
     * BEFORE the bursar enters lines. It is only a preview: the authoritative guard is the
     * DB unique index + assertNoActiveInvoice at generation time (surfaced as the 422).
     */
    public function hasActiveInvoiceForEnrollment(int $enrollmentId): bool
    {
        return Invoice::query()
            ->where('student_curriculum_id', $enrollmentId)
            ->excludingVoid()
            ->exists();
    }
}

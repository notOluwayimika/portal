<?php

namespace App\Finance\Services;

use App\Finance\Enums\CreditNoteStatus;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\VoidRequestStatus;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Finance\Models\StudentAccount;
use App\Finance\Models\VoidRequest;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
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
            ->tap($this->settlementSums(...))
            ->orderBy('id')
            ->get();
    }

    /**
     * THE TWO SETTLEMENT AGGREGATES, FOR THIS READ PATH.
     *
     * ── THE INVARIANT, AND IT IS NOT ABOUT ROUTE-MODEL BINDING ──
     *
     * InvoiceSettlement reads `allocated_minor` and `approved_credit_minor` off the model as plain
     * attributes and treats an ABSENT one as zero — see
     * `for` (app/Finance/Services/InvoiceSettlement.php:51). So the rule is:
     * **any Invoice handed to InvoiceResource that did not come through this method reports a
     * settlement position of zero, whether or not that is true.** An invoice with money against it
     * then serialises `settlement_state: 'unpaid'`, `outstanding` equal to its full total,
     * `can_record_payment: true` and `can_request_void: true` with no blocked reason — a response
     * offering to void an invoice that carries a payment allocation, answering 200, invisible to
     * any test asserting that a page or an endpoint responds.
     *
     * AN EARLIER VERSION OF THIS PARAGRAPH BLAMED `{invoice:uuid}` BINDING, and that is one way in
     * rather than the rule. The other way in is a FRESHLY CREATED model that acquired allocations
     * inside its own transaction — `GenerateInvoice` writes carry-forward PaymentAllocation rows
     * against the invoice it has just created, through
     * `applyCreditForward` (app/Finance/Actions/GenerateInvoice.php:479), and then returns that
     * model. That is the way in
     * that shipped: both generate routes answered their 201 through InvoiceResource without passing
     * here.
     *
     * `allocated_minor` covers ordinary payments AND applied carry-forward credit;
     * `approved_credit_minor` counts only APPROVED credit notes, because a pending proposal moves
     * no money. Both are SQL aggregates — one query for the whole set, never an N+1.
     *
     * ── IT IS THE ONE SPELLING **THIS READ PATH** USES, NOT THE ONLY ONE IN THE CODEBASE ──
     *
     * That stronger claim was made here and it was false. The `withSum` pair is written out in
     * three places — this method, `AllocationProposal::openInvoices()` and
     * `DriveFinanceStates::openInvoiceCount()` — and the outstanding arithmetic over them in two:
     * `InvoiceSettlement::for()` and `AllocationProposal::outstandingKobo()`. They agree today,
     * compared character by character. Converging them is its own change with its own arms; see
     * docs/handoff/tickets/three-spellings-of-the-settlement-aggregates.md, which is why this
     * method is deliberately NOT made public here — a primitive widened ahead of a consumer is
     * front-loading, and AllocationProposal is merged code with arms of its own.
     *
     * @param  Builder<Invoice>  $query
     */
    private function settlementSums(Builder $query): void
    {
        $query
            ->withSum('allocations as allocated_minor', 'amount_minor')
            ->withSum(['creditNotes as approved_credit_minor' => fn ($q) => $q->where('status', CreditNoteStatus::Approved->value)], 'amount_minor');
    }

    /**
     * ONE invoice, carrying its settlement position — what every caller about to hand an Invoice to
     * InvoiceResource passes it through first.
     *
     * NAMED FOR THE INVARIANT AND NOT FOR ONE SCREEN. This was `forDetail()` when the detail page
     * was its only caller, and that name is part of why the generate 201 kept the defect for a
     * commit longer: the two POST routes were not "the detail", so nothing about the name suggested
     * they needed it. They did — see settlementSums() above.
     *
     * IT TAKES A MODEL AND RE-QUERIES IT rather than calling `loadSum` on it, so this and
     * forStudent() express the aggregates through the SAME method above. The re-query is scoped by
     * BelongsToSchool a second time; on the page routes that is redundant (route-model binding
     * already resolved it under SchoolScope) and it is kept because the alternative is a read of a
     * Finance model that is NOT scoped at its own call site. On the generate routes it is not
     * redundant at all: the model arrives straight from `create()`.
     *
     * ONE EXTRA QUERY PER CALL, and it is only paid where an invoice is about to be SERIALISED.
     * `ProcessBulkInvoiceRun` calls GenerateInvoice per student and renders nothing, so it does not
     * come through here — which is why this is at the InvoiceResource call sites rather than inside
     * the Action's return.
     *
     * VOIDED INVOICES RESOLVE HERE, deliberately. `excludingVoid()` is the REPORTING default and
     * this is not a report — it is the document. The route's own comment records why voidness was
     * never made a global scope (it would turn the double-void 422 into a 404); a detail page that
     * 404'd on a voided invoice would recreate that hole one surface over, and the void decision
     * trail is exactly what someone opening a voided invoice has come to read.
     */
    public function withSettlement(Invoice $invoice): Invoice
    {
        return Invoice::query()
            ->whereKey($invoice->getKey())
            ->with('lines')
            ->tap($this->settlementSums(...))
            ->firstOrFail();
    }

    /**
     * The void requests against ONE invoice — newest first, maker eager-loaded.
     *
     * MATCHED ON THE FOREIGN KEY, not on a rendered number. The statement pairs its rows against
     * pending requests by `display_number` (statement.tsx), because the invoice it holds carries a
     * uuid while the void request references the numeric PK — a real constraint of that screen and
     * a string comparison all the same. The detail page holds the invoice ROW, so it asks the
     * question the database can answer exactly.
     *
     * @return Collection<int, VoidRequest>
     */
    public function voidRequestsForInvoice(Invoice $invoice): Collection
    {
        return VoidRequest::query()
            ->where('invoice_id', $invoice->getKey())
            // `invoice` as well as the maker: VoidRequestResource reads the invoice for the number
            // and the amount at stake, and renders both as null when it is not loaded.
            ->with(['invoice', 'submittedBy'])
            ->orderByDesc('id')
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
     * Does this enrollment episode already have an ACTIVE (issued, non-void) SCHEDULED invoice?
     *
     * THE ONE PHP EXPRESSION OF THAT QUESTION, and the only one. It has two consumers with
     * different jobs and necessarily the same answer:
     *
     *   - InvoiceController::billableEnrollment — the modal's `already_invoiced` preview, read
     *     BEFORE the bursar enters lines so they are warned rather than refused;
     *   - GenerateInvoice::assertNoActiveInvoice — the friendly-422 pre-check at generation time.
     *
     * NEITHER IS THE AUTHORITY. That is UNIQUE(school_id, active_enrollment_key) over the generated
     * column (2026_08_18_100000); under concurrency this read cannot hold, because both racers see
     * a snapshot in which no invoice exists. This method exists so the two friendly paths cannot
     * DISAGREE — which is what happened when they were two hand-maintained copies and only one
     * gained the `kind` filter: the preview told a bursar to void an invoice, and the write it
     * warned about then succeeded.
     *
     * The predicate mirrors the index term for term: School, episode, issued, scheduled.
     */
    public function hasActiveScheduledInvoiceForEnrollment(int $enrollmentId, int $schoolId): bool
    {
        return $this->activeScheduledInvoiceIdForEnrollment($enrollmentId, $schoolId) !== null;
    }

    /**
     * The same question, answered with the invoice's id instead of a boolean — WHICH invoice is
     * already there.
     *
     * IT IS THE PREDICATE, AND THE BOOLEAN ABOVE NOW DELEGATES TO IT. The third consumer (U6 commit
     * 3's bulk run, which records `already_billed` NAMING the invoice that blocked it) needed an id,
     * and the two ways to get one were a fourth copy of this `where` chain or this. A copy is how
     * the preview and the pre-check came to disagree in the first place; the delegation makes "same
     * answer" a fact rather than a comment. Widening the predicate still moves all three consumers.
     *
     * STILL NOT THE AUTHORITY — see above. The bulk run treats a `null` here as permission to TRY,
     * not as proof the write will succeed, and re-asks after a refusal.
     */
    public function activeScheduledInvoiceIdForEnrollment(int $enrollmentId, int $schoolId): ?int
    {
        $id = Invoice::query()
            // EXPLICIT, not left to the global SchoolScope, and this is the STRICTER of the two
            // predicates it replaced. SchoolScope applies a School filter only when
            // ActiveSchool::id() is non-null, and throws on a missing context only when
            // auth()->check() is true (SchoolScope:60) — so an off-request path with no context and
            // no authenticated principal reads UNSCOPED. GenerateInvoice never relied on that; it
            // named the School itself. Requiring the caller to name it keeps that property on the
            // write path and adds it to the read path, so collapsing the two copies loses nothing.
            ->where('school_id', $schoolId)
            ->where('student_curriculum_id', $enrollmentId)
            // The `kind` half is exactly as load-bearing as the void half. Without it a
            // supplementary charge raised in week 2 makes the term bill unraisable, and the
            // operator is told to void an invoice that is not the term bill and must not be voided.
            ->where('kind', InvoiceKind::Scheduled->value)
            ->excludingVoid()
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}

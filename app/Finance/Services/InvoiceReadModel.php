<?php

namespace App\Finance\Services;

use App\Finance\Enums\CreditNoteStatus;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\VoidRequestStatus;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Models\LedgerTransaction;
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
     * THE OUTSTANDING INVOICES for ONE student — the payer's list, and the read the parent portal
     * consumes.
     *
     * IT GOES THROUGH settlementSums() LIKE EVERY OTHER READ HERE, and that is the whole reason this
     * method exists rather than the caller assembling it. The docblock immediately below states the
     * invariant: an Invoice that did not come through those aggregates reports a settlement position
     * of zero whether or not that is true, so a resource serialising it shows `outstanding` equal to
     * the FULL TOTAL, silently. On a staff screen that is a wrong badge. On a PAYER surface it is a
     * parent being asked for money they have already paid, with nothing anywhere reporting an error.
     *
     * THE OUTSTANDING ARITHMETIC IS NOT SPELLED AGAIN HERE. It is `InvoiceSettlement::for()` — the
     * same derivation InvoiceResource renders — because the ticket the docblock below cites
     * (docs/handoff/tickets/three-spellings-of-the-settlement-aggregates.md) counts the spellings
     * that exist, and a third one added by this commit would be a fourth thing to converge.
     *
     * THREE EXCLUSIONS, ALL DELIBERATE:
     *   • VOID — `excludingVoid()`, the reporting default. A void charge was reversed; asking a
     *     parent to pay it is asking for money the school has said it is not owed.
     *   • SETTLED — an invoice whose outstanding has reached zero, by payment or by approved credit
     *     note or by both. Nothing is owed on it, so it is not on the payer's list.
     *   • NOT RELEASED — `releasedToPayers()`. Brookstone ruled on 31 August 2026 that every bill
     *     must be reviewed by an Internal Auditor before it is released to parents
     *     (docs/handoff/brookstone-answers-31-august.md §6). The bill EXISTS and COUNTS from the
     *     moment it is raised — it holds the enrollment's active slot and has posted its ledger
     *     charge — so this is a visibility gate and emphatically not a draft state. It is the ONLY
     *     one of the three that is parent-specific, which is why it lives on this method and not on
     *     `forStudent()`: the bursar, the statement and the Auditor must all keep seeing it.
     * An invoice that is PART paid stays, carrying its REMAINING amount. A student with no debt gets
     * an empty collection — which is information, not an error.
     *
     * FILTERED IN PHP, NOT IN SQL, and that is a bounded claim rather than an oversight: the set is
     * one student's invoices (a handful per term — `forStudent()` above already materialises all of
     * them for `billedTotalForStudent()`), and a `HAVING` over the two withSum aliases would be the
     * third spelling of the arithmetic this method exists to avoid.
     *
     * `lines` ARE NOT LOADED, unlike forStudent(). The payer resource does not carry them (a fee
     * breakdown is internal composition), so loading them would be an eager load nothing reads.
     *
     * School isolation is automatic — Invoice uses BelongsToSchool.
     *
     * @return Collection<int, Invoice>
     */
    public function outstandingForStudent(int $studentId): Collection
    {
        $settlement = new InvoiceSettlement;

        return Invoice::query()
            ->where('student_id', $studentId)
            ->excludingVoid()
            ->releasedToPayers()
            // THE LINES, EAGER-LOADED HERE AND NOWHERE ELSE. `GuardianInvoiceResource` ships them
            // through `whenLoaded`, which returns NOTHING rather than querying when the relation is
            // absent — so without this the payer screen would render a breakdown of zero rows,
            // silently, on a bill that has three. Same shape as the settlement aggregates two lines
            // down: a read model is the only sanctioned way in precisely because the resource cannot
            // tell a missing relation from an empty one.
            //
            // `with`, not a per-invoice read: this returns a list, and lazy-loading would be one
            // query per bill on a screen showing every ward's.
            ->with('lines')
            ->tap($this->settlementSums(...))
            ->orderBy('id')
            ->get()
            ->reject(fn (Invoice $invoice) => $settlement->for($invoice)['settlement_state'] === 'settled')
            ->values();
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
     * `applyCreditForward` (app/Finance/Actions/GenerateInvoice.php:583), and then returns that
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
     * THE DECIDED CREDIT NOTES — U13's read, and the twin of pendingCreditNotes() on the other side
     * of the decision. Every note in the active School that a checker has already approved or
     * rejected, newest DECISION first, with the invoice, the maker AND the checker eager-loaded.
     *
     * `decidedBy` is the load that makes this feed worth having. A decided document already appeared
     * on its student's statement (InvoiceController::forStudent), but that read loads the maker
     * alone — so "who approved this", which is the only question maker-checker exists to answer, was
     * unanswerable on every surface in the application. It is answerable here.
     *
     * ORDERED BY `decided_at` AND THEN BY id. Both terminal states stamp decided_at (CreditNote::
     * decide()), so the column is never null in this set; the id tiebreak is for two decisions taken
     * inside the same second, which a checker working through a queue produces routinely.
     *
     * NOT PAGINATED, and that is a bounded claim rather than an oversight: this set grows for the
     * life of a school, unlike the pending queue it mirrors, and the screen pages it CLIENT-side the
     * way the approvals queue does. That holds while corrections stay rare — each one costs two
     * people — and it is recorded as a limit in the implementation report rather than left to be
     * discovered.
     *
     * @return Collection<int, CreditNote>
     */
    public function decidedCreditNotes(): Collection
    {
        return CreditNote::query()
            ->whereIn('status', [CreditNoteStatus::Approved->value, CreditNoteStatus::Rejected->value])
            ->with(['invoice', 'submittedBy', 'decidedBy'])
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * THE DECIDED VOID REQUESTS — U14, the twin of decidedCreditNotes() exactly as
     * pendingVoidRequests() is pendingCreditNotes()'s. Approved or rejected, newest decision first,
     * invoice + maker + checker eager-loaded.
     *
     * An APPROVED row here names an invoice that is now void and whose charge has been reversed; a
     * REJECTED one names an invoice that still stands, and carries the checker's reason for letting
     * it stand. Both are readings of something that has already happened — nothing on this path
     * writes, and the rows themselves are status-terminal.
     *
     * Same non-pagination limit as decidedCreditNotes(), for the same reason.
     *
     * @return Collection<int, VoidRequest>
     */
    public function decidedVoidRequests(): Collection
    {
        return VoidRequest::query()
            ->whereIn('status', [VoidRequestStatus::Approved->value, VoidRequestStatus::Rejected->value])
            ->with(['invoice', 'submittedBy', 'decidedBy'])
            ->orderByDesc('decided_at')
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
     * THE SAME POSITION AS A PARENT MAY SEE IT — the account balance with every bill Internal Audit
     * has not yet released taken back out of it.
     *
     * ── WHY THIS EXISTS AT ALL, WHICH IS NOT A PRESENTATION PREFERENCE ───────────────────────────
     *
     * `outstandingForStudent()` withholds unreleased invoices from the payer's LIST. If the payer's
     * TOTAL still counted them, the parent portal would render a positive "Account balance" directly
     * above the sentence "Nothing outstanding for {name} right now"
     * (the `WardCard` component in resources/js/pages/parent/finance.tsx, which branches on an empty
     * invoice array). That
     * is not an inconsistency a reader can resolve — it is the screen stating a falsehood, produced
     * by the compliance gate itself, in the falsely-reassuring direction. The withhold is not
     * shippable without this.
     *
     * ── IT IS A DERIVATION FROM THE PROJECTION, NOT A SECOND DEFINITION OF THE BALANCE ───────────
     *
     * The distinction is load-bearing, because a second definition of a money figure is exactly the
     * defect `finance:reconcile-accounts` exists to detect and this method must not become a source
     * of drift it cannot see.
     *
     * `finance_student_accounts.balance_minor` is defined as SUM(signed ledger amount_minor) per
     * (school, student), maintained by the single writer `SubledgerPoster::post()`. This method does
     * not recompute that, does not read invoice totals, and does not spell the balance a second time.
     * It reads the AUTHORITATIVE balance through `accountPositionForStudent()` above — the same
     * method the staff statement reads, unchanged and deliberately untouched — and subtracts a sum
     * taken from THE SAME LEDGER ROWS the projection is made of. Both sides come from one source. If
     * the projection drifts, this drifts identically and `finance:reconcile-accounts` still sees it;
     * there is no path by which this method is right while the balance is wrong, or the reverse.
     *
     * The staff side keeps counting the bill — per Brookstone the invoice is real from the moment it
     * is raised. Only the payer's view of it is deferred.
     *
     * ── WHICH LEDGER ROWS COME OUT, AND WHY A PAYMENT IS NOT ONE OF THEM ─────────────────────────
     *
     * The question this answers is "what would this account read if the bills you cannot see did not
     * exist", so what comes out is every ledger movement that exists ONLY BECAUSE a withheld invoice
     * exists:
     *
     *   • ITS CHARGE — `source_type = 'invoice'`, the row `GenerateInvoice` posts
     *     (the `Charge` post inside `GenerateInvoice::handle()`). This is the ordinary case and usually the
     *     only one. Subtracting it is the exact inverse of the write that created it.
     *   • A CREDIT NOTE AGAINST IT — `source_type = 'credit_note'`
     *     (the post inside `ApproveCreditNote::handle()`). A credit note is not money that moved; it
     *     is the school forgiving part of ONE named invoice. If the parent cannot see the bill, they
     *     must not see it being forgiven either — otherwise the school appears to owe them money it
     *     does not. Unapproved notes post no ledger row and so contribute nothing; no status filter
     *     is needed and none is written, because filtering on one would be a second spelling of
     *     ApproveCreditNote's own rule.
     *
     * A PAYMENT IS DELIBERATELY NOT SUBTRACTED, and it is not an omission — it is not attributable in
     * the first place. `RecordPayment` posts one ledger row per PAYMENT
     * (`source_type = 'payment'`, inside `RecordPayment::handle()`) carrying the full amount
     * received, while its ALLOCATIONS may span several invoices; there is no ledger row per
     * allocation to remove. Nor should there be: the money genuinely arrived. Under "as if the bill
     * did not exist" a payment taken against a withheld invoice becomes unapplied credit, which is
     * what a parent who handed over money and can see no bill is in fact owed. That is the honest
     * answer rather than the convenient one, and it is pinned in ParentPortalFinanceReadTest §2b.
     *
     * A VOID invoice is not in the withheld set at all (`excludingVoid()`), so its charge and its
     * reversal both stay in the balance where they net to zero — the same treatment they get on the
     * staff side, arrived at by not making an exception rather than by making one.
     *
     * ── CURRENCY ────────────────────────────────────────────────────────────────────────────────
     *
     * The adjustment is summed in minor units and carried in the ACCOUNT's currency rather than the
     * ledger rows'. That is safe because the two cannot diverge: `SubledgerPoster::applyToAccount()`
     * refuses — with a LogicException, before the write — any ledger row whose currency differs from
     * the account it would move — `applyToAccount()`, the currency check at the top of it. The invariant is
     * enforced upstream at the single writer, so re-deriving it here would be a second guard over a
     * case that cannot reach this method.
     *
     * School isolation is automatic on both queries — Invoice and LedgerTransaction use
     * BelongsToSchool.
     *
     * @return array{balance: Money, available_credit: Money}
     */
    public function guardianAccountPositionForStudent(int $studentId): array
    {
        $position = $this->accountPositionForStudent($studentId);

        $withheldIds = Invoice::query()
            ->where('student_id', $studentId)
            ->excludingVoid()
            ->withheldFromPayers()
            ->pluck('id')
            ->all();

        if ($withheldIds === []) {
            return $position;
        }

        // The credit notes written against those invoices. Their ledger rows are keyed on the NOTE,
        // so the invoice link has to be resolved here rather than in the sum below.
        $noteIds = CreditNote::query()
            ->whereIn('invoice_id', $withheldIds)
            ->pluck('id')
            ->all();

        $withheldKobo = (int) LedgerTransaction::query()
            ->where(function (Builder $query) use ($withheldIds, $noteIds) {
                $query->where(fn (Builder $q) => $q->where('source_type', 'invoice')->whereIn('source_id', $withheldIds));

                if ($noteIds !== []) {
                    $query->orWhere(fn (Builder $q) => $q->where('source_type', 'credit_note')->whereIn('source_id', $noteIds));
                }
            })
            ->sum('amount_minor');

        $balance = $position['balance'];
        $adjusted = Money::fromKobo($balance->toKobo() - $withheldKobo, $balance->currency);

        // `available_credit` is re-derived from the ADJUSTED balance through StudentAccount's own
        // method rather than re-stated here. A transient, unsaved model is the cheapest way to reach
        // that one spelling of the rule; writing `max(0, −balance)` again in this file would be the
        // second copy of a derivation whose whole design note (StudentAccount::availableCredit) is
        // that it is derived and never stored.
        $projected = new StudentAccount(['balance' => $adjusted]);

        return ['balance' => $projected->balance, 'available_credit' => $projected->availableCredit()];
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

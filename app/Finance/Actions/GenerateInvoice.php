<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\FeeItem;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Finance\Models\StudentAccount;
use App\Finance\Services\InvoiceReadModel;
use App\Finance\Services\SubledgerPoster;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
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
 * DUPLICATE PREVENTION — "at most one ACTIVE SCHEDULED invoice per enrollment
 * episode" is a SET-based invariant: it constrains the set of invoices for an
 * enrollment, which no single Invoice aggregate can see. The authoritative guard is
 * therefore the DB's UNIQUE(school_id, active_enrollment_key) over the generated
 * column; the pre-check below exists only to turn the common case into a friendly
 * 422 instead of a duplicate-key error. Under concurrency the pre-check CANNOT hold
 * (both racers read a snapshot in which no invoice exists) — the unique index is
 * what actually holds, which is why the duplicate-key error is translated rather
 * than treated as an impossible case. Proven in InvoiceConcurrencyTest.
 *
 * KIND COMES FROM THE CALLER AND IS NEVER INFERRED. `$kind` is a REQUIRED argument,
 * not a defaulted one, and it is deliberately not derived from "does this episode
 * already have an invoice?" — that inference would make the second invoice of a
 * term silently supplementary, which is the exact misclassification the NOT NULL /
 * no-default column exists to make loud. The caller knows whether it is raising the
 * term bill or a trip fee; it says so.
 *
 * THE TWO DUPLICATE ARMS HERE ARE SCOPED TO `scheduled`, and both must be, for the
 * same reason: a supplementary invoice on an episode that already carries a scheduled
 * one is the NORMAL case. The pre-check mirrors the index exactly — School, episode,
 * issued, scheduled — so a prior SUPPLEMENTARY invoice never blocks the term bill.
 *
 * AND THERE ARE ONLY TWO ARMS BECAUSE THERE IS ONLY ONE PREDICATE. An earlier version
 * of this docblock said "both arms are scoped" and was FALSE WHEN WRITTEN: a third
 * copy of the same question lived in InvoiceReadModel, feeding the modal's
 * `already_invoiced` preview, and it was never re-scoped. The preview therefore told a
 * bursar to void the episode's invoice before billing — for a SUPPLEMENTARY invoice
 * that must not be voided — and the write it warned about then succeeded, so the
 * preview and the authority disagreed in the direction that gives a wrong instruction
 * rather than a wrong refusal.
 *
 * The repair is structural, not another patched copy: `assertNoActiveInvoice` below
 * DELEGATES to {@see InvoiceReadModel::hasActiveScheduledInvoiceForEnrollment()}, which
 * is now the single PHP expression of "this episode already has an active scheduled
 * invoice". A coupling asserted in prose is a wish; a coupling that is one method is a
 * fact. What remains genuinely duplicated is the ARM COUNT — the pre-check and the
 * duplicate-key translation — not the predicate.
 */
final class GenerateInvoice
{
    /** MySQL duplicate-entry error code. */
    private const DUPLICATE_ENTRY = 1062;

    /** MySQL error number for a user SIGNAL with SQLSTATE '45000' (a trigger-raised business rule). */
    private const SIGNAL_EXCEPTION = 1644;

    public function __construct(
        private readonly BillableEnrollmentProvider $enrollments,
        private readonly SubledgerPoster $ledger,
        private readonly InvoiceReadModel $invoices,
    ) {}

    /**
     * @param  list<InvoiceLineSpec>  $lines
     */
    /**
     * @param  InvoiceKind  $kind  scheduled (the term bill) or supplementary (a charge raised outside
     *                             the schedule). REQUIRED — see the class docblock; there is no default
     *                             and no inference, so a caller that has not decided cannot compile.
     * @param  ?int  $actorId  the user raising the invoice, recorded on every line (S1 Part 0). Passed
     *                         in from the controller edge — the Action never calls auth() (boundary lint).
     *                         Nullable so seeders/console callers with no acting user still work.
     */
    public function handle(string $enrollmentUuid, array $lines, InvoiceKind $kind, ?int $actorId = null): Invoice
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

        // Resolve each charge line's discountability from its fee item (S1 3.6) BEFORE percentages, since
        // the percentage base depends on it. Server-side and never from the wire — see resolveDiscountability.
        $lines = $this->resolveDiscountability($lines);

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
            return DB::transaction(function () use ($enrollment, $lines, $total, $kind, $actorId) {
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

                // Only the term bill is one-per-episode. A supplementary charge is raised
                // AGAINST a live scheduled invoice by design, so pre-checking it would refuse
                // the whole feature.
                if ($kind->isEpisodeExclusive()) {
                    $this->assertNoActiveInvoice($enrollment->schoolId, $enrollment->enrollmentId);
                }

                $number = Sequences::next('finance_invoice', (string) $enrollment->schoolId);

                $invoice = Invoice::create([
                    'school_id' => $enrollment->schoolId,
                    'student_id' => $enrollment->studentId,
                    'student_curriculum_id' => $enrollment->enrollmentId,
                    'number' => $number,
                    'status' => InvoiceStatus::Issued,
                    'kind' => $kind,
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
                        // A reduction line carries the policy it cites; a charge carries null. The
                        // finance_invoice_lines_reduction_guard is the authority (proofs 11–14).
                        'discount_policy_id' => $line->discountPolicyId,
                        'created_by_user_id' => $actorId,
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
                    // A charge is raised NOW and belongs to NOW. There is no earlier business date
                    // to honour: the obligation comes into existence when the invoice is issued,
                    // so effective and posted coincide here. They diverge for corrections and for
                    // migrated history, not for an invoice raised in the ordinary course.
                    SchoolDay::today(),
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
            // The set-based invariant, enforced by the DB, surfacing as a domain error. SCOPED TO
            // scheduled: a supplementary invoice computes a NULL key and cannot collide on that
            // index, so a 1062 naming it while raising one would mean the generated expression is
            // not what this Action believes. Rethrowing the raw QueryException there is deliberate —
            // a 500 that names the index is diagnosable; a friendly 422 telling a bursar to void the
            // term bill before adding a trip fee is a wrong answer stated confidently.
            if ($kind->isEpisodeExclusive() && $this->isActiveEnrollmentCollision($e)) {
                // NAMES THE TERM INVOICE, not "an invoice". Both are true and only one is useful:
                // the episode may also carry any number of supplementary charges, and a bursar who
                // has just raised one and is now told to "void the active invoice" has to guess
                // which. Voiding the wrong one is the expensive mistake — it discards that
                // invoice's payment allocations. Kept in step with the modal's copy in
                // resources/js/components/finance/new-invoice-modal.tsx, which shows the same
                // sentence before the request is made.
                throw new BusinessRuleException(
                    'This enrollment already has an active TERM invoice. Void it before billing the term again.'
                );
            }

            // The reduction_guard (S1 3b) is the AUTHORITY on which reductions may be inserted — a
            // policy-less / non-active / approval-requiring / cross-school reduction line. Surface its
            // refusal as a friendly 422 carrying the trigger's own message, rather than a raw 500 (the
            // credit-note precedent: an untranslated 500 in a money flow is how the operator stops trusting
            // it). This maps the DB's error, it does not pre-empt it — a raw write that never enters this
            // Action still hits the trigger directly (proof 12's direct-write half).
            if ($this->isReductionGuardViolation($e)) {
                throw new BusinessRuleException((string) ($e->errorInfo[2] ?? 'The reduction could not be applied.'));
            }

            throw $e;
        }
    }

    /**
     * Resolve each line's discountability from its fee item (S1 3.6) — SERVER-SIDE, never from the wire. A
     * client does not get to say whether a line is in the percentage base: is_discountable is a property of
     * the fee ITEM. A line with no feeItemId (free text) stays discountable = true (the additive default),
     * so every existing line behaves exactly as before. One query for all cited fee items, keyed by id; a
     * cited id that does not resolve (foreign / deleted) also stays true. FeeItem carries SchoolScope, so
     * this cannot read another School's item — the resolution is implicitly under the active School.
     *
     * @param  list<InvoiceLineSpec>  $lines
     * @return list<InvoiceLineSpec>
     */
    private function resolveDiscountability(array $lines): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (InvoiceLineSpec $line) => $line->feeItemId, $lines),
            static fn (?int $id) => $id !== null,
        )));

        if ($ids === []) {
            return $lines;
        }

        $flags = FeeItem::query()->whereIn('id', $ids)->pluck('is_discountable', 'id');

        return array_map(
            static fn (InvoiceLineSpec $line) => $line->feeItemId === null
                ? $line
                : $line->withDiscountable((bool) ($flags[$line->feeItemId] ?? true)),
            $lines,
        );
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
            // Skip non-discountable charge lines (S1 3.6): a "50% staff-child discount" takes 50% off the
            // discountable charges only — tuition, not transport/feeding an item marks is_discountable=false.
            if (! $line->isReduction() && ! $line->isPercentage() && $line->isDiscountable) {
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
     * Settle the just-created invoice from the student's carry-forward credit, up to
     * $applyKobo (= min(credit, invoice total)), sourcing the OLDEST unallocated
     * payment(s) first as REAL payment-allocations — `payment_id` set to those payments,
     * no credit-funded allocation and no touch to payment_id's NOT NULL (fork 6 is §10).
     * Applying credit posts NOTHING to the ledger; it is a settlement link, so the
     * balance is unchanged and only the invoice's outstanding falls.
     *
     * APPLIES min(credit, total, Σunallocated-payments) — the §10 C1 relax. Only credit
     * BACKED BY A PAYMENT can become a per-invoice allocation (a credit note has no
     * payment to source). Any remainder — credit-note credit with nothing to draw from —
     * is NOT under-applied silently: it is already in the negative account balance (the
     * credit note posted its own ledger credit), and the new charge above has already
     * netted against it, so the account owes the correct amount. It carries at the
     * ACCOUNT level, which is the right home for a credit note's effect; it is not a
     * settlement of this one future invoice. (Before credit notes existed, Σunallocated ≥
     * credit always held, so this cap equalled min(credit, total) and W3's overpayment
     * behaviour is unchanged — proven in WalletApplyForwardTest.)
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
                // A DIFFERENT RULE FROM RecordPayment'S, and the distinction is the point of the
                // column: this allocation links money that arrived BEFORE the charge existed, drawn
                // oldest-payment-first, whereas RecordPayment allocates a payment against the
                // invoice that payment itself names. Stamping both with one rule name would make an
                // append-only row assert a provenance it does not have.
                //
                // NO DATE COLUMN HERE, deliberately. The allocation is a settlement LINK, not an
                // economic event: it posts nothing to the ledger (see this method's docblock), so
                // it has no period of its own to record. The two dates that matter already exist on
                // the rows it links — the payment's received_at and the invoice charge's
                // effective_at — and duplicating either here would create a third date that can
                // disagree with them and never be corrected.
                'allocation_rule' => PaymentAllocation::RULE_CREDIT_APPLIED_FORWARD_OLDEST_FIRST,
                'allocation_overridden' => false,
                'allocation_override_reason' => null,
            ]);

            $remaining -= $draw;
        }

        // A leftover $remaining is EXPECTED when credit-note credit exceeds what payments
        // can source (§10 C1): it is already in the negative account balance and the new
        // charge has netted against it, so there is nothing to fail on. The balance is the
        // universal carry-forward; the allocation is only the payment-sourced visibility.
    }

    private function assertNoActiveInvoice(int $schoolId, int $enrollmentId): void
    {
        // DELEGATES rather than restating the predicate. This is the same call the modal's
        // `already_invoiced` preview makes, which is the point: the warning a bursar sees and the
        // refusal they get must be the same question asked once.
        //
        // EQUIVALENT TO THE QUERY IT REPLACED, checked rather than assumed. It is the same builder
        // on the same connection, so it is still the first PLAIN read after the account
        // lockForUpdate above — the statement this transaction's REPEATABLE READ snapshot forms
        // at, exactly as before (see the comment on that lock). The School is still named
        // EXPLICITLY and still comes from the episode, so isolation does not fall back to the
        // global SchoolScope, which applies no filter at all when there is no context and no
        // authenticated principal.
        $exists = $this->invoices->hasActiveScheduledInvoiceForEnrollment($enrollmentId, $schoolId);

        if ($exists) {
            throw new BusinessRuleException(
                'This enrollment already has an active TERM invoice. Void it before billing the term again.'
            );
        }
    }

    private function isActiveEnrollmentCollision(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === self::DUPLICATE_ENTRY
            && str_contains($e->getMessage(), 'finance_invoices_active_enrollment_unique');
    }

    /**
     * A SIGNAL from finance_invoice_lines_reduction_guard (S1 3b): MySQL maps SQLSTATE '45000' to error
     * 1644, and every message the guard raises names the "discount policy" — narrow enough to not catch an
     * unrelated 1644 from some other trigger.
     */
    private function isReductionGuardViolation(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === self::SIGNAL_EXCEPTION
            && str_contains($e->getMessage(), 'discount policy');
    }
}

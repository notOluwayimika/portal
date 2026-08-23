<?php

namespace App\Finance\Actions;

use App\Finance\Exceptions\AllocationRefused;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Finance\Models\StudentAccount;
use App\Finance\Services\AllocationProposal;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * U10 — DIRECT AN ALREADY-RECORDED PAYMENT'S UNALLOCATED REMAINDER onto invoices an operator chose.
 * The THIRD writer of `finance_payment_allocations`, and the first one a human drives.
 *
 * IT MOVES NO MONEY, and that is the fact everything else here follows from. The payment's cash was
 * posted to the subledger when it was recorded — `RecordPayment` / `RecordAccountPayment` post the
 * FULL amount as a ledger credit — so an allocation is a SETTLEMENT LINK and nothing else. Exactly
 * as {@see GenerateInvoice::applyCreditForward} does it: allocation rows are written, `balance_minor`
 * is untouched, and only the named invoices' outstanding falls. There is no `SubledgerPoster::post`
 * call in this file and there must never be one; a second credit for cash that arrived once would
 * double-count the payment.
 *
 * ── THE LOCK, AND WHY IT IS THE FIRST STATEMENT ──
 *
 * `StudentAccount ... lockForUpdate()` is the unconditional first statement of the transaction. Not
 * a habit copied from GenerateInvoice — the named residual left open by
 * `docs/handoff/tickets/nothing-constrains-allocations-to-a-payments-amount.md`, and this Action is
 * exactly the writer that ticket predicted:
 *
 *   "The coverage above is a property of the two writers that exist today, not of the schema. A
 *    future writer that allocates against a payment without joining the account-row lock — a job, a
 *    bulk correction, a second path — would race, and this trigger would not catch it."
 *
 * THE TRIGGER IS NOT AND CANNOT BE THE CONCURRENCY ANCHOR. `finance_allocation_not_over_payment_amount`
 * reads `SUM(amount_minor)` with a plain SELECT, which cannot see another transaction's uncommitted
 * allocation — measured on that branch, not conceded: two connections each inserting 5001 against a
 * 10000 payment BOTH pass and Σ ends at 10002. A trigger cannot take a lock that outlives its own
 * statement, so this cannot be pushed into the database. What covers the axis today is the account
 * row, and it covers it because every payment whose remainder this Action can draw belongs to the one
 * student whose account row is held — a strictly coarser serialisation point than the payment row.
 * `AllocatePaymentConcurrencyTest` measures it, and reds when the lock is removed.
 *
 * ORDERING: account-then-nothing-else. `RecordPayment` locks the INVOICE row and touches the account
 * only through `post()`'s atomic increment; `GenerateInvoice` locks the ACCOUNT row first. This Action
 * takes the account row and no other row lock at all, so it introduces no opposite-order pair and no
 * deadlock (docs/finance/concurrency.md).
 *
 * A LOCKING READ DOES NOT ESTABLISH THE REPEATABLE READ SNAPSHOT — it forms at the first plain read
 * AFTER it. So the proposal re-derived below is a CURRENT read of committed state, not a stale one,
 * which is the same property GenerateInvoice's first statement relies on and the reason the
 * re-derivation is trustworthy rather than decorative.
 *
 * ── NO MAKER-CHECKER, AND THIS IS A DECISION RATHER THAN AN OMISSION ──
 *
 * All four actions behind `ApprovalRequirement` REDUCE A RECEIVABLE: a credit note forgives a charge,
 * a write-off abandons it, a void reverses it, an opening balance posts a position that was never
 * billed here. Each needs two people because one person could otherwise make a debt disappear.
 *
 * An allocation does not reduce anything. The student's balance is identical before and after — the
 * ledger is untouched — and the money was already the school's. What changes is WHICH invoice a
 * payment is recorded against, and the total owed is the same number afterwards. Requiring a second
 * person for a write that cannot change what is owed would spend the checker's attention where
 * nothing is at stake, and every approval that is routine is one that gets rubber-stamped, which is
 * how the four that do matter lose their force.
 *
 * The controls that ARE proportionate, and all three are built: the row names the operator
 * (`allocated_by_user_id`, required for this rule by `finance_allocation_provenance_pairing_bi`), a
 * departure from the proposal carries a marker and a REQUIRED reason (`allocation_overridden` +
 * `allocation_override_reason`, paired by the same trigger), and the table is append-only, so the
 * trail cannot be edited away.
 *
 * WHAT WOULD CHANGE THIS, written down so the decision can be re-opened on evidence rather than
 * re-argued from scratch:
 *
 *   · AN ALLOCATION THAT COULD REDUCE A RECEIVABLE. Un-allocation and re-allocation are the obvious
 *     shapes — moving a settlement off an invoice raises that invoice's outstanding, and doing it
 *     without a compensating link would discharge nothing while appearing to. Both are explicitly out
 *     of scope here and neither exists; if either is built, this paragraph is the reason to reconsider.
 *   · A SCHOOL ASKING FOR IT. Brookstone's stated approval list is scholarships, discounts,
 *     concessions, refunds and write-offs — every item a reduction. If a school says an allocation
 *     belongs on that list, the school is the authority on its own controls, not this docblock.
 *
 * ── WHAT THIS ACTION DOES NOT DO ──
 *
 * It never un-allocates and never edits. `finance_payment_allocations` carries `_no_update` and
 * `_no_delete` (2026_07_19_110000), so a correction after submit is a compensating write and not an
 * edit — which is why the screen has to say so at the point of submit, and does.
 */
final class AllocatePayment
{
    public function __construct(private readonly AllocationProposal $proposals) {}

    /**
     * @param  list<array{invoice_id: string, amount_minor: int}>  $directions  The operator's split, in
     *                                                                          the wire's own shape so a refusal can name the row it is
     *                                                                          about by index. An invoice the proposal offered and this
     *                                                                          list omits — or carries at zero — is a direction of
     *                                                                          zero, which is a real operator decision whenever the
     *                                                                          proposal proposed something for it.
     * @param  string  $fingerprint  The proposal token the screen was rendered from. See
     *                               {@see AllocationProposal} for why an edit cannot be told from
     *                               concurrent drift without it.
     * @return Collection<int, PaymentAllocation>
     */
    public function handle(Payment $payment, array $directions, string $fingerprint, User $actor, ?string $overrideReason = null): Collection
    {
        return DB::transaction(function () use ($payment, $directions, $fingerprint, $actor, $overrideReason) {
            // THE FIRST STATEMENT. See the docblock: this is the whole safety argument for the
            // payment axis, and the trigger beneath it is a single-write backstop that cannot
            // serialise anything.
            $account = StudentAccount::query()
                ->where('student_id', $payment->student_id)
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                // Unreachable through any live path — SubledgerPoster::post upserts the account row
                // on every payment, and a payment cannot exist without one. It refuses rather than
                // proceeding because the alternative is running the rest of this transaction with
                // NOTHING serialising it, which is precisely the residual this Action exists to close.
                throw new AllocationRefused(
                    'This student has no finance account row, so an allocation cannot be serialised against it. Nothing was written.',
                    'allocations',
                );
            }

            // Re-derived UNDER the lock, and therefore a current read of committed state rather than
            // whatever the screen was rendered from.
            $proposal = $this->proposals->for($payment);

            if (! hash_equals($proposal['fingerprint'], $fingerprint)) {
                throw new AllocationRefused(
                    'This student’s position changed while this screen was open — another payment or invoice landed. '
                    .'Reload and check the split before submitting; nothing was written.',
                    'fingerprint',
                );
            }

            $offered = collect($proposal['invoices'])->keyBy('id');
            $currency = $payment->amount->currency;
            $remainingKobo = $proposal['payment']['unallocated']->toKobo();

            if ($remainingKobo <= 0) {
                throw new AllocationRefused(
                    'This payment has nothing left to allocate.',
                    'allocations',
                );
            }

            $byUuid = [];
            $total = 0;

            foreach ($directions as $index => $direction) {
                $uuid = $direction['invoice_id'];

                // CAST, AND THE CAST IS LOAD-BEARING — it is not defensive tidying.
                //
                // The departure comparison below is `!==`, and the JSON string "3000" is not identical
                // to the integer 3000. Uncast, a submission byte-identical to the proposal was recorded
                // as an OVERRIDE nobody made: `allocation_overridden = 1` plus a reason the operator was
                // compelled to invent for a change they had not made, written onto a row that carries
                // `_no_update` and `_no_delete`. Measured against the live route before this line
                // existed; the four reproductions are in this branch's report.
                //
                // THE FORMREQUEST'S `integer:strict` IS THE OTHER HALF AND NEITHER IS SUFFICIENT ALONE.
                // That rule shuts the HTTP door; this Action is documented above as reachable off-HTTP,
                // and a job or console caller passing a numeric string meets no FormRequest at all. A
                // guard placed only at the edge protects only the callers that go through the edge.
                $amountKobo = (int) $direction['amount_minor'];

                if (array_key_exists($uuid, $byUuid)) {
                    // Two rows for one invoice. Summing them would be a guess about what the operator
                    // meant, and refusing them independently would let the pair pass every per-row cap
                    // while their total exceeds the invoice's outstanding.
                    throw new AllocationRefused(
                        'This invoice appears twice in the submission. Each invoice takes one amount.',
                        "allocations.{$index}.invoice_id",
                    );
                }

                $row = $offered->get($uuid);

                if ($row === null) {
                    // Either not this student's, or not open, or void. The message does not say which:
                    // the caller supplied an id the proposal did not offer, and enumerating the reasons
                    // it might not have would answer a question about invoices this operator was not
                    // shown.
                    throw new AllocationRefused(
                        'This invoice is not one of the open invoices this payment can settle. Reload the proposal.',
                        "allocations.{$index}.invoice_id",
                    );
                }

                if ($amountKobo < 0) {
                    // A negative allocation would RAISE an invoice's outstanding — an un-allocation
                    // wearing an allocation's shape, on a table that cannot be edited afterwards.
                    throw new AllocationRefused(
                        'An allocation must be a positive amount. Taking money back off an invoice is not something this screen can do.',
                        "allocations.{$index}.amount_minor",
                    );
                }

                $byUuid[$uuid] = $amountKobo;

                // A ZERO IS RECORDED AND THEN SKIPPED, not rejected. The screen posts every offered
                // invoice, so zero is how an operator says "not this one" — and it must still count
                // for the duplicate check above and for the departure comparison below, while writing
                // no row (an allocation of nothing is a row that asserts a settlement that did not
                // happen).
                if ($amountKobo === 0) {
                    continue;
                }

                if (! $row['allocatable']) {
                    throw new AllocationRefused((string) $row['blocked_reason'], "allocations.{$index}.amount_minor");
                }

                if ($amountKobo > $row['outstanding']->toKobo()) {
                    // The invoice axis, refused in words — and THIS COMMENT USED TO CLAIM MORE THAN IS
                    // TRUE. It said `finance_allocation_not_over_invoice_total` "is the authority and
                    // stays reachable for any writer that does not come through here", which is the same
                    // sentence the payment-axis ticket demolished for that trigger's sibling, and it is
                    // false here for the same reason.
                    //
                    // WHAT THE TRIGGER ACTUALLY GUARANTEES: it refuses what a SINGLE TRANSACTION can
                    // see. Its `SELECT SUM` is a plain read and cannot see another transaction's
                    // uncommitted allocation, so it is a single-write backstop and not a serialisation
                    // point — exactly as its payment-axis sibling is.
                    //
                    // AND THE INVOICE AXIS IS NOT SERIALISED ACROSS WRITERS. The three writers hold
                    // DISJOINT locks: RecordPayment locks the INVOICE row, GenerateInvoice::
                    // applyCreditForward and this Action lock the ACCOUNT row and never touch the
                    // invoice row. Two of them therefore never block each other, and the cold review
                    // measured Σ = 20000 against a 10000 invoice. That pair PRE-DATES this branch; what
                    // this Action adds is a third writer on the uncovered axis. It is recorded, with the
                    // measurement, in docs/handoff/tickets/the-invoice-axis-is-not-serialised-across-writers.md
                    // and is deliberately NOT fixed here — closing it needs its own concurrency
                    // argument, which is how the payment axis was handled.
                    throw new AllocationRefused(
                        'That is more than invoice '.$row['display_number'].' still owes ('.$row['outstanding']->format().').',
                        "allocations.{$index}.amount_minor",
                    );
                }

                $total += $amountKobo;
            }

            if ($total === 0) {
                throw new AllocationRefused(
                    'Enter an amount against at least one invoice.',
                    'allocations',
                );
            }

            if ($total > $remainingKobo) {
                // The payment axis, refused in words — the same ceiling
                // finance_allocation_not_over_payment_amount enforces, reached before the database has
                // to. A 1644 surfacing as a 500 tells an operator nothing they can act on.
                throw new AllocationRefused(
                    'That allocates more than this payment has left ('.$proposal['payment']['unallocated']->format().').',
                    'allocations',
                );
            }

            // THE DEPARTURE IS COMPUTED OVER EVERY OFFERED INVOICE, not only over the ones the
            // operator typed into. Declining a proposed allocation by leaving its field empty is as
            // much an operator decision as changing a figure, and a comparison that walked only the
            // submitted rows would record it as no decision at all.
            $overridden = [];

            foreach ($offered as $uuid => $row) {
                $submitted = $byUuid[$uuid] ?? 0;

                if ($submitted !== $row['proposed']->toKobo()) {
                    $overridden[$uuid] = true;
                }
            }

            $reason = $overrideReason === null ? null : trim($overrideReason);

            if ($overridden !== [] && ($reason === null || $reason === '')) {
                throw new AllocationRefused(
                    'You changed the proposed split, so a reason is required. It is written onto the allocation rows and cannot be edited afterwards.',
                    'override_reason',
                );
            }

            if ($overridden === [] && $reason !== null && $reason !== '') {
                // REFUSED RATHER THAN DISCARDED. `allocation_override_reason` must be null when the
                // marker is false (the pairing trigger enforces it), so the alternative is accepting
                // text the operator typed and silently dropping it — which is worse than saying so.
                throw new AllocationRefused(
                    'This submission matches the proposal exactly, so there is nothing to explain. Clear the reason, or change a figure.',
                    'override_reason',
                );
            }

            // uuid -> integer id, resolved through the SCHOOL-SCOPED model and restricted to the
            // uuids the proposal itself offered. The wire never carries an invoice's integer key (U8
            // commit 1), and the proposal is deliberately not widened to leak one just because this
            // writer needs it — the loop above has already established that every uuid here is one of
            // this student's open invoices, so this resolves ids for a set that is already vouched for.
            $written = array_filter($byUuid, static fn (int $amountKobo) => $amountKobo > 0);

            $invoiceIds = Invoice::query()->whereIn('uuid', array_keys($written))->pluck('id', 'uuid');

            $created = new Collection;

            foreach ($written as $uuid => $amountKobo) {
                $isOverridden = isset($overridden[$uuid]);

                $created->push($payment->allocations()->create([
                    'school_id' => $payment->school_id,
                    'invoice_id' => (int) $invoiceIds[$uuid],
                    'amount' => Money::fromKobo($amountKobo, $currency),
                    'allocation_rule' => PaymentAllocation::RULE_OPERATOR_DIRECTED_REMAINDER,
                    // PER ROW, not per submission. An operator who accepts the proposal for one
                    // invoice and changes another has made one edit, not two, and stamping the
                    // untouched row as overridden would assert a choice about it that nobody made —
                    // on a row that can never be corrected.
                    'allocation_overridden' => $isOverridden,
                    'allocation_override_reason' => $isOverridden ? $reason : null,
                    // Required for this rule by finance_allocation_provenance_pairing_bi, and the
                    // reason the null on an engine row means "no human chose this".
                    'allocated_by_user_id' => $actor->id,
                ]));
            }

            return $created;
        });
    }
}

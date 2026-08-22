<?php

namespace App\Finance\Services;

use App\Finance\Actions\AllocatePayment;
use App\Finance\Enums\CreditNoteStatus;
use App\Finance\Models\BankAccount;
use App\Finance\Models\FeeItem;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * U10's READ SIDE — what the engine WOULD do with a payment's unallocated remainder, computed and
 * returned, with no write path in it. The operator edits the proposal; {@see AllocatePayment}
 * is what turns an edited proposal into rows.
 *
 * THE PROPOSAL IS OLDEST-INVOICE-FIRST, and that is not a fresh choice. ADR 0048 D2 made
 * `GenerateInvoice::applyCreditForward`'s oldest-first the SINGLE settlement order in the system by
 * deleting the newest-first workaround that competed with it. This surface proposes what that engine
 * would do if the next invoice generation drew this remainder forward, so it orders the same way and
 * for the same reason: `orderBy('id')` is monotonic with creation and free of second-precision ties.
 * A different order here would make the screen's proposal disagree with what happens if the operator
 * closes the tab and lets the engine do it.
 *
 * NOTHING HERE IS AUTHORITY. Every figure below is a plain read on a snapshot; two of them are
 * read-then-write inputs that a concurrent writer can invalidate between this response and the
 * submit. The Action re-derives all of them under the student-account row lock, and the
 * `finance_allocation_not_over_payment_amount` / `_invoice_total` triggers are the floor beneath
 * that. This class exists to show an operator a starting point, not to decide anything.
 *
 * ── THE BANK-ACCOUNT DESTINATION IS A LIVE LOOKUP, NOT A SNAPSHOT, AND THE SCREEN SAYS SO ──
 *
 * The MVP cut brief (§9 item 6) says the account "must be snapshotted onto the invoice line". IT IS
 * NOT, and that is deliberate rather than an oversight: `2026_08_10_120000_finance_bank_account_foreign_keys`
 * §"finance_invoice_lines — DELIBERATELY NOT IN SCOPE" argues that a destination column on a table
 * whose lines are free text with no fee item behind them would be null on every row or defaulted to
 * a destination nobody chose. `finance_invoice_lines` therefore has no `bank_account_id`, and the
 * only destination that exists anywhere is `finance_fee_items.bank_account_id` (NOT NULL).
 *
 * So the mismatch this screen must show (cut brief line 307 — money received into account A settling
 * lines destined for account B) is derivable ONLY through `finance_invoice_lines.fee_item_id`, which
 * is nullable LOOKUP provenance with NO foreign key (InvoiceLine's docblock; GenerateInvoiceRequest
 * :215-221) pointing at a MUTABLE row. Two consequences, and both are surfaced rather than smoothed:
 *
 *   · THE STATE IS THREE-VALUED, never two. `unrecorded` — no charge line on this invoice resolves to
 *     a fee item with an account — is NOT `matches`. Rendering an unknown destination as agreement is
 *     precisely the "silently allocate across it" the cut brief forbids, one level more subtle.
 *   · `charge_lines_without_destination` is carried BESIDE the state, so an invoice that resolves two
 *     of its five lines reports `matches` qualified by three lines it could not read, rather than an
 *     unqualified `matches`. The screen renders that qualification; it does not re-derive it.
 *
 * A LIVE LOOKUP CAN ALSO GO STALE IN THE OTHER DIRECTION: the fee item's account can be edited, and
 * a superseded schedule's item still resolves. So this answers "where would this charge's money go if
 * it were billed from the catalog as it stands today", which is the best available answer and is not
 * the same question as "where was it destined when it was billed". The day S11's snapshot lands, this
 * derivation is replaced by reading the line's own column and the ambiguity goes away.
 */
final class AllocationProposal
{
    /**
     * @return array{
     *   payment: array<string, mixed>,
     *   invoices: list<array<string, mixed>>,
     *   proposed_total: Money,
     *   unproposed_remainder: Money,
     *   fingerprint: string,
     * }
     */
    public function for(Payment $payment): array
    {
        $payment->loadMissing('bankAccount');

        // FORCE A FRESH READ OF THE ALLOCATIONS, and this line is load-bearing rather than defensive.
        // Payment::unallocatedAmount() uses the loaded relation when there is one, which is right for
        // a list read and WRONG here: AllocatePayment calls this method after taking the account-row
        // lock, on a Payment instance that may have been loaded — with its allocations — before the
        // lock was granted. Trusting that relation would make the re-derivation the lock exists to
        // enable read from exactly the stale snapshot the lock was taken to escape, and the remainder
        // would be computed as though a competitor's committed rows did not exist.
        $payment->unsetRelation('allocations');

        $currency = $payment->amount->currency;

        // THROUGH THE MODEL'S OWN EXPRESSION, not a second copy of it. Payment::unallocatedAmount() is
        // what a statement row reads to decide whether to offer this screen at all, and this proposal
        // is what the screen shows once opened — two spellings of "how much of this payment is
        // unspent" would put an offer and the thing it opens in disagreement. Unfloored there and so
        // here: see that method for why a negative must surface rather than clamp.
        $remaining = $payment->unallocatedAmount();
        $allocatedKobo = $payment->amount->toKobo() - $remaining->toKobo();

        $invoices = $this->openInvoices($payment);
        $destinations = $this->destinationsFor($invoices, $payment->bank_account_id);

        // The walk. Integer minor units throughout; the running remainder is the only mutable
        // quantity and it never goes below zero because every draw is min()'d against it.
        $remainingKobo = max(0, $remaining->toKobo());
        $proposedKobo = 0;
        $rows = [];

        foreach ($invoices as $invoice) {
            $outstandingKobo = $this->outstandingKobo($invoice);

            // A cross-currency invoice is LISTED AND BLOCKED, never dropped. The
            // finance_allocation_not_over_payment_amount trigger refuses an allocation whose currency
            // differs from the payment's, so proposing one would propose a row the database will not
            // take; hiding the invoice would tell an operator their student has fewer open bills than
            // they do. The row states the reason instead.
            $blocked = $invoice->total->currency !== $currency
                ? "This invoice is in {$invoice->total->currency} and the payment is in {$currency}. A payment can only settle invoices in its own currency."
                : null;

            $draw = ($blocked === null) ? min($remainingKobo, $outstandingKobo) : 0;
            $remainingKobo -= $draw;
            $proposedKobo += $draw;

            $rows[] = [
                'id' => $invoice->uuid,
                'display_number' => $invoice->displayNumber(),
                // Scheduled (the term bill) or supplementary (a trip, a damaged appliance). The wire
                // for it landed in #269; an operator directing money needs to know which of a
                // student's several open bills is the term bill.
                'kind' => $invoice->kind->value,
                'academic_context' => $invoice->academic_context,
                'total' => $invoice->total,
                'outstanding' => Money::fromKobo($outstandingKobo, $invoice->total->currency),
                'proposed' => Money::fromKobo($draw, $currency),
                'allocatable' => $blocked === null,
                'blocked_reason' => $blocked,
                'destination' => $destinations[$invoice->id],
            ];
        }

        return [
            'payment' => [
                'id' => $payment->uuid,
                'reference' => $payment->reference,
                'payer_name' => $payment->payer_name,
                'method' => $payment->method,
                'amount' => $payment->amount,
                'allocated' => Money::fromKobo($allocatedKobo, $currency),
                'unallocated' => $remaining,
                // Formatted SERVER-SIDE. bin/ci-money-lint.php's format ban is total inside
                // resources/js/pages/admin/finance/, so a toLocaleString on the page is a lint
                // finding — the same reason PaymentReceiptController:133-139 formats its two dates.
                'received_at' => $payment->received_at->format('j F Y'),
                'received_at_reason' => $payment->received_at_reason,
                // Null for a migrated row (the origin pairing trigger enforces exactly that), which
                // is why every consumer of this must render an absence rather than assume a label.
                'bank_account' => $payment->bankAccount === null ? null : [
                    'label' => $payment->bankAccount->label,
                    'bank_name' => $payment->bankAccount->bank_name,
                ],
            ],
            'invoices' => $rows,
            'proposed_total' => Money::fromKobo($proposedKobo, $currency),
            // What the proposal could NOT place: the student has no open invoice left to absorb it
            // (or what remains is cross-currency). It stays on the account as credit and the next
            // generation draws it forward. Stated so the operator is not left comparing two figures.
            'unproposed_remainder' => Money::fromKobo($remainingKobo, $currency),
            'fingerprint' => $this->fingerprint($payment, $remaining, $rows),
        ];
    }

    /**
     * The student's OPEN invoices, oldest first — issued, not void, and still carrying an
     * outstanding balance.
     *
     * Settled invoices are excluded because an allocation to one is arithmetic with no meaning: the
     * invoice-axis trigger would refuse it and there is nothing for an operator to direct money at.
     * VOID invoices are excluded by `excludingVoid()` — the read model's rule, not a global scope.
     *
     * The two withSum aggregates mirror InvoiceReadModel::forStudent exactly, because
     * {@see InvoiceSettlement} is what reads them and this screen's outstanding must be the same
     * number the statement shows for the same invoice. A second spelling of that sum is how two
     * surfaces come to disagree about what a student owes.
     *
     * @return Collection<int, Invoice>
     */
    private function openInvoices(Payment $payment): Collection
    {
        return Invoice::query()
            ->where('student_id', $payment->student_id)
            ->excludingVoid()
            ->with('lines')
            ->withSum('allocations as allocated_minor', 'amount_minor')
            ->withSum(['creditNotes as approved_credit_minor' => fn ($q) => $q->where('status', CreditNoteStatus::Approved->value)], 'amount_minor')
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice) => $this->outstandingKobo($invoice) > 0)
            ->values();
    }

    /**
     * total − Σ(allocations) − Σ(approved credit notes), floored at zero.
     *
     * FLOORED HERE AND UNFLOORED ON THE PAYMENT ABOVE, and the asymmetry is deliberate rather than
     * inconsistent. A negative payment remainder means a violating row exists and must be seen. A
     * negative invoice outstanding is ORDINARY — it is a paid invoice later credit-noted, whose true
     * credit position lives on the account balance ({@see InvoiceSettlement}, which floors it for the
     * same reason and whose figure this must equal). Proposing a negative draw is meaningless either
     * way; min() against the remainder would already make it zero.
     */
    private function outstandingKobo(Invoice $invoice): int
    {
        return max(0, $invoice->total->toKobo()
            - (int) ($invoice->getAttribute('allocated_minor') ?? 0)
            - (int) ($invoice->getAttribute('approved_credit_minor') ?? 0));
    }

    /**
     * Where each invoice's CHARGE lines were destined, and whether that is the account the money
     * landed in. See this class's docblock for why this is a live lookup through `fee_item_id` and
     * not a snapshot.
     *
     * CHARGE LINES ONLY. A waiver or discount line reduces a bill; it has no destination account and
     * counting it as an unresolved one would report every discounted invoice as partially unknown.
     *
     * ONE QUERY for every fee item cited across every invoice, and one for the accounts — not a
     * lookup per line. FeeItem and BankAccount both carry SchoolScope, so a cited id belonging to
     * another School resolves to nothing here rather than leaking a label.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array<int, array<string, mixed>>
     */
    private function destinationsFor(Collection $invoices, ?int $paymentAccountId): array
    {
        $feeItemIds = $invoices
            ->flatMap(fn (Invoice $invoice) => $invoice->lines
                ->reject(fn ($line) => $line->isReduction())
                ->pluck('fee_item_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        /** @var array<int, int> $itemToAccount */
        $itemToAccount = $feeItemIds === []
            ? []
            : FeeItem::query()->whereIn('id', $feeItemIds)->pluck('bank_account_id', 'id')->all();

        $accounts = $itemToAccount === []
            ? collect()
            : BankAccount::query()->whereIn('id', array_values($itemToAccount))->get()->keyBy('id');

        $out = [];

        foreach ($invoices as $invoice) {
            $charges = $invoice->lines->reject(fn ($line) => $line->isReduction());
            $resolved = [];
            $unresolved = 0;

            foreach ($charges as $line) {
                $accountId = $line->fee_item_id === null ? null : ($itemToAccount[$line->fee_item_id] ?? null);

                if ($accountId === null || ! $accounts->has($accountId)) {
                    $unresolved++;

                    continue;
                }

                $resolved[$accountId] = $accounts->get($accountId);
            }

            $out[$invoice->id] = [
                // THREE STATES. `unrecorded` is not `matches`; see the class docblock. A payment with
                // no bank account of its own (a migrated row) can never match, so it reports
                // `unrecorded` too — the comparison has no left-hand side, which is a thing not known
                // rather than a disagreement.
                'state' => match (true) {
                    $resolved === [] || $paymentAccountId === null => 'unrecorded',
                    array_keys($resolved) === [$paymentAccountId] => 'matches',
                    default => 'differs',
                },
                'accounts' => array_values(array_map(
                    fn (BankAccount $account) => ['label' => $account->label, 'bank_name' => $account->bank_name],
                    $resolved,
                )),
                // How much of the invoice this answer does NOT cover. `matches` with a non-zero count
                // here means "as far as we can read", and the screen must say that rather than
                // rendering an unqualified agreement.
                'charge_lines_without_destination' => $unresolved,
            ];
        }

        return $out;
    }

    /**
     * THE POSITION THIS PROPOSAL WAS COMPUTED FROM, hashed — an optimistic-concurrency token, and it
     * exists for one specific defect rather than for tidiness.
     *
     * {@see \\App\\Finance\\Actions\\AllocatePayment} decides `allocation_overridden` by comparing what the
     * operator submitted against the proposal RE-COMPUTED under the account-row lock. Without a token,
     * a concurrent `GenerateInvoice` drawing this remainder forward — or another payment landing on
     * one of these invoices — moves that baseline between the render and the submit, and an operator
     * who accepted the proposal verbatim gets their rows stamped `allocation_overridden = 1` and is
     * asked for a reason for a change they did not make. The table is append-only, so that false
     * attribution is permanent, and PaymentAllocation's own docblock is explicit that a wrong
     * attribution is harder to notice than a missing one.
     *
     * So the Action refuses on a stale token — "reload and look again" — instead of guessing which of
     * the two happened. The token is the ONLY thing that tells the operator's edit apart from the
     * world moving underneath it.
     *
     * IT IS NOT A LOCK AND NOT A PERMISSION. It says the position is unchanged since this render; the
     * account-row lock is what makes the re-derivation trustworthy, and the two allocation triggers
     * are the floor under both.
     *
     * Hashed rather than sent as a structure so a client cannot construct a plausible-looking one by
     * hand: reproducing it requires the server's own view of every figure it covers.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function fingerprint(Payment $payment, Money $unallocated, array $rows): string
    {
        $canonical = [
            $payment->uuid,
            $unallocated->toKobo(),
            $unallocated->currency,
        ];

        foreach ($rows as $row) {
            // Every figure the operator's decision rests on: WHICH invoices were offered, in what
            // ORDER, what each one still owed, whether it could be allocated to at all, and what was
            // proposed for it. A change to any of them is a different question being answered.
            $canonical[] = implode('|', [
                $row['id'],
                $row['outstanding']->toKobo(),
                $row['allocatable'] ? '1' : '0',
                $row['proposed']->toKobo(),
            ]);
        }

        return hash('sha256', implode("\n", $canonical));
    }
}

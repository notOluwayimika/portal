<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Models\GatewayTransaction;
use App\Finance\Models\Invoice;
use App\Finance\Services\GatewayFeeCalculator;
use App\Finance\Services\GatewayMinimumPayment;
use App\Finance\Services\GatewayReference;
use App\Finance\Services\PaystackClient;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Step 3 — starts a gateway payment against ONE invoice, and records the attempt before the payer
 * ever reaches Paystack.
 *
 * ── THE ORDER OF THE GUARDS IS THE DESIGN ──
 *
 * Every refusal happens BEFORE `PaystackClient::initialize()` is called, because after that call the
 * payer can be charged and every refusal downstream becomes money we have to reconcile by hand
 * rather than a message we can show them. The one thing that must never happen here is a checkout
 * URL for a payment we would have refused.
 *
 * ── RELEASE IS CHECKED SERVER-SIDE, NEVER BY TRUSTING THE FEED ──
 *
 * `InvoiceReadModel::outstandingForStudent()` already withholds unreleased invoices from the
 * parent's list, so a client cannot ordinarily offer one. That is PRESENTATION. A client that hides
 * a button and a server that refuses a request are different guarantees, and only the second
 * survives a crafted POST, a stale tab, or a client the school did not write. The check is repeated
 * here against the invoice the request actually named.
 *
 * ── RELEASE IS ONE-WAY, SO THE WINDOW HERE IS NOT A NEW ONE ──
 *
 * Segun signed off on 2026-09-01: release only ever moves toward LESS payable. That puts it in the
 * same family as void, settled and currency — axes this system already has a time-of-check window
 * on, between a payer starting and the delivery arriving.
 *
 * SO THE WINDOW IS THE ONE THOSE THREE ALREADY HAVE. It is not handled, and saying it were would be
 * the overclaim: what is true is that it is not a NEW class needing new machinery. The webhook
 * records whatever arrives, because money that has landed is a fact and refusing at settlement does
 * not un-take it — it only detaches the evidence from the invoice the parent chose. Detection
 * belongs to step 7's report:
 * docs/handoff/tickets/discrepancy-report-fifth-class-release-withdrawn.md
 *
 * ── OVERPAYMENT NEEDS NO NEW MACHINERY ──
 *
 * Developer 1 ruled that a payer may pay partial, clear the outstanding, or overpay. Nothing here
 * caps the amount at outstanding: `RecordPayment` caps the ALLOCATION at outstanding and the excess
 * banks as account credit under a rule that already exists. Capping here would silently refuse a
 * payment the ruling permits.
 */
final class InitiateGatewayPayment
{
    public function __construct(
        private readonly PaystackClient $paystack,
        private readonly GatewayFeeCalculator $fees,
        private readonly GatewayMinimumPayment $minimum,
    ) {}

    /**
     * @param  Money  $bill  what the PAYER chose to pay — not the outstanding, and not capped to it
     *
     * @throws BusinessRuleException every refusal, all of them before the provider is called
     */
    public function handle(Invoice $invoice, Money $bill, User $payer, ?string $callbackUrl = null): GatewayTransaction
    {
        if ($bill->isZero() || $bill->isNegative()) {
            throw new BusinessRuleException('A payment amount must be positive.');
        }

        // ⚠️ THIS IS A COLUMN TEST BEHIND A WRAPPER. IT IS NOT THE RELEASE CHECK.
        //
        // `Invoice::isReviewed()` is `reviewed_at !== null` and nothing more. Developer 1's
        // instruction is to call a Finance-owned predicate — `isReleasedToPayers()` — rather than
        // compare a column, because rejection modelling is still open with Brookstone. If a REJECTED
        // bill ends up stamping `reviewed_at`, this passes a bill an auditor has just refused.
        //
        // That predicate DOES NOT EXIST YET (grepped 2026-09-01: zero hits in app/, resources/,
        // tests/). It is Developer 1's to add and he has offered; implementing a private one here
        // would create the third reader of a rule that already has two implementations.
        //
        // KEPT AS AN INTERIM, NOT BECAUSE IT IS SUFFICIENT. No release check at all fails OPEN on
        // the axis that matters — a parent paying a bill Internal Audit has not released — and that
        // is strictly worse than a check correct under the current rejection shape and wrong under
        // one Brookstone has not chosen. Swap it the day the shared predicate lands:
        // docs/handoff/tickets/release-predicate-has-two-implementations.md
        if (! $invoice->isReviewed()) {
            throw new BusinessRuleException(
                'This bill has not been released for payment yet. It is with Internal Audit for review.'
            );
        }

        if ($invoice->isVoid()) {
            throw new BusinessRuleException('This bill has been cancelled and cannot be paid.');
        }

        if ($bill->currency !== $invoice->total->currency) {
            throw new BusinessRuleException("A payment must be in the bill's currency ({$invoice->total->currency}).");
        }

        // Throws when unconfigured — deliberately, and before anyone is charged.
        $minimum = $this->minimum->forCurrency($bill->currency);

        if ($bill->toKobo() < $minimum->toKobo()) {
            throw new BusinessRuleException(
                'The smallest payment this school accepts online is '.$minimum->format().'.'
            );
        }

        // THE GROSS IS SOLVED FOR THE CHOSEN AMOUNT, not for the outstanding. The three regimes were
        // derived against bills, and the bill here is whatever the payer typed — a part-payment, the
        // whole outstanding, or more than it.
        $gross = $this->fees->grossFor($bill);

        $schoolId = (int) $invoice->school_id;

        // The row is written BEFORE the provider is called, and committed, so a checkout that the
        // payer completes always has a transaction for the webhook to find. The opposite order —
        // initialise, then record — loses the row if the write fails, and the payer is charged
        // against a reference this system has never heard of.
        $transaction = DB::transaction(fn () => GatewayTransaction::create([
            'school_id' => $schoolId,
            'invoice_id' => $invoice->getKey(),
            'provider' => 'paystack',
            // The database enforces this format since 2026_09_01_100000; minting is not optional.
            'reference' => GatewayReference::mint($schoolId),
            'amount' => $gross,
            'bill' => $bill,
            'status' => 'pending',
            'initiated_by_user_id' => $payer->id,
        ]));

        $checkout = $this->paystack->initialize($gross, $transaction->reference, $payer->email, $callbackUrl);

        $transaction->setAttribute('checkout', $checkout);

        return $transaction;
    }
}

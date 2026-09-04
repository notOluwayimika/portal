<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Actions\SettleGatewayTransaction;
use App\Finance\Enums\GatewaySettlementOutcome;
use App\Finance\Models\GatewayTransaction;
use App\Finance\Services\GatewayReference;
use App\Finance\Services\GuardianPaymentAuthorisation;
use App\Http\Controllers\Controller;
use App\Support\ActiveSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §6 STEP 6 — where Paystack sends the payer back, and what they are told.
 *
 * ── IT MAKES RECOVERY FAST, NOT POSSIBLE, AND THAT IS WORTH SAYING PLAINLY ─────────────────────
 *
 * Since #370 a redelivered webhook RE-VERIFIES: `SettleGatewayTransaction::handle()` records the
 * delivery and calls {@see SettleGatewayTransaction::settleFromProvider()} every time, so a
 * transaction stranded by an earlier unreachable verify recovers on Paystack's own retry schedule
 * with no human involved.
 *
 * **So this screen is not the recovery path.** It is the payer's own return trip doing in seconds
 * what a retry would do in minutes — and, more importantly, it is where the parent is TOLD what
 * happened rather than left looking at a checkout page they have just left. Nobody should later read
 * this controller as the thing that makes settlement work; deleting it would cost latency and an
 * explanation, not correctness.
 *
 * ── IT SETTLES FROM `verify()`, NEVER FROM THE CALLBACK PARAMETERS ─────────────────────────────
 *
 * Paystack returns the payer to this URL with `?reference=` and `?trxref=` on the query string. Those
 * are numbers in a URL the payer's own browser sends — editable, replayable, and worth nothing as
 * evidence. The reference names WHICH transaction to ask about; every fact about the money comes from
 * `PaystackClient::verify()`, exactly as the webhook does since #370.
 *
 * ── IT COMPOSES, IT DOES NOT RE-IMPLEMENT ──────────────────────────────────────────────────────
 *
 * `GatewayReference::schoolIdFrom()` for the school, then the same `(provider, reference)` lookup the
 * webhook uses, then `settleFromProvider()` for the money. **A second spelling of `settle()` here
 * would be two implementations of the settlement rule** — the shape this codebase has paid for four
 * times, and the one place it would be unarguable is a parent's payment. The compare-and-swap on
 * `payment_id` is what makes this safe to run concurrently with the webhook: whichever arrives first
 * settles, the other is told `already_settled`, and neither writes twice.
 *
 * ── FOUR STATES A PAYER CAN BE IN, AND THEY ARE NOT THE OUTCOME ENUM ───────────────────────────
 *
 * `GatewaySettlementOutcome` has nine cases because it answers "what did this delivery do" for a log
 * and for step 7's report. A parent needs to know one of four things: it worked, we are still
 * confirming it, it did not go through, or we already had it. Several outcomes map onto the same
 * sentence deliberately — `fee_not_reported`, `verify_unavailable` and `could_not_book` are three
 * very different operational states and ONE parent-facing fact: *your money is not lost and you need
 * do nothing*.
 *
 * WHAT MUST NOT HAPPEN is `pending` reading as failure. A parent who is told their payment failed
 * pays again, and the second payment is real money the school then has to return.
 */
final class GatewayReturnController extends Controller
{
    public function __invoke(
        Request $request,
        SettleGatewayTransaction $settle,
        GuardianPaymentAuthorisation $authorisation,
    ): Response {
        $reference = (string) $request->query('reference', $request->query('trxref', ''));

        if (GatewayReference::schoolIdFrom($reference) === null) {
            // Not a reference this system minted. Not an error the payer can act on, and not one
            // that should name the reference back to them.
            return $this->page('unknown');
        }

        // ── THE SCHOOL COMES FROM THE CALLER, NOT FROM THE REFERENCE ──
        //
        // The WEBHOOK derives the school from the reference, and is right to: it has no
        // authenticated user, so the reference is the only thing that can route it. **Copying that
        // here was a defect and this comment is the scar.** This route has a session, and deriving
        // the school from a string in the payer's own query would have let any parent holding a
        // valid reference settle and READ another family's transaction in another school —
        // idempotent, correct, and a disclosure.
        //
        // So the lookup runs in the CALLER's active school, where `SchoolScope` is doing its
        // ordinary job, and a reference minted elsewhere simply is not found.
        $schoolId = (int) ActiveSchool::getOrFail()->id;

        $transaction = GatewayTransaction::query()
            ->where('provider', 'paystack')
            ->where('reference', $reference)
            ->first();

        // AND THE RELATIONSHIP, WHICH IS A DIFFERENT GUARANTEE FROM ISOLATION. Same school is not
        // the same family: `mayPay()` answers whether this invoice's student is this user's ward,
        // and it is the same predicate the initiate path uses to decide they could start it.
        if ($transaction === null
            || $transaction->invoice === null
            || ! $authorisation->mayPay($request->user(), $transaction->invoice)) {
            return $this->page('unknown');
        }

        // ALREADY SETTLED BEFORE WE ASKED — the webhook won the race, which is the ordinary case
        // when a parent's browser is slower than a server-to-server delivery. Answered without
        // calling the provider: there is nothing left to decide, and asking again would be a
        // network round trip to reach the same conclusion.
        if ($transaction->payment_id !== null) {
            return $this->page('recorded', $transaction);
        }

        $outcome = ActiveSchool::runFor($schoolId, fn () => $settle->settleFromProvider($transaction));

        if ($outcome === GatewaySettlementOutcome::CouldNotBook) {
            // THE SERIOUS ONE. The payer has been charged and the system holds no payment — an
            // invoice went void, or a school has no settlement account. Logged at ERROR because
            // nothing else will surface it until step 7's report runs.
            Log::error('paystack.return.could_not_book', ['transaction' => $transaction->getKey()]);
        }

        return $this->page($this->state($outcome), $transaction->fresh());
    }

    /**
     * NINE OUTCOMES ONTO FOUR SENTENCES, and the collapsing is the decision.
     *
     * `pending` is the one that matters: every outcome that leaves the row unsettled but the payer
     * possibly charged maps to it, because the alternative — telling a parent it failed — makes them
     * pay twice. Only an outcome where the PROVIDER says the money did not arrive is a failure.
     */
    private function state(GatewaySettlementOutcome $outcome): string
    {
        return match ($outcome) {
            GatewaySettlementOutcome::Settled => 'settled',
            GatewaySettlementOutcome::AlreadySettled => 'recorded',
            // The provider itself says this transaction is not a success. The only case where
            // telling the payer it did not go through is TRUE.
            GatewaySettlementOutcome::NotSuccessfulAtProvider => 'failed',
            // Everything else: charged or possibly charged, not yet recorded, recoverable without
            // the payer doing anything. `verify_unavailable`, `fee_not_reported`, `could_not_book`,
            // `amount_mismatch`, `fee_is_negative`, `fee_exceeds_amount`, `unknown`.
            default => 'pending',
        };
    }

    private function page(string $state, ?GatewayTransaction $transaction = null): Response
    {
        return Inertia::render('parent/payment-return', [
            'state' => $state,
            // THE AMOUNT THE BILL WAS CREDITED, not the gross. A parent who paid ₦101,600 to settle
            // ₦100,000 should see the bill's figure here — the gross was already explained on the
            // confirmation, and repeating it as "received" would misstate what the school got.
            'amount' => $transaction?->bill,
        ]);
    }
}

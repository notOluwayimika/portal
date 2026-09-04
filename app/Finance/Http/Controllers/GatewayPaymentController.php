<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\InitiateGatewayPayment;
use App\Finance\Actions\PreviewGatewayFee;
use App\Finance\DTOs\PaystackCheckout;
use App\Finance\Exceptions\PaystackUnavailable;
use App\Finance\Http\Requests\InitiateGatewayPaymentRequest;
use App\Finance\Http\Requests\PreviewGatewayFeeRequest;
use App\Http\Controllers\Controller;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Starts a gateway payment and hands back the provider's checkout URL.
 *
 * Thin on purpose: every refusal is the action's, so there is exactly one place where the rules
 * about paying a bill live, and this class decides only what an HTTP caller sees.
 */
final class GatewayPaymentController extends Controller
{
    public function store(InitiateGatewayPaymentRequest $request, InitiateGatewayPayment $initiate): JsonResponse
    {
        $invoice = $request->resolveInvoice();

        try {
            $transaction = $initiate->handle(
                $invoice,
                Money::fromKobo((int) $request->integer('amount_minor'), $invoice->total->currency),
                $request->user(),
                $request->string('callback_url')->toString() ?: null,
            );
        } catch (BusinessRuleException $e) {
            // 422 and the message: every one of these is something the payer can act on — the bill
            // is not released, the amount is below the minimum, the bill is cancelled. The one
            // exception is an unconfigured minimum, which is a school misconfiguration wearing the
            // same shape; it is logged so it does not read to an operator as a parent's mistake.
            Log::warning('paystack.initiate.refused', ['reason' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PaystackUnavailable $e) {
            // AMBIGUOUS, and it must not read as a refusal. The provider may or may not have created
            // the transaction; our row exists either way and the webhook will settle it if a payment
            // happens. 503 tells the client to retry rather than telling the parent they cannot pay.
            Log::error('paystack.initiate.unavailable', ['reason' => $e->getMessage()]);

            return response()->json(['message' => 'The payment provider is not responding. Please try again shortly.'], 503);
        }

        /** @var PaystackCheckout $checkout */
        $checkout = $transaction->getAttribute('checkout');

        return response()->json([
            'reference' => $transaction->reference,
            'authorization_url' => $checkout->authorizationUrl,
            // THREE NUMBERS, BECAUSE THE CONFIRMATION SCREEN MUST SHOW THREE. Under parent-bears the
            // payer is charged MORE than they typed, and the screen has to say so before they
            // commit: what is credited to the bill, what the provider takes, and what their card is
            // charged. A surprise on a card statement is a chargeback.
            //
            // The fee is DERIVED HERE from the two stored figures rather than stored as a third
            // column: it is the up-front estimate, and the fee that is eventually RECORDED is the
            // provider's reported one at settlement. Two different facts; storing this one beside
            // `fee_minor` would invite them to be read as the same.
            'bill' => $transaction->bill,
            'fee' => $transaction->amount->minus($transaction->bill),
            'amount' => $transaction->amount,
        ], 201);
    }

    /**
     * The fee preview — three figures, no row, no provider call.
     *
     * 200 rather than 201: nothing was created, and that is the point of the endpoint.
     */
    public function preview(PreviewGatewayFeeRequest $request, PreviewGatewayFee $preview): JsonResponse
    {
        $invoice = $request->resolveInvoice();

        try {
            $figures = $preview->handle(
                $invoice,
                Money::fromKobo((int) $request->integer('amount_minor'), $invoice->total->currency),
            );
        } catch (BusinessRuleException $e) {
            // Same refusals as `store`, in the same order, because they are literally the same
            // guards. A parent who cannot pay must not be quoted a figure for doing so.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            // WHAT THE CARD IS CHARGED — exact, because it is the number sent to the provider.
            'gross' => $figures['gross'],
            // WHAT SETTLES THE BILL, and what is left over. Computed server-side: the split is
            // arithmetic on money, and `outstanding` on the client may predate another guardian's
            // payment.
            'applied' => $figures['applied'],
            'excess' => $figures['excess'],
        ], 200);
    }
}

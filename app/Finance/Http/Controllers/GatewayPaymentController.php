<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\InitiateGatewayPayment;
use App\Finance\DTOs\PaystackCheckout;
use App\Finance\Exceptions\PaystackUnavailable;
use App\Finance\Http\Requests\InitiateGatewayPaymentRequest;
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
            // Both figures, because the payer is being charged more than the bill and the screen has
            // to be able to say so. Sending only the gross would make the fee invisible at the one
            // moment the parent is entitled to see it.
            'bill' => $transaction->bill,
            'amount' => $transaction->amount,
        ], 201);
    }
}

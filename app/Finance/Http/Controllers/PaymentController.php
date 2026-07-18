<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\RecordPayment;
use App\Finance\Http\Requests\RecordPaymentRequest;
use App\Finance\Http\Resources\PaymentResource;
use App\Finance\Models\Invoice;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class PaymentController extends Controller
{
    public function store(RecordPaymentRequest $request, Invoice $invoice, RecordPayment $action): JsonResponse
    {
        $amount = Money::fromKobo(
            $request->integer('amount_minor'),
            (string) $request->input('currency', Money::DEFAULT_CURRENCY),
        );

        try {
            $payment = $action->handle($invoice, $amount, (string) $request->input('payer_name'), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new PaymentResource($payment), 201);
    }
}

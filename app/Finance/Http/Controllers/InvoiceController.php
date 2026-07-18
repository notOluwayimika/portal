<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\CancelInvoice;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Http\Requests\CancelInvoiceRequest;
use App\Finance\Http\Requests\GenerateInvoiceRequest;
use App\Finance\Http\Resources\InvoiceResource;
use App\Finance\Models\Invoice;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The manual entry points for the walking skeleton: "generate invoice for
 * enrollment X" and "cancel invoice X". No automated billing trigger (that needs
 * the enrollment-create fan-out convergence + EnrollmentCreated event, 1.4e).
 *
 * Controllers validate → authorize → delegate → respond; the transaction lives in
 * the Action, and the DB facade is never touched here (arch rule).
 */
class InvoiceController extends Controller
{
    public function generate(GenerateInvoiceRequest $request, GenerateInvoice $action): JsonResponse
    {
        $amount = Money::fromKobo(
            $request->integer('amount_minor'),
            (string) $request->input('currency', Money::DEFAULT_CURRENCY),
        );

        try {
            $invoice = $action->handle(
                (string) $request->input('enrollment_id'),
                $amount,
                (string) $request->input('description'),
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new InvoiceResource($invoice), 201);
    }

    public function cancel(CancelInvoiceRequest $request, Invoice $invoice, CancelInvoice $action): JsonResponse
    {
        try {
            $invoice = $action->handle($invoice, (string) $request->input('reason'), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new InvoiceResource($invoice));
    }
}

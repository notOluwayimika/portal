<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\RecordAccountPayment;
use App\Finance\Actions\RecordPayment;
use App\Finance\Http\Requests\RecordAccountPaymentRequest;
use App\Finance\Http\Requests\RecordPaymentRequest;
use App\Finance\Http\Resources\PaymentResource;
use App\Finance\Models\BankAccount;
use App\Finance\Models\Invoice;
use App\Models\Student;
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
            $payment = $action->handle(
                $invoice,
                $amount,
                (string) $request->input('payer_name'),
                $request->user(),
                (string) $request->input('received_at'),
                // uuid -> id. BankAccount is School-scoped, so a foreign uuid resolves to nothing
                // here rather than being trusted and refused later by the composite foreign key.
                (int) BankAccount::query()->where('uuid', $request->input('bank_account_id'))->valueOrFail('id'),
                $request->input('received_at_reason'),
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new PaymentResource($payment), 201);
    }

    /**
     * Record a payment ON THE ACCOUNT — no invoice (the general "money at the window"). Shaped like
     * InvoiceController::generateForStudent: bind the School-scoped {student:uuid} (a foreign student
     * 404s at the binding), build Money at the edge, delegate to RecordAccountPayment, catch the
     * business rule → 422. PaymentResource with `allocations` loaded (always empty here) so the wire
     * shape is identical to the invoice-scoped door.
     */
    public function storeForStudent(RecordAccountPaymentRequest $request, Student $student, RecordAccountPayment $action): JsonResponse
    {
        $amount = Money::fromKobo(
            $request->integer('amount_minor'),
            (string) $request->input('currency', Money::DEFAULT_CURRENCY),
        );

        try {
            $payment = $action->handle(
                $student->id,
                $amount,
                (string) $request->input('payer_name'),
                $request->user(),
                (string) $request->input('received_at'),
                // uuid -> id. BankAccount is School-scoped, so a foreign uuid resolves to nothing
                // here rather than being trusted and refused later by the composite foreign key.
                (int) BankAccount::query()->where('uuid', $request->input('bank_account_id'))->valueOrFail('id'),
                $request->input('received_at_reason'),
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new PaymentResource($payment), 201);
    }
}

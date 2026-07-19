<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\CancelInvoice;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Http\Requests\CancelInvoiceRequest;
use App\Finance\Http\Requests\GenerateInvoiceRequest;
use App\Finance\Http\Resources\InvoiceResource;
use App\Finance\Models\Invoice;
use App\Finance\Services\InvoiceReadModel;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The manual entry points: "generate a multi-line invoice for enrollment X",
 * "void invoice X", and the student invoice read.
 *
 * Controllers validate → authorize → delegate → respond; the transaction lives in
 * the Action, and the DB facade is never touched here (arch rule).
 */
class InvoiceController extends Controller
{
    public function generate(GenerateInvoiceRequest $request, GenerateInvoice $action): JsonResponse
    {
        try {
            $invoice = $action->handle(
                (string) $request->input('enrollment_id'),
                $request->lineSpecs(),
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

    /**
     * Invoices for a student. Voided invoices are excluded by DEFAULT — they were
     * never really billed. `?include_void=1` is the explicit audit view, which is
     * the only way to see them.
     */
    public function forStudent(Request $request, Student $student, InvoiceReadModel $invoices): JsonResponse
    {
        $includeVoid = $request->boolean('include_void');

        return response()->json([
            'billed_total' => $invoices->billedTotalForStudent($student->id, $includeVoid),
            'invoices' => InvoiceResource::collection(
                $invoices->forStudent($student->id, $includeVoid)
            ),
        ]);
    }
}

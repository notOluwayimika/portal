<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\RejectVoidRequest;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\Http\Requests\RejectVoidRequestRequest;
use App\Finance\Http\Requests\SubmitVoidRequestRequest;
use App\Finance\Http\Resources\VoidRequestResource;
use App\Finance\Models\Invoice;
use App\Finance\Models\VoidRequest;
use App\Finance\Services\InvoiceReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Invoice-void maker-checker lifecycle (Ph3b) — the SECOND instance of the credit-note template
 * ({@see CreditNoteController}). Validate → authorize → delegate → respond; the transaction, the
 * invoice flip and the reversing ledger entry live in the Actions, and the DB facade is never
 * touched here (arch rule).
 *
 * Permissions are gated by route middleware (submit / approve / reject). The record-level
 * maker ≠ checker rule is the VoidRequestPolicy, invoked via Gate::authorize for the decision
 * actions — a maker cannot approve/reject their own submission (403), with the DB CHECK beneath.
 */
class VoidRequestController extends Controller
{
    /** Maker: propose voiding an invoice (status `submitted`; invoice untouched, no money moves). */
    public function submit(SubmitVoidRequestRequest $request, Invoice $invoice, SubmitVoidRequest $action): JsonResponse
    {
        try {
            $voidRequest = $action->handle($invoice, (string) $request->input('reason'), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $voidRequest->load(['invoice', 'submittedBy']);

        return response()->json(new VoidRequestResource($voidRequest), 201);
    }

    /** Checker: approve — voids the invoice + posts the reversal. 403 if the checker is the maker. */
    public function approve(Request $request, VoidRequest $voidRequest, ApproveVoidRequest $action): JsonResponse
    {
        Gate::authorize('approve', $voidRequest);

        try {
            $approved = $action->handle($voidRequest, $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $approved->load(['invoice', 'submittedBy']);

        return response()->json(new VoidRequestResource($approved), 200);
    }

    /** Checker: reject with a reason — invoice stands, no money moves. 403 if maker = checker. */
    public function reject(RejectVoidRequestRequest $request, VoidRequest $voidRequest, RejectVoidRequest $action): JsonResponse
    {
        Gate::authorize('reject', $voidRequest);

        try {
            $rejected = $action->handle($voidRequest, $request->user(), (string) $request->input('reason'));
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $rejected->load(['invoice', 'submittedBy']);

        return response()->json(new VoidRequestResource($rejected), 200);
    }

    /** The checker's pending void queue — School-scoped, newest first, with can_approve. */
    public function pending(InvoiceReadModel $read): JsonResponse
    {
        return response()->json([
            'data' => VoidRequestResource::collection($read->pendingVoidRequests()),
        ]);
    }
}

<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Http\Resources\OpeningBalanceBatchResource;
use App\Finance\Models\OpeningBalanceBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The opening-balance cutover's READ surface (§9 step 5a) — one action, `pending`, and deliberately
 * nothing else.
 *
 * §9 step 4c shipped the approval gate as DOMAIN ONLY: SubmitOpeningBalanceBatch,
 * ApproveOpeningBalanceBatch and RejectOpeningBalanceBatch exist and are exercised by tests and the
 * console, with no HTTP path at all. This controller does NOT open one. Submitting and deciding a
 * batch are the operator screen's (§9 step 5b / spec §2's U12b); all that is added here is the feed
 * the unified approvals queue needs so that a holder of `finance.opening-balance.approve` can SEE
 * that a batch is waiting — which, before 5a, no screen anywhere told them.
 *
 * Shape follows CreditNoteController::pending / VoidRequestController::pending exactly, envelope
 * included: `{"data": [...]}`. Every feed on the queue answers in one shape, because the page maps
 * one declared list over all of them.
 */
class OpeningBalanceBatchController extends Controller
{
    /**
     * Every batch awaiting a second signature in the active School — School isolation is automatic
     * via BelongsToSchool on the model, exactly as the four siblings rely on it.
     *
     * `submitted` is the ONLY pending state: 4c made it the one state a batch may be approved from
     * (OpeningBalanceBatchStatus::Submitted), and `validated` explicitly is not — a validated batch
     * has not been offered for a decision yet, so it is not the checker's business.
     */
    public function pending(): JsonResponse
    {
        $batches = OpeningBalanceBatch::query()
            ->where('status', OpeningBalanceBatchStatus::Submitted->value)
            ->with('submittedBy')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => OpeningBalanceBatchResource::collection($batches),
        ]);
    }
}

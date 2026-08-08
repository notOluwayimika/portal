<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveDiscountPolicyChange;
use App\Finance\Actions\RejectDiscountPolicyChange;
use App\Finance\Actions\SubmitDiscountPolicyChange;
use App\Finance\Enums\DiscountPolicyChangeKind;
use App\Finance\Enums\DiscountPolicyChangeStatus;
use App\Finance\Http\Requests\RejectDiscountPolicyChangeRequest;
use App\Finance\Http\Requests\SubmitDiscountPolicyChangeRequest;
use App\Finance\Http\Resources\DiscountPolicyChangeResource;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\DiscountPolicyChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * The discount-policy governance surface (S1 commit 3) — the finance_void_requests controller shape.
 * Validate → authorize → delegate → respond; the transaction lives in the Action, the record-level
 * maker ≠ checker rule is the DiscountPolicyChangePolicy (Gate::authorize on approve/reject).
 */
class DiscountPolicyChangeController extends Controller
{
    public function submit(SubmitDiscountPolicyChangeRequest $request, SubmitDiscountPolicyChange $action): JsonResponse
    {
        $kind = DiscountPolicyChangeKind::from((string) $request->input('kind'));

        $target = null;
        if ($kind !== DiscountPolicyChangeKind::Create) {
            // Scoped find: a foreign/unknown target uuid resolves to null → 404.
            $target = DiscountPolicy::query()->where('uuid', $request->input('target'))->first();
            if ($target === null) {
                return response()->json(['message' => 'The target discount policy was not found.'], 404);
            }
        }

        try {
            $change = $action->handle($kind, $target, $request->terms(), (string) $request->input('reason'), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new DiscountPolicyChangeResource($change), 201);
    }

    /**
     * The checker's pending queue for this type — the `data` envelope and the eager-loaded maker are
     * §9 step 5a's, for the reasons written out on FeeScheduleChangeController::pending().
     */
    public function pending(): JsonResponse
    {
        $changes = DiscountPolicyChange::query()
            ->where('status', DiscountPolicyChangeStatus::Submitted->value)
            ->with('submitter')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => DiscountPolicyChangeResource::collection($changes),
        ]);
    }

    public function approve(DiscountPolicyChange $change, ApproveDiscountPolicyChange $action): JsonResponse
    {
        Gate::authorize('approve', $change);

        try {
            $approved = $action->handle($change, request()->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new DiscountPolicyChangeResource($approved));
    }

    public function reject(RejectDiscountPolicyChangeRequest $request, DiscountPolicyChange $change, RejectDiscountPolicyChange $action): JsonResponse
    {
        Gate::authorize('reject', $change);

        try {
            $rejected = $action->handle($change, (string) $request->input('reason'), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new DiscountPolicyChangeResource($rejected));
    }
}

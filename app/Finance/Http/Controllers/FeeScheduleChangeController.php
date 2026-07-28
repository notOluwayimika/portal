<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveFeeScheduleChange;
use App\Finance\Actions\RejectFeeScheduleChange;
use App\Finance\Actions\SubmitFeeScheduleChange;
use App\Finance\Enums\FeeScheduleChangeKind;
use App\Finance\Enums\FeeScheduleChangeStatus;
use App\Finance\Http\Requests\RejectFeeScheduleChangeRequest;
use App\Finance\Http\Requests\SubmitFeeScheduleChangeRequest;
use App\Finance\Http\Resources\FeeScheduleChangeResource;
use App\Finance\Models\FeeSchedule;
use App\Finance\Models\FeeScheduleChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * The fee-schedule governance surface (S1 commit 4) — the discount-policy-change controller shape.
 * Validate → authorize → delegate → respond; the transaction lives in the Action, the record-level
 * maker ≠ checker rule is the FeeScheduleChangePolicy (Gate::authorize on approve/reject).
 */
class FeeScheduleChangeController extends Controller
{
    public function submit(SubmitFeeScheduleChangeRequest $request, SubmitFeeScheduleChange $action): JsonResponse
    {
        $kind = FeeScheduleChangeKind::from((string) $request->input('kind'));

        // Scoped find: a foreign/unknown target uuid resolves to null → 404.
        $target = FeeSchedule::query()->where('uuid', $request->input('target'))->first();
        if ($target === null) {
            return response()->json(['message' => 'The target fee schedule was not found.'], 404);
        }

        try {
            $change = $action->handle($kind, $target, (string) $request->input('reason'), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new FeeScheduleChangeResource($change), 201);
    }

    public function pending(): JsonResponse
    {
        $changes = FeeScheduleChange::query()
            ->where('status', FeeScheduleChangeStatus::Submitted->value)
            ->orderBy('id')
            ->get();

        return response()->json(FeeScheduleChangeResource::collection($changes));
    }

    public function approve(FeeScheduleChange $change, ApproveFeeScheduleChange $action): JsonResponse
    {
        Gate::authorize('approve', $change);

        try {
            $approved = $action->handle($change, request()->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new FeeScheduleChangeResource($approved));
    }

    public function reject(RejectFeeScheduleChangeRequest $request, FeeScheduleChange $change, RejectFeeScheduleChange $action): JsonResponse
    {
        Gate::authorize('reject', $change);

        try {
            $rejected = $action->handle($change, (string) $request->input('reason'), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new FeeScheduleChangeResource($rejected));
    }
}

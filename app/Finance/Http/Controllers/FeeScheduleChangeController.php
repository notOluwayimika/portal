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

    /**
     * The checker's pending queue for this type. §9 step 5a changed TWO things here and both were
     * required to put the feed on the unified approvals screen at all:
     *
     * 1. The ENVELOPE is now `{"data": [...]}`. It was a bare array — `response()->json()` on a
     *    resource collection serialises through jsonSerialize(), which does NOT apply the `data`
     *    wrap the two working feeds got by returning through toResponse(). So the page's one
     *    fetch-and-map could not have read this feed even once it fetched it.
     * 2. The maker is EAGER-LOADED. `submitted_by_name` is a whenLoaded() field, so without this
     *    the queue's submitter column would silently be absent on every row of this type — the
     *    quiet half of the same defect.
     *
     * The TARGET and its term and class level are eager-loaded to avoid an N+1, and ONLY that.
     * Stated precisely because the obvious stronger claim is false and I shipped it for an hour:
     * the `target_*` fields do NOT disappear without this `with()`. The line above them
     * (`'target_schedule_id' => $this->target?->uuid`) lazy-loads `target` during toArray(), so the
     * later whenLoaded('target', …) calls are always satisfied and the closures then lazy-load
     * `term` and `classLevel` in turn. Removing this `with()` costs three extra queries per row and
     * changes no output — which is exactly why the watched red for the subject arm mutates the
     * RESOURCE, not this line. See the report's note on assertions that pass for the wrong reason.
     *
     * Ordering is unchanged (`id` ascending); the page sorts the merged set by created_at itself.
     */
    public function pending(): JsonResponse
    {
        $changes = FeeScheduleChange::query()
            ->where('status', FeeScheduleChangeStatus::Submitted->value)
            ->with(['submitter', 'target.term', 'target.classLevel'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => FeeScheduleChangeResource::collection($changes),
        ]);
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

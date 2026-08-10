<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Actions\EditFeeScheduleDraft;
use App\Finance\Http\Requests\FeeScheduleRequest;
use App\Finance\Http\Resources\FeeScheduleResource;
use App\Finance\Models\FeeItem;
use App\Finance\Models\FeeSchedule;
use App\Finance\Services\FeeScheduleLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The fee-schedule catalog surface (S1 commit 2, narrowed in commit 4). `index` reads the School's
 * schedules; `store`/`update` author DRAFTS ONLY (commit 4 removed the direct-publish flip — publishing is
 * now an approved fee-schedule change, {@see FeeScheduleChangeController}); `prefill` resolves the active
 * schedule's items into prefilled invoice-line specs for the bursar's generate form — the bursar still
 * confirms and posts them, so GenerateInvoice's contract is unchanged.
 */
class FeeScheduleController extends Controller
{
    public function index(): JsonResponse
    {
        $schedules = FeeSchedule::query()
            ->with(['items' => fn ($q) => $q->orderBy('sort_order')])
            ->orderByDesc('id')
            ->get();

        return response()->json(FeeScheduleResource::collection($schedules));
    }

    public function store(FeeScheduleRequest $request, CreateFeeSchedule $action): JsonResponse
    {
        try {
            $schedule = $action->handle(
                (int) $request->input('term_id'),
                (int) $request->input('class_level_id'),
                (string) $request->input('label'),
                $request->itemSpecs(),
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new FeeScheduleResource($schedule), 201);
    }

    /**
     * Edit a DRAFT in place — its label, and its items replaced wholesale. Reuses FeeScheduleRequest
     * unchanged, so the bank-account-per-item rule bites on an edit exactly as it does on a create; the
     * request's `term_id`/`class_level_id` are validated but IGNORED here, because a draft's slot is fixed
     * by the row and re-slotting it silently would be the same defect {@see self::supersede} avoids.
     *
     * THAT REUSE IS A DECISION U1 HAS TO SETTLE, NOT A FINISHED CONTRACT. Both fields are `required`, so
     * the page must send two values the server discards, and a page that omits them gets a 422 naming
     * fields its operator cannot see. Their `exists` rules are also UNSCOPED — harmless here because the
     * values are thrown away, not harmless on `store`/`supersede`, which read them. Do not "fix" this by
     * making the Action consume them: see docs/handoff/tickets/edit-draft-request-reuse-decide-at-u1.md
     * for the three options and why this commit deliberately left it open.
     *
     * This is the route the pending_unique 422 points at. Before it existed a draft occupied its own slot
     * and could be neither edited nor deleted — see {@see EditFeeScheduleDraft} for why that was a brick.
     */
    public function editDraft(FeeScheduleRequest $request, FeeSchedule $feeSchedule, EditFeeScheduleDraft $action): JsonResponse
    {
        try {
            $schedule = $action->handle(
                $feeSchedule,
                (string) $request->input('label'),
                $request->itemSpecs(),
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new FeeScheduleResource($schedule), 200);
    }

    /**
     * Re-price a slot: author a fresh DRAFT for the bound schedule's (term, class level). The bound
     * schedule identifies the slot; term and class level come from it, not the body, so a re-price cannot
     * silently move a schedule's slot. Publishing the draft (superseding the current active) is the ED's
     * approval, not this route — commit 4 moved activation out of here entirely.
     *
     * RENAMED from `update` (was S1 commit 2). It never updated anything: it discards the bound model and
     * calls CreateFeeSchedule, leaving the old row untouched beside a new draft. The name cost two people
     * an hour to see through. The URI is unchanged — both route fixtures key on `METHOD /uri`, so the
     * rename is free there and the only regenerated artefact is the wayfinder action.
     */
    public function supersede(FeeScheduleRequest $request, FeeSchedule $feeSchedule, CreateFeeSchedule $action): JsonResponse
    {
        try {
            $schedule = $action->handle(
                (int) $feeSchedule->term_id,
                (int) $feeSchedule->class_level_id,
                (string) $request->input('label'),
                $request->itemSpecs(),
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new FeeScheduleResource($schedule), 200);
    }

    /**
     * The billing read path (proof 26 bites the lookup's single status filter). Returns the ACTIVE
     * schedule's items as prefilled charge lines — never a draft's. Empty `lines` when nothing is active.
     */
    public function prefill(Request $request, FeeScheduleLookup $lookup): JsonResponse
    {
        $request->validate([
            'term_id' => ['required', 'integer'],
            'class_level_id' => ['required', 'integer'],
        ]);

        $schedule = $lookup->activeFor((int) $request->integer('term_id'), (int) $request->integer('class_level_id'));

        if ($schedule === null) {
            return response()->json(['schedule' => null, 'lines' => []]);
        }

        return response()->json([
            'schedule' => new FeeScheduleResource($schedule->loadMissing('items')),
            'lines' => $schedule->items->map(fn (FeeItem $item) => [
                'description' => $item->description,
                'amount_minor' => $item->amount->toKobo(),
                'currency' => $item->amount->currency,
                'kind' => 'charge',
                'fee_item_id' => $item->id,
                'is_mandatory' => $item->is_mandatory,
                'is_discountable' => $item->is_discountable,
            ])->values(),
        ]);
    }
}

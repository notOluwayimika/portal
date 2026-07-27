<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Http\Requests\FeeScheduleRequest;
use App\Finance\Http\Resources\FeeScheduleResource;
use App\Finance\Models\FeeItem;
use App\Finance\Models\FeeSchedule;
use App\Finance\Services\FeeScheduleLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The fee-schedule catalog surface (S1 commit 2). `index` reads the School's schedules; `store`/`update`
 * publish (direct-publish path, removed in commit 4 in favour of an approved change); `prefill` resolves
 * the active schedule's items into prefilled invoice-line specs for the bursar's generate form — the
 * bursar still confirms and posts them, so GenerateInvoice's contract is unchanged.
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
     * Re-price a slot: publish a fresh set of items for the bound schedule's (term, class level),
     * superseding whatever is currently active there. The bound schedule identifies the slot; term and
     * class level come from it, not the body, so a re-price cannot silently move a schedule's slot.
     */
    public function update(FeeScheduleRequest $request, FeeSchedule $feeSchedule, CreateFeeSchedule $action): JsonResponse
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

<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Actions\EditFeeScheduleDraft;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Http\Requests\EditFeeScheduleDraftRequest;
use App\Finance\Http\Requests\FeeScheduleRequest;
use App\Finance\Http\Resources\FeeScheduleResource;
use App\Finance\Models\FeeItem;
use App\Finance\Models\FeeSchedule;
use App\Finance\Services\FeeScheduleLookup;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * The fee-schedule catalog surface (S1 commit 2, narrowed in commit 4). `index` reads the School's
 * schedules; `store`/`update` author DRAFTS ONLY (commit 4 removed the direct-publish flip — publishing is
 * now an approved fee-schedule change, {@see FeeScheduleChangeController}); `prefill` resolves the active
 * schedule's items into prefilled invoice-line specs for the bursar's generate form — the bursar still
 * confirms and posts them, so GenerateInvoice's contract is unchanged.
 */
class FeeScheduleController extends Controller
{
    /**
     * The School's schedules, newest first, with TWO OPTIONAL FILTERS and nothing else.
     *
     * `term_id` carries the same School-scoped `exists` rule as {@see FeeScheduleRequest} — written as an
     * explicit `where` rather than through the scoped model, because Rule::exists queries the TABLE and no
     * global scope applies to it.
     *
     * WHAT THE SCOPING CLOSES IS A TERM-ID EXISTENCE ORACLE, PLATFORM-WIDE — and nothing about the rows.
     * `FeeSchedule` uses BelongsToSchool, so this query is bounded to the active School before
     * `where('term_id', …)` is applied: the response is `200 []` whether another School's term has zero
     * schedules or fifty, and no count, no id and no fact about that School is conveyed either way.
     *
     * What an UNSCOPED `Rule::exists('terms','id')` would convey is the difference between **422** (this
     * term id exists nowhere on the platform) and **200 []** (it exists in SOME school). Feed it ids and
     * it enumerates which term ids are real across every school on the installation. Scoped, both cases
     * are the same 422, and the question is never answered rather than answered emptily.
     *
     * ABSENT MEANS UNFILTERED. Both are `nullable`, so an empty `?term_id=` is "all" rather than a 422 on
     * a screen that has not chosen a term yet, and nothing that calls this endpoint today changes.
     *
     * PAGINATION IS NOT HERE. A caller passing no term still gets every schedule the School has ever
     * written, with its items — see docs/handoff/tickets/fee-schedule-index-unpaginated.md.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'term_id' => ['nullable', 'integer', Rule::exists('terms', 'id')->where('school_id', ActiveSchool::id())],
            'status' => ['nullable', Rule::enum(FeeScheduleStatus::class)],
        ]);

        $schedules = FeeSchedule::query()
            // `items.bankAccount` is not decoration: the resource serialises each item's destination
            // account uuid, and without this it is one query per item across every schedule on the page.
            // `term.academicSession` and `classLevel` are what make term_label/class_level_label appear —
            // whenLoaded returns nothing without them, so dropping either eager-load silently empties the
            // labels rather than erroring.
            ->with([
                'items' => fn ($q) => $q->orderBy('sort_order'),
                'items.bankAccount',
                'term.academicSession',
                'classLevel',
            ])
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->string('status')))
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

        // THE SAME SHAPE index() RETURNS, labels included. A page renders a row from the list and then
        // re-renders it from this response; if the write routes returned no term_label and no
        // class_level_label those two columns would go blank on save. Loaded HERE and not in
        // CreateFeeSchedule — the Action's return value is its contract with every caller including its
        // tests, and what a payload renders is the controller's business.
        return response()->json(new FeeScheduleResource($schedule->loadMissing('items.bankAccount', 'term.academicSession', 'classLevel')), 201);
    }

    /**
     * Edit a DRAFT in place — its label, and its items replaced wholesale.
     *
     * VALIDATED BY ITS OWN REQUEST as of U1 commit 1. It used to reuse {@see FeeScheduleRequest}, which
     * requires `term_id` and `class_level_id` — two fields {@see EditFeeScheduleDraft::handle()} never
     * receives, so the endpoint demanded them, refused the request without them, and then discarded
     * them. {@see EditFeeScheduleDraftRequest} carries `label` and the `items.*` rules only, and the
     * item rules are shared with FeeScheduleRequest rather than copied, so the bank-account-per-item
     * rule still bites on an edit exactly as it does on a create. A draft's slot stays fixed by the row:
     * re-slotting it from the body would be the same defect {@see self::supersede} avoids.
     *
     * This is the route the pending_unique 422 points at. Before it existed a draft occupied its own slot
     * and could be neither edited nor deleted — see {@see EditFeeScheduleDraft} for why that was a brick.
     */
    public function editDraft(EditFeeScheduleDraftRequest $request, FeeSchedule $feeSchedule, EditFeeScheduleDraft $action): JsonResponse
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

        // Same shape as index(), labels included — see store(). This is the response a page is most
        // likely to re-render a row from.
        return response()->json(new FeeScheduleResource($schedule->loadMissing('items.bankAccount', 'term.academicSession', 'classLevel')), 200);
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

        // Same shape as index(), labels included — see store().
        return response()->json(new FeeScheduleResource($schedule->loadMissing('items.bankAccount', 'term.academicSession', 'classLevel')), 200);
    }

    /**
     * The billing read path (proof 26 bites the lookup's single status filter). Returns the ACTIVE
     * schedule's items as prefilled charge lines — never a draft's. Empty `lines` when nothing is active.
     *
     * `items.bankAccount` is loaded here for the same reason `index()` loads it — the resource serialises
     * each item's destination uuid, and this is the BILLING read path, where one query per item is the
     * cost of not saying so. Nothing else about this payload moves: `term` stays unloaded, so the two
     * labels remain ABSENT from `schedule` rather than present-and-null.
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
            'schedule' => new FeeScheduleResource($schedule->loadMissing('items.bankAccount')),
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

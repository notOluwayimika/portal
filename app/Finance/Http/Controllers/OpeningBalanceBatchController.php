<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveOpeningBalanceBatch;
use App\Finance\Actions\PostOpeningBalanceBatch;
use App\Finance\Actions\RejectOpeningBalanceBatch;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Exports\OpeningBalanceImportTemplateExport;
use App\Finance\Http\Requests\RejectOpeningBalanceBatchRequest;
use App\Finance\Http\Resources\OpeningBalanceBatchResource;
use App\Finance\Models\OpeningBalanceBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The opening-balance cutover's read surface (§9 step 5a's `pending`, §9 step 5b-i's `template`) and,
 * as of §9 step 5b-ii, ITS DECISION SURFACE: `approve` and `reject`.
 *
 * WHAT CHANGED, because 5a's docblock here said the opposite and a reader who knows it will look for
 * it: 4c shipped Submit/Approve/RejectOpeningBalanceBatch as DOMAIN ONLY — real Actions with real
 * guards, exercised by tests and reachable over no HTTP path at all — and 5a deliberately did not
 * open one. This commit opens the CHECKER's half. Submitting is still not HTTP: the maker's screen
 * (spec §2's U12b) is the next commit, so until it lands nothing can move a batch into `submitted`
 * and `pending` below returns an empty feed. That is invisible, not dishonest — the alternative,
 * shipping the entrance before the exit, is what left 5a's two feeds and 5b-i's template reachable
 * and rendered nowhere.
 *
 * NEITHER DECISION RE-IMPLEMENTS A GUARD. The Actions own the transaction, the locked re-read of
 * `status` and `submitted_by_user_id`, and every posting mechanic; this file validates, authorizes
 * and delegates. Shape is DiscountPolicyChangeController::approve/reject: Gate::authorize on the
 * record-level maker ≠ checker Policy, BusinessRuleException → 422, the Resource back.
 *
 * `pending` follows CreditNoteController::pending / VoidRequestController::pending exactly, envelope
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

    /**
     * §9 step 5b-i (R13) — the import template, issued BY THE PLATFORM.
     *
     * Same shape as GuardianImportController@template: a download of an export that renders the
     * validator's own COLUMNS map, so the format the operator fills in and the format the importer
     * reads back cannot drift apart. This is the download only; the upload screen is still to come.
     *
     * THE GATE IS THE MAKER ABILITY, and it coins nothing: `finance.opening-balance.submit` is the
     * submit half of the triple §9 step 4c already ships (Permission.php:158-160). The person who
     * downloads the template is the person who will upload the file — so the checker's
     * `…approve` (which gates `pending` above) is the wrong ability here, and a template behind
     * `finance.access` alone would hand the format to everyone who can read a statement.
     *
     * It carries no School data — it is the FORMAT, not an extract — so there is nothing here for
     * SchoolScope to isolate; the route's `tenant` middleware still establishes context for the
     * permission check itself.
     */
    public function template(): BinaryFileResponse
    {
        return Excel::download(new OpeningBalanceImportTemplateExport, 'opening-balance-import-template.xlsx');
    }

    /**
     * §9 step 5b-ii — APPROVE, WHICH IS THE POST. There is no separate posting call and there must
     * never be one: {@see ApproveOpeningBalanceBatch} runs {@see PostOpeningBalanceBatch}
     * inside its own transaction, so this single request writes one ledger charge per positive
     * fee-type line and one netted migrated payment per student in credit — and G1b's two triggers
     * then deny every exit from `posted`, UPDATE and DELETE alike. There is no un-post, no delete and
     * no second attempt.
     *
     * That is why the queue puts a confirmation in front of this route and in front of no other type's
     * (resources/js/lib/finance/approval-feeds.ts). The confirmation is a UI courtesy, not a control:
     * the controls are the ability on the route, the Policy below, the Action's locked re-read and the
     * database's CHECK, and every one of them holds against a client that never renders a dialog.
     */
    public function approve(OpeningBalanceBatch $batch, ApproveOpeningBalanceBatch $action): JsonResponse
    {
        Gate::authorize('approve', $batch);

        try {
            $approved = $action->handle($batch, request()->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new OpeningBalanceBatchResource($approved));
    }

    /**
     * §9 step 5b-ii — REJECT. Moves no money: the batch goes to `rejected` with the checker's reason,
     * and its staged rows are retained so a refused cutover stays readable. A corrected extract comes
     * back as a NEW batch under a new reference (§7's idempotency key); there is no way back to
     * `validated` from here.
     */
    public function reject(RejectOpeningBalanceBatchRequest $request, OpeningBalanceBatch $batch, RejectOpeningBalanceBatch $action): JsonResponse
    {
        Gate::authorize('reject', $batch);

        try {
            $rejected = $action->handle($batch, (string) $request->input('reason'), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new OpeningBalanceBatchResource($rejected));
    }
}

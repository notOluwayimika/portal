<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveCreditNote;
use App\Finance\Actions\RejectCreditNote;
use App\Finance\Actions\SubmitCreditNote;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Http\Requests\RejectCreditNoteRequest;
use App\Finance\Http\Requests\SubmitCreditNoteRequest;
use App\Finance\Http\Resources\CreditNoteResource;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Services\InvoiceReadModel;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Credit-note maker-checker lifecycle (Ph3). Validate → authorize → delegate → respond;
 * the transaction, ceiling and ledger live in the Actions, and the DB facade is never
 * touched here (arch rule).
 *
 * Permissions are gated by route middleware (submit / approve / reject). The record-level
 * maker ≠ checker rule is the CreditNotePolicy, invoked via Gate::authorize for the decision
 * actions — a maker cannot approve/reject their own submission (403), and the DB CHECK is
 * the backstop beneath that.
 */
class CreditNoteController extends Controller
{
    /** Maker: propose a credit note (status `submitted`; no money moves). */
    public function submit(SubmitCreditNoteRequest $request, Invoice $invoice, SubmitCreditNote $action): JsonResponse
    {
        $amount = Money::fromKobo(
            $request->integer('amount_minor'),
            (string) $request->input('currency', Money::DEFAULT_CURRENCY),
        );

        $kind = $request->filled('kind')
            ? CreditNoteKind::from((string) $request->input('kind'))
            : CreditNoteKind::CreditNote;

        try {
            $creditNote = $action->handle(
                $invoice,
                $amount,
                $kind,
                $request->input('note') !== null ? (string) $request->input('note') : null,
                $request->user(),
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new CreditNoteResource($creditNote), 201);
    }

    /** Checker: approve a pending note — money moves here. 403 if the checker is the maker. */
    public function approve(Request $request, CreditNote $creditNote, ApproveCreditNote $action): JsonResponse
    {
        Gate::authorize('approve', $creditNote);

        try {
            $approved = $action->handle($creditNote, $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new CreditNoteResource($approved), 200);
    }

    /** Checker: reject a pending note with a reason — never any money. 403 if maker = checker. */
    public function reject(RejectCreditNoteRequest $request, CreditNote $creditNote, RejectCreditNote $action): JsonResponse
    {
        Gate::authorize('reject', $creditNote);

        try {
            $rejected = $action->handle($creditNote, $request->user(), (string) $request->input('reason'));
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new CreditNoteResource($rejected), 200);
    }

    /** The checker's pending-approvals queue — School-scoped, newest first, with can_approve. */
    public function pending(InvoiceReadModel $read): JsonResponse
    {
        return response()->json([
            'data' => CreditNoteResource::collection($read->pendingCreditNotes()),
        ]);
    }

    /**
     * U13 — THE DECIDED NOTES. What `pending` shows before the decision, this shows after it:
     * approved and rejected notes, School-scoped, newest decision first, carrying BOTH the maker and
     * the checker.
     *
     * IT IS A READ AND IT IS GATED AS ONE. `pending` carries `finance.credit-note.approve` because
     * it precedes an act — it is the checker's worklist, and every row on it is a thing they are
     * about to do. Nothing here precedes anything: these rows are status-terminal, the money has
     * already moved or has already been refused, and no route can move them again. So it takes the
     * API group's `finance.access` and nothing more (routes/endpoints/finance.php).
     *
     * That is not a widening of what `finance.access` can see. THE SAME ROWS, IN EVERY STATUS, ARE
     * ALREADY SERVED UNDER IT — InvoiceController::forStudent's statement returns
     * creditNotesForStudent(), which filters on student and not on status, under a route carrying no
     * middleware beyond the group. The gap this closes is not secrecy, it is FINDABILITY: to read a
     * decided note today you must already know which student it belongs to. It follows the receipt's
     * precedent exactly (routes/web.php: `finance.payment.record` is the authority to TAKE money,
     * the receipt is a read of money already taken), and ADR 0048's D1 argument is about the same
     * distinction — the ability that gates an act does not gate the record of it.
     */
    public function decided(InvoiceReadModel $read): JsonResponse
    {
        return response()->json([
            'data' => CreditNoteResource::collection($read->decidedCreditNotes()),
        ]);
    }
}

<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Actions\AllocatePayment;
use App\Finance\Exceptions\AllocationRefused;
use App\Finance\Http\Requests\AllocatePaymentRequest;
use App\Finance\Models\Payment;
use App\Finance\Services\AllocationProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * U10 — the allocation surface: the proposal (`proposal`) and the submit that turns an edited one
 * into rows (`store`). The screen that drives both is the next commit.
 *
 * THE ORDER IS DELIBERATE and it is the one §9 step 5b-ii used for the opening-balance queue: build
 * the read, then the writer, then the screen. The other order is what left two approval feeds and a
 * CSV template shipped and reachable from nothing.
 *
 * WHY THIS ROUTE CARRIES `finance.payment.allocate` RATHER THAN THE GROUP'S `finance.access`. Every
 * figure it returns is already visible on the statement to a `finance.access` holder — the open
 * invoices, their outstanding, the payment's amount — so this is not a disclosure gate. It is the
 * SAME gate the write half will carry, applied to the surface that proposes the write, so that the
 * proposal and the submit cannot end up answering to different seats. ADR 0048's D1 correction is
 * the precedent read in the other direction: there, two payment doors shipped under `finance.access`
 * with no ability of their own and the hole was live for weeks.
 */
class PaymentAllocationController extends Controller
{
    /**
     * The proposal for one payment. `{payment:uuid}` is bound through the School-scoped model, so a
     * payment belonging to another School 404s at the binding rather than being read and refused
     * later — the same shape as PaymentReceiptController and the statement's own feed.
     */
    public function proposal(Payment $payment, AllocationProposal $proposal): JsonResponse
    {
        return response()->json($proposal->for($payment));
    }

    /**
     * Submit an edited proposal. Both routes carry `finance.payment.allocate`; nothing about the
     * write is gated more narrowly, because directing money is one act and this is it.
     *
     * THE REFUSALS COME BACK AS FIELD ERRORS, in the `errors` shape Laravel's own validation uses, so
     * a table row can show its own message. That is the whole reason
     * {@see AllocationRefused} carries a field: the Action's refusals are
     * the ones an operator actually trips (this is more than the invoice still owes; this is more than
     * the payment has left; the position moved while you were looking at it), and they cannot be
     * checked before the lock. A `{"message": …}` with no key would put every one of them in a
     * page-level banner above a table of eight editable rows.
     *
     * The two allocation triggers and the provenance pairing stay underneath, unchanged and reachable:
     * a writer that does not come through here still meets them, and gets 1644.
     */
    public function store(AllocatePaymentRequest $request, Payment $payment, AllocatePayment $action): JsonResponse
    {
        try {
            $allocations = $action->handle(
                $payment,
                $request->array('allocations'),
                (string) $request->input('fingerprint'),
                $request->user(),
                $request->input('override_reason'),
            );
        } catch (AllocationRefused $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [$e->field => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'allocated' => $allocations->map(fn ($allocation) => [
                'id' => $allocation->uuid,
                'amount' => $allocation->amount,
                'overridden' => $allocation->allocation_overridden,
            ])->values(),
        ], 201);
    }
}

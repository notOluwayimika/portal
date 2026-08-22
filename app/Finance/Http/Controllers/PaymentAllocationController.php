<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Models\Payment;
use App\Finance\Services\AllocationProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * U10 — the allocation surface. This commit is its READ HALF ONLY: what the engine would do with a
 * payment's unallocated remainder, computed and returned. There is no write path here yet, and the
 * screen that edits the proposal comes after the Action that can accept it.
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
}

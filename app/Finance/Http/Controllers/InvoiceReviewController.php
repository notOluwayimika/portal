<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveInvoice;
use App\Finance\Models\Invoice;
use App\Http\Controllers\Controller;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * INTERNAL AUDIT'S REVIEW SURFACE — the pending queue, and the batch release.
 *
 * Both routes sit behind `permission:finance.invoice.approve` in their own top-level group
 * (routes/endpoints/internal-audit.php), so this controller adds no ability check of its own: the
 * middleware is real and enforced. `ApproveInvoice` re-checks the ability anyway, per invoice —
 * that is the action's contract, not this controller's, and the redundancy is deliberate because
 * the action is callable off-request.
 *
 * ─── THE PENDING COUNT IS THE INSTRUMENT, NOT DECORATION ────────────────────────────────────────
 *
 * `pagination.total` is the FULL count of unreleased bills, not the page length, and it is the
 * reason this endpoint reports pagination at all.
 *
 * The IA control's real failure mode is an OMISSION — a bill nobody ever reviews. That emits no
 * activity row at any severity (config/activity_log_severity.php says so under
 * `finance.invoice.approved`), it throws nothing, and it looks exactly like a quiet week. The only
 * thing that will ever show it is this number growing. A page of 25 that does not say "of 900"
 * hides precisely what the endpoint exists to reveal.
 *
 * It is the house `pagination.total` rather than a second field of its own name, and that is a
 * decision: `FinanceAccountController` and the manual-run roster both report the true total there,
 * and a duplicate field carrying the same number is a second spelling that can drift from the
 * first. What this docblock adds is that here it is LOAD-BEARING.
 *
 * THE DAY A FILTER IS ADDED TO THIS QUERY, `total` SILENTLY BECOMES THE FILTERED SUBSET AND THE
 * OMISSION DETECTOR NARROWS. It will still be a true number and still be called `total`, and
 * nothing will fail. So one of two things must hold in the change that adds the filter: either the
 * filter does not affect this count, or the separate unfiltered count arrives IN THAT SAME CHANGE.
 * A filter that ships without one of those has silently replaced "everything awaiting review" with
 * "everything awaiting review that matches what I happened to type", which is the reassuring
 * direction.
 *
 * ─── A BATCH THAT HALF-SUCCEEDS MUST NOT ANSWER "done" ──────────────────────────────────────────
 *
 * `approve()` returns a PER-INVOICE outcome, never a blanket success. A batch that releases four of
 * five and answers 200 leaves the fifth unreleased while the operator believes it is done — which
 * is an unreviewed bill that looks reviewed, the exact defect this whole slice exists to remove.
 *
 * Each invoice is its OWN transaction, inside `ApproveInvoice`. The batch is deliberately NOT
 * wrapped in one: a single refusal — a void bill, one already released by a colleague — must not
 * roll back attestations that were validly made moments earlier. Per-invoice is the complete axis;
 * the batch is convenience over it.
 */
class InvoiceReviewController extends Controller
{
    /** Default page size. */
    private const PER_PAGE = 25;

    /**
     * The largest page a caller may have. CLAMPED, never validated — the roster's shape and its
     * reasoning: "a client asking for more should get the most it may have, not an error in the
     * middle of a selection" (ManualInvoiceRunStudentController).
     *
     * 100 because that is the top of the shared control's `LIMITS`
     * (resources/js/components/pagination.tsx), so the control cannot offer an option this server
     * refuses. The roster needed its OWN higher ceiling and had to argue from measured cohorts;
     * this screen does not, because an auditor works through a queue rather than ticking a whole
     * class level in one page.
     *
     * Clamped rather than validated, and NOT `max:100` as a rule, on the standing instruction of
     * docs/handoff/tickets/two-index-endpoints-paginate-on-unclamped-user-input.md: that ticket
     * records two endpoints paginating on unclamped input and says plainly not to close it by
     * adding a third convention beside the two that already exist.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * The most invoices one call may release.
     *
     * PAIRED WITH THE PAGE CEILING ON PURPOSE, not chosen independently: an auditor releases what
     * they have just read, and they can only read a page. A cap equal to the largest page means
     * "approve everything on this screen" is always expressible and "approve more than anyone
     * looked at" is not.
     *
     * An unbounded batch is a timeout and a half-applied state with no record of where it stopped —
     * and because each invoice commits on its own, a timeout would leave exactly that: some bills
     * released, some not, and a response nobody received.
     */
    private const MAX_BATCH = 100;

    /** GET /api/internal-audit/invoices/pending */
    public function pending(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', (string) self::PER_PAGE), self::MAX_PER_PAGE));

        // BelongsToSchool scopes this to the active school; the explicit school_id below is not a
        // second filter but the statement of intent the boundary lint asks for on a finance read.
        $page = Invoice::query()
            ->where('school_id', ActiveSchool::getOrFail()->id)
            ->whereNull(Invoice::RELEASE_STAMP_COLUMN)
            ->excludingVoid()
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Invoice $invoice) => [
                'uuid' => $invoice->uuid,
                'number' => $invoice->number,
                'student_id' => $invoice->student_id,
                'kind' => $invoice->kind->value,
                'total' => $invoice->total,
                'issued_at' => $invoice->created_at->toIso8601String(),
            ])->all(),
            'pagination' => [
                // THE INSTRUMENT — see the class docblock. The count of everything awaiting review,
                // not the length of this page.
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** POST /api/internal-audit/invoices/approve */
    public function approve(Request $request, ApproveInvoice $approve): JsonResponse
    {
        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1'],
            'uuids.*' => ['required', 'string'],
        ]);

        $uuids = array_values(array_unique($data['uuids']));

        // Refused BEFORE anything is released, and naming the cap: a caller told "too many" after
        // sixty bills were already stamped could not tell which sixty.
        if (count($uuids) > self::MAX_BATCH) {
            return response()->json([
                'message' => 'Too many invoices in one request: '.count($uuids).' sent, the maximum is '
                    .self::MAX_BATCH.'. Release them in smaller batches.',
            ], 422);
        }

        $schoolId = ActiveSchool::getOrFail()->id;
        $results = [];
        $approved = 0;

        foreach ($uuids as $uuid) {
            $invoice = Invoice::query()->where('school_id', $schoolId)->where('uuid', $uuid)->first();

            if ($invoice === null) {
                // Unknown, not forbidden — the house convention for a row in another school.
                $results[] = ['uuid' => $uuid, 'outcome' => 'refused', 'message' => 'No such invoice in this School.'];

                continue;
            }

            try {
                $approve->handle($invoice, $request->user());
                $results[] = ['uuid' => $uuid, 'outcome' => 'approved'];
                $approved++;
            } catch (BusinessRuleException $e) {
                // The action's sentence, verbatim. It already names the reviewer who holds an
                // existing attestation, or why the bill cannot be released — rewriting it here
                // would produce a second, poorer spelling of the same refusal.
                $results[] = ['uuid' => $uuid, 'outcome' => 'refused', 'message' => $e->getMessage()];
            }
        }

        $refused = count($results) - $approved;

        // 200 ONLY WHEN EVERY ONE WAS RELEASED. Anything else is 207: the operator must not read a
        // partial batch as done, and the status line is the first thing a client branches on.
        return response()->json([
            'approved' => $approved,
            'refused' => $refused,
            'results' => $results,
        ], $refused === 0 ? 200 : 207);
    }
}

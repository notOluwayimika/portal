<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveInvoice;
use App\Finance\Actions\ReturnInvoice;
use App\Finance\Http\Requests\ReturnInvoiceRequest;
use App\Finance\Models\Invoice;
use App\Http\Controllers\Controller;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Builder;
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
 * THAT DAY HAS COME, AND THIS IS THE ANSWER RATHER THAN THE WARNING. `pending()` now filters
 * `whereNull('returned_at')`, so the paragraph above is no longer a prediction and is not left
 * standing beside the thing it predicted.
 *
 * OF THE TWO THINGS THAT HAD TO HOLD, THE SECOND WAS TAKEN: the separate unfiltered count arrives
 * in the same change. The first was not available — `paginate()` derives `last_page` from the very
 * count it reports, so a `total` describing a different set than the rows would make the PAGER lie,
 * and a pager that says "page 1 of 108" over 25 rows of a 2,700-row set is a worse defect than the
 * one being avoided.
 *
 * SO `pagination.total` IS NOW THE FILTERED SUBSET — bills awaiting review — and the omission
 * detector has MOVED, deliberately and by name, into `counts.unreleased_total`, which is
 * unfiltered. Anyone looking for "how many bills has nobody dealt with" reads that field, and the
 * class-docblock argument for why that number matters is unchanged: an omission emits no activity
 * row at any severity, throws nothing, and looks exactly like a quiet week.
 *
 * ─── AND `counts.returned_to_finance` IS LOAD-BEARING, NOT INFORMATIONAL ────────────────────────
 *
 * THERE IS NO FINANCE QUEUE YET — Phase B builds it. Until it exists, this number is the ONLY place
 * in the entire system a returned bill is visible at all. Without it, returning a bill would make
 * it VANISH from every screen: gone from the auditor's queue by the new filter, invisible to the
 * payer because it is still unreleased, and with nowhere for Finance to find it.
 *
 * That is a worse hole than the one this field exists to detect, and worse in the direction that
 * matters: an unreviewed bill is at least sitting in a queue somebody is working through, whereas a
 * returned bill would have an owner who can drop it and no surface that would ever say so.
 *
 * ─── THE INVARIANT, AND WHAT ITS BREAKING MEANS ────────────────────────────────────────────────
 *
 *     unreleased_total == awaiting_review + returned_to_finance
 *
 * Asserted in `tests/Feature/Finance/InvoiceReviewEndpointsTest.php`. It holds because the three
 * counts share every predicate except the return axis, which partitions the unreleased set in two.
 *
 * A BREAK MEANS A FOURTH UNRELEASED STATE HAS APPEARED AND NOBODY UPDATED THESE COUNTS — not that
 * the arithmetic is wrong. That is the whole value of asserting it: the next axis added to this
 * table reds here rather than quietly making one of these numbers describe less than its name says.
 *
 * ALL THREE CARRY `excludingVoid()`, AND THAT IS LOAD-BEARING RATHER THAN TIDY. If one arm counted
 * void bills and the others did not, the invariant would fail for a reason that has nothing to do
 * with review state — a red pointing at the wrong thing, which is how a real signal gets baselined.
 * A mutation dropping `excludingVoid()` from one count reds the invariant arm; that is checked
 * rather than assumed.
 *
 * WHY THIS IS NOT THE "SECOND SPELLING" THE PARAGRAPH ABOVE WARNS AGAINST. That warning was about a
 * duplicate field carrying the SAME number as `pagination.total`, which can drift from it. These
 * are DIFFERENT numbers — and one of them is the number `pagination.total` used to be.
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
 *
 * ─── AND THERE IS NO BATCH RETURN, WHICH IS NOT AN OMISSION ─────────────────────────────────────
 *
 * `approve()` takes many uuids; `return()` takes ONE, in the path. The asymmetry follows from what
 * the two acts carry. A release carries no payload beyond the attestation itself, so "these
 * twenty-five" is a complete instruction. A RETURN CARRIES A REASON, and one reason applied to a
 * hundred bills is a LABEL rather than a reason: Finance would be told something is wrong with a
 * batch without being told what is wrong with any bill in it, and the field that exists to say what
 * to fix would be the one field that says nothing.
 *
 * ─── A RETURN IS WHERE THIS SLICE'S TWO HALVES MEET ────────────────────────────────────────────
 *
 * One successful return moves a bill from `counts.awaiting_review` to `counts.returned_to_finance`
 * and leaves `counts.unreleased_total` UNCHANGED. That last part is the whole argument for the
 * third count existing: the omission detector must not narrow when a bill is returned, or returning
 * bills would quietly shrink the number that exists to reveal bills nobody has dealt with. It is
 * asserted end-to-end rather than reasoned about — see the integration arm in
 * `tests/Feature/Finance/InvoiceReviewEndpointsTest.php`.
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
            // THE RETURN AXIS. A bill out with Finance is still unreleased, so without this it sat
            // in the auditor's queue asking to be reviewed again while Finance was correcting it.
            // This is the filter the class docblock spent a section predicting; the count that
            // replaces what `total` used to mean arrives below, in the same change.
            ->whereNull('returned_at')
            ->excludingVoid()
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage);

        // THE THREE COUNTS. School-scoped and `excludingVoid()` on every arm — see the class
        // docblock: an arm that counted void bills while the others did not would break the
        // invariant for a reason that has nothing to do with review state.
        // A FACTORY, NOT A SHARED BUILDER. Each call returns a fresh query, so the three counts
        // cannot contaminate one another with a predicate meant for a sibling — the failure a
        // single `$base` variable invites and that a reader cannot see at the call site.
        $unreleased = fn (): Builder => Invoice::query()
            ->where('school_id', ActiveSchool::getOrFail()->id)
            ->whereNull(Invoice::RELEASE_STAMP_COLUMN)
            ->excludingVoid();

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
                // NOW THE FILTERED SUBSET — bills awaiting review — because `paginate()` derives
                // `last_page` from this number and a total describing a different set than the rows
                // would make the pager lie. The omission detector moved to `counts.unreleased_total`.
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            'counts' => [
                'awaiting_review' => $unreleased()->whereNull('returned_at')->count(),
                // THE ONLY SURFACE A RETURNED BILL HAS until Phase B builds the Finance queue.
                'returned_to_finance' => $unreleased()->whereNotNull('returned_at')->count(),
                // THE OMISSION DETECTOR, unfiltered by the return axis. This is what
                // `pagination.total` used to be.
                'unreleased_total' => $unreleased()->count(),
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

    /**
     * POST /api/internal-audit/invoices/{uuid}/return
     *
     * `return` IS A LEGAL PHP METHOD NAME — reserved words are permitted as method names since PHP
     * 7.0, and it compiles, resolves through the route action string and passes phpstan. Named for
     * the verb the domain uses rather than renamed to dodge a keyword that is not in the way.
     */
    public function return(ReturnInvoiceRequest $request, string $uuid, ReturnInvoice $action): JsonResponse
    {
        // RESOLVED MANUALLY INSIDE THE TENANT, not by route model binding — the same shape and the
        // same reason as `approve()` above. Binding resolves globally and then refuses late, which
        // both leaks the existence of another school's row and answers with the wrong word.
        // UNKNOWN, NOT FORBIDDEN, is the house convention for a record in another School.
        $invoice = Invoice::query()
            ->where('school_id', ActiveSchool::getOrFail()->id)
            ->where('uuid', $uuid)
            ->first();

        if ($invoice === null) {
            return response()->json(['message' => 'No such invoice in this School.'], 404);
        }

        try {
            $returned = $action->handle($invoice, $request->user(), (string) $request->validated('reason'));
        } catch (BusinessRuleException $e) {
            // The action's sentence, VERBATIM — the house shape (CreditNoteController:54-55 and
            // three siblings). It already names the first returner, or the void-and-credit-note
            // remedy, or the measured length; a second rendering here would be a poorer spelling of
            // the same refusal.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'uuid' => $returned->uuid,
            'number' => $returned->number,
            'student_id' => $returned->student_id,
            'returned_at' => $returned->returned_at?->toIso8601String(),
            'returned_by_user_id' => $returned->returned_by_user_id,
            'return_reason' => $returned->return_reason,
        ]);
    }
}

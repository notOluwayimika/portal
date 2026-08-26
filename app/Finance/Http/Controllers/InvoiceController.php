<?php

namespace App\Finance\Http\Controllers;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Http\Requests\GenerateInvoiceForStudentRequest;
use App\Finance\Http\Requests\GenerateInvoiceRequest;
use App\Finance\Http\Resources\CreditNoteResource;
use App\Finance\Http\Resources\InvoiceResource;
use App\Finance\Http\Resources\PaymentResource;
use App\Finance\Http\Resources\VoidRequestResource;
use App\Finance\Models\Invoice;
use App\Finance\Services\InvoiceReadModel;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The manual entry points: "generate a multi-line invoice for enrollment X",
 * "void invoice X", and the student invoice read.
 *
 * Controllers validate → authorize → delegate → respond; the transaction lives in
 * the Action, and the DB facade is never touched here (arch rule).
 *
 * ── BOTH 201s GO THROUGH InvoiceReadModel::withSettlement() ──
 *
 * The invariant is: any Invoice handed to InvoiceResource that did not come through the read model
 * reports a settlement position of ZERO, true or not, because InvoiceSettlement reads the two
 * aggregates as plain attributes and treats an absent one as zero.
 * See `for` (app/Finance/Services/InvoiceSettlement.php:51).
 *
 * That bites HERE and not only on the page routes, and this is the caller where it shipped.
 * GenerateInvoice applies carry-forward credit inside its own transaction: it writes
 * PaymentAllocation rows against the invoice it has just created, through
 * `applyCreditForward` (app/Finance/Actions/GenerateInvoice.php:576), and then returns that
 * freshly created model. Serialising it directly answered `settlement_state:
 * 'unpaid'`, the full total outstanding, `can_record_payment: true` and `can_request_void: true`
 * with no blocked reason, for an invoice that had just been settled on the way in.
 *
 * IT IS THE ORDINARY CUTOVER PATH, not a corner.
 * `PostOpeningBalanceBatch` (app/Finance/Actions/PostOpeningBalanceBatch.php:114)
 * turns every negative migrated balance into
 * a real payment row, so a student arriving from WCBS in credit has an unallocated payment waiting
 * and the FIRST invoice raised for them takes this branch.
 *
 * The re-read is at the SERIALISATION site rather than inside the Action's return on purpose:
 * ProcessBulkInvoiceRun generates one invoice per student and renders none of them, and it should
 * not pay a query per invoice for a payload nobody builds.
 */
class InvoiceController extends Controller
{
    public function generate(GenerateInvoiceRequest $request, GenerateInvoice $action, InvoiceReadModel $invoices): JsonResponse
    {
        $this->assertMayReduce($request);

        // Reduction provenance, refused as FIELD errors before the Action's transaction (U8 commit 3).
        // AFTER assertMayReduce on purpose, so a principal without the reduction grant still gets its
        // 403 and is told nothing about a policy it may not apply. The DB reduction_guard remains the
        // authority and the backstop — see the method for what it does and does not cover.
        $request->assertDiscountPoliciesUsable();

        try {
            $invoice = $action->handle(
                (string) $request->input('enrollment_id'),
                $request->lineSpecs(),
                // BOTH GENERATE ROUTES NOW CARRY THE CHOICE (U7). It is taken from the request rather
                // than named here because the two routes share one FormRequest, so the rule, the
                // absent-means-scheduled default and the invalid-value refusal exist once and neither
                // route can drift from the other. What is NOT on the wire anywhere is a THIRD kind of
                // caller deciding for itself: ProcessBulkInvoiceRun still names InvoiceKind::Scheduled
                // as a literal, and must — see routes/endpoints/finance.php's bulk-run block.
                $request->invoiceKind(),
                $request->user()?->id,
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new InvoiceResource($invoices->withSettlement($invoice)), 201);
    }

    /**
     * Applying a reduction (waiver/discount) needs `finance.invoice.reduction.apply` ON TOP of the
     * route's `finance.invoice.generate` (S1 Part 0). Checked here, not in the route, because it
     * depends on the request body — a charge-only invoice needs only the generate grant. Refused
     * BEFORE the Action's transaction, so a forbidden reduction writes nothing.
     */
    private function assertMayReduce(GenerateInvoiceRequest $request): void
    {
        if ($request->hasReductionLine() && ! $request->user()->can(Permission::FINANCE_INVOICE_REDUCTION_APPLY->value)) {
            abort(403, 'You may not apply a reduction to an invoice.');
        }
    }

    /**
     * The bursar bills a STUDENT — enrollment resolution is Finance's job, done here via
     * the ACL port, so the frontend never handles an enrollment id or touches Academics.
     * Resolves the student's current billable episode, then delegates to the SAME
     * GenerateInvoice (unchanged domain). No active enrollment → 422; F7/negative-total →
     * the Action's 422.
     *
     * THE TWO GENERATE ROUTES ORDER THEIR REFUSALS DIFFERENTLY, and the asymmetry is recorded here
     * rather than left to be rediscovered. On THIS route the enrollment is resolved at the top, so the
     * "no active enrollment" refusal is available before anything else and runs FIRST: a student who
     * cannot be billed at all is told that, not that a discount policy is retired. On `generate()`
     * above, the enrollment is not validated until inside GenerateInvoice (:73-112), so the pre-check
     * necessarily answers first there and a caller gets the policy problem before the enrollment one.
     *
     * That is not fixable without moving the pre-check into the Action, and the Action throws
     * BusinessRuleException, whose handler answers a plain `{"message": …}` with no `errors` key — so
     * the move would cost the field keys this whole commit exists to produce. The trade is taken
     * knowingly and only on the harness route: `generate()` is the enrollment-id POST, used by tests
     * and no client (routes/endpoints/finance.php:222-225), while this one is what the "New invoice"
     * modal posts to.
     *
     * NOT A DISCLOSURE FIX, and no comment here should say it is. GET /v1/finance/discount-policies
     * carries no permission beyond `finance.access` and already returns `status` and
     * `requires_approval` for every policy the principal can see, so the pre-check's messages tell an
     * authorised caller nothing they could not already read. The reorder is about which refusal is
     * USEFUL to the operator, not about what they may know.
     */
    public function generateForStudent(
        GenerateInvoiceForStudentRequest $request,
        Student $student,
        BillableEnrollmentProvider $enrollments,
        GenerateInvoice $action,
        InvoiceReadModel $invoices,
    ): JsonResponse {
        $enrollment = $enrollments->currentForStudent($student->id);

        $this->assertMayReduce($request);

        // BEFORE the pre-check, deliberately. "This student has no active enrollment to bill" is the
        // more fundamental refusal: nothing about the lines can be acted on until there is something
        // to bill, and telling a bursar to pick a different discount policy for an invoice that cannot
        // exist sends them to fix the wrong thing. Measured before the reorder: that request answered
        // with the policy field error.
        if ($enrollment === null) {
            return response()->json(['message' => 'This student has no active enrollment to bill.'], 422);
        }

        // Reduction provenance as FIELD errors, still before the Action's transaction (U8 commit 3).
        $request->assertDiscountPoliciesUsable();

        try {
            $invoice = $action->handle(
                $enrollment->enrollmentUuid,
                $request->lineSpecs(),
                // The modal's route, and the one the choice was built for: the "New invoice" dialog
                // posts `kind` on every submit, `scheduled` unless the bursar picked otherwise.
                $request->invoiceKind(),
                $request->user()?->id,
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new InvoiceResource($invoices->withSettlement($invoice)), 201);
    }

    /**
     * The current billable episode for a student — what the "New invoice" modal reads to
     * show the bursar which episode they are about to bill (academic context) and whether
     * it is already invoiced (F7), BEFORE they enter any lines. A preview only; the
     * authoritative F7 guard fires at generation.
     *
     * "Already invoiced" means an active SCHEDULED invoice. A supplementary charge against this
     * episode does not make it already-invoiced and must not raise the "void it first" warning —
     * voiding it would be the wrong action, and the term bill it warns about would generate
     * successfully anyway. The predicate is shared with the Action for exactly that reason.
     */
    public function billableEnrollment(
        Student $student,
        BillableEnrollmentProvider $enrollments,
        InvoiceReadModel $invoices,
    ): JsonResponse {
        $enrollment = $enrollments->currentForStudent($student->id);

        if ($enrollment === null) {
            return response()->json(['message' => 'This student has no active enrollment to bill.'], 422);
        }

        return response()->json([
            'academic_context' => $enrollment->academicContext,
            // The SAME method GenerateInvoice's pre-check calls, so the preview and the refusal
            // cannot disagree. The School comes from the episode, which is where the Action takes
            // it from too — not from ActiveSchool, so the two callers name one value from one source.
            'already_invoiced' => $invoices->hasActiveScheduledInvoiceForEnrollment(
                $enrollment->enrollmentId,
                $enrollment->schoolId,
            ),
        ]);
    }

    /**
     * Invoices for a student. Voided invoices are excluded by DEFAULT — they were
     * never really billed. `?include_void=1` is the explicit audit view, which is
     * the only way to see them.
     */
    public function forStudent(Request $request, Student $student, InvoiceReadModel $invoices): JsonResponse
    {
        $includeVoid = $request->boolean('include_void');

        return response()->json([
            'billed_total' => $invoices->billedTotalForStudent($student->id, $includeVoid),
            'invoices' => InvoiceResource::collection(
                $invoices->forStudent($student->id, $includeVoid)
            ),
            // Credit notes ride BESIDE the invoices as their own documents (§5/§7): the
            // invoice keeps its full amount; the statement never shows a netted figure.
            'credit_notes' => CreditNoteResource::collection(
                $invoices->creditNotesForStudent($student->id)
            ),
            // Void requests ride beside the invoices too (Ph3b): a PENDING one shows "void
            // requested, awaiting approval" while the invoice is still active and in the balance;
            // a decided one is the audit trail. The invoice's own status/amount is untouched here.
            'void_requests' => VoidRequestResource::collection(
                $invoices->voidRequestsForStudent($student->id)
            ),
            // The account-level position — where credit-note credit is visible (it carries
            // on the balance, not as a per-invoice line, §10 C1). balance + available_credit
            // each serialise to the Money wire shape.
            'account' => $invoices->accountPositionForStudent($student->id),
            // Payments as their own history — date, amount, method, reference, allocations.
            // Never netted into invoices; the account position already reflects their effect.
            'payments' => PaymentResource::collection(
                $invoices->paymentsForStudent($student->id)
            ),
        ]);
    }
}

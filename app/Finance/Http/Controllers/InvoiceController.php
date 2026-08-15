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
 */
class InvoiceController extends Controller
{
    public function generate(GenerateInvoiceRequest $request, GenerateInvoice $action): JsonResponse
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
                $request->user()?->id,
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new InvoiceResource($invoice), 201);
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
     */
    public function generateForStudent(
        GenerateInvoiceForStudentRequest $request,
        Student $student,
        BillableEnrollmentProvider $enrollments,
        GenerateInvoice $action,
    ): JsonResponse {
        $enrollment = $enrollments->currentForStudent($student->id);

        $this->assertMayReduce($request);
        $request->assertDiscountPoliciesUsable();

        if ($enrollment === null) {
            return response()->json(['message' => 'This student has no active enrollment to bill.'], 422);
        }

        try {
            $invoice = $action->handle($enrollment->enrollmentUuid, $request->lineSpecs(), $request->user()?->id);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new InvoiceResource($invoice), 201);
    }

    /**
     * The current billable episode for a student — what the "New invoice" modal reads to
     * show the bursar which episode they are about to bill (academic context) and whether
     * it is already invoiced (F7), BEFORE they enter any lines. A preview only; the
     * authoritative F7 guard fires at generation.
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
            'already_invoiced' => $invoices->hasActiveInvoiceForEnrollment($enrollment->enrollmentId),
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

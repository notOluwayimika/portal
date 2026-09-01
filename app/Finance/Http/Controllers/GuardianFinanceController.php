<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Http\Resources\GuardianInvoiceResource;
use App\Finance\Services\InvoiceReadModel;
use App\Models\Student;
use App\Services\GuardianService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * THE PARENT PORTAL'S ONE FINANCE READ — what the authenticated guardian's wards owe.
 *
 * @see GuardianPaymentAuthorisation for the write-side half (may this user pay this invoice).
 */
class GuardianFinanceController extends Controller
{
    /**
     * GET /api/parent/finance/wards — the authenticated guardian's OWN position.
     *
     * IT TAKES NO IDENTIFIER, AND THAT IS THE DESIGN RATHER THAN AN OMISSION. There is no student
     * uuid, no guardian uuid and nothing else on the request that names a subject; the wards are
     * derived server-side from the authenticated user through
     * `forUserInActiveSchool` (app/Services/GuardianService.php). An endpoint with no identifier in
     * it HAS NO IDOR SURFACE — there is no uuid to edit, so there is no request that asks about
     * somebody else's child and therefore no ownership check that can be forgotten. The eleven
     * guardian routes fixed in the two commits immediately before this one all had an identifier and
     * all authorised on the ability alone. A per-student variant added here "for convenience" would
     * reopen that class of hole on the one surface that currently cannot have it; if the portal ever
     * needs one ward at a time, that is a filter over THIS shape.
     *
     * IT RETURNS ALL OF THE GUARDIAN'S WARDS, for the same reason: a subject the caller cannot name
     * is a subject the caller cannot get wrong.
     *
     * A GUARDIAN WITH NO ROW IN THIS SCHOOL IS AN EMPTY LIST, NOT AN ERROR — the parent may hold
     * wards in a School they have not switched to, which is a legitimate state and not a failure.
     * `GuardianController::wards` answers the identity half the same way.
     *
     * A WARD WITH NO OUTSTANDING INVOICES STILL APPEARS, carrying an empty `invoices` array. Absence
     * of debt is information a payer screen must be able to render ("nothing owed"); dropping the
     * ward would make "paid up" and "not your child" the same response.
     *
     * WARD IDENTITY IS uuid AND NAME, AND NOTHING ELSE. No date of birth, no class, no admission
     * number — a payment surface needs to tell one child from another, which two fields do. The
     * portal's identity feed (`/api/parent/wards`) is where richer ward data belongs, behind its own
     * decision.
     *
     * ONLY BILLS INTERNAL AUDIT HAS RELEASED APPEAR, ON BOTH KEYS. Brookstone ruled on 31 August
     * 2026 that every bill is reviewed by an Internal Auditor before it reaches a parent (§6). An
     * unreleased bill is real — it is `issued`, it holds the enrollment's active slot and it has
     * posted its ledger charge, so it counts on every staff surface — but this endpoint is the payer
     * channel and it does not carry it. The two keys are gated together on purpose: see the comment
     * on `account` below for what a response that withheld one and not the other would print.
     *
     * QUERIES ARE PER-WARD (invoices + account position), and that is bounded rather than an N+1
     * worth engineering away: a guardian has a handful of wards, and each read is already one
     * aggregate query rather than a per-invoice one.
     *
     * SCHOOL ISOLATION IS AUTOMATIC ON BOTH HALVES: the Guardian row is resolved in the active
     * School, `studentsFor()` reads through Student's SchoolScope, and Invoice/StudentAccount carry
     * BelongsToSchool.
     */
    public function wards(Request $request, GuardianService $guardians, InvoiceReadModel $invoices): JsonResponse
    {
        $guardian = $guardians->forUserInActiveSchool($request->user());

        if ($guardian === null) {
            return response()->json(['data' => []]);
        }

        $data = $guardians->studentsFor($guardian)
            ->map(fn (Student $student) => [
                'student' => [
                    'id' => $student->uuid,
                    'name' => $student->full_name,
                ],
                'invoices' => GuardianInvoiceResource::collection(
                    $invoices->outstandingForStudent($student->id)
                ),
                // The account-level position AS A PAYER MAY SEE IT. This is where credit carries — a
                // parent in credit has no outstanding invoice to show it on (Sec 10 C1), so a
                // response listing invoices alone would report their position as zero when the
                // school in fact owes them. Both figures are the Money wire shape.
                //
                // NOT `accountPositionForStudent()`, WHICH IS THE STAFF STATEMENT'S READ AND STAYS
                // THAT WAY. Since 31 August a bill counts against the student's balance from the
                // moment it is raised but is not visible to parents until Internal Audit releases it
                // (§6). The `invoices` key above withholds the unreleased ones; if this key still
                // totalled them, the screen would print a positive balance directly above "Nothing
                // outstanding". The two keys must answer about the same set of bills or the response
                // is internally contradictory, and the contradiction lands on the payer.
                'account' => $invoices->guardianAccountPositionForStudent($student->id),
            ])
            ->values();

        return response()->json(['data' => $data]);
    }
}

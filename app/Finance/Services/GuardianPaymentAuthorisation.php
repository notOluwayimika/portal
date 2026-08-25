<?php

namespace App\Finance\Services;

use App\Finance\Models\Invoice;
use App\Models\User;
use App\Services\GuardianService;

/**
 * MAY THIS USER PAY THIS INVOICE? — the write-side authorisation seam for the guardian-facing
 * payment portal. Call this BEFORE initiating any gateway transaction against an invoice.
 *
 * ONE METHOD, NOT TWO FACTS TO COMBINE, and that is the point of the class. The question decomposes
 * into "which guardian is this user in the active School" and "is that invoice's student one of
 * theirs" — and a seam where the CALLER assembles those two halves is precisely how the eleven
 * IDOR holes fixed immediately before this commit were built: every one of them authorised on an
 * ability and never on the relationship, because the relationship half was somebody else's line of
 * code to remember. So the caller gets an answer, never the ingredients.
 *
 * IT RESOLVES THE STUDENT FROM THE INVOICE AND DEFERS TO
 * `isWardOf` (app/Services/GuardianService.php) — never `$user->guardian`, which is an unordered
 * `hasOne` whose scope ORs on School access and therefore returns an arbitrary one of a
 * multi-School parent's rows. isWardOf() resolves through forUserInActiveSchool() for exactly that
 * reason; going around it here would reintroduce the bug one surface over.
 *
 * SCHOOL ISOLATION IS CARRIED, NOT ADDED. isWardOf() resolves the Guardian row in the ACTIVE School
 * and matches on the `guardian_student` pivot, and the
 * `guardian_student_same_school` BEFORE INSERT/UPDATE triggers make a cross-School pivot row
 * impossible by construction. An invoice belonging to another School therefore cannot match, and
 * Invoice's own BelongsToSchool means it will not usually have resolved in the first place.
 *
 * ── WHAT THIS DOES **NOT** ANSWER, and do not let the name suggest otherwise ──
 *
 * This is the AUTHORISATION axis alone: *is this invoice one of yours*. It is NOT the PAYABILITY
 * axis — whether the invoice is void, already settled, or in a currency the gateway will take. Those
 * are separate questions with separate owners (InvoiceSettlement derives settlement and eligibility;
 * RecordPayment holds the over-allocation and currency guards, and remains the authority). A true
 * here means "this parent may direct money at this invoice", not "this invoice wants money".
 * Conflating the two would put a settlement rule behind an ownership check, where nobody would think
 * to look for it.
 *
 * FINANCE-PRIVATE (arch: App\Finance\Services is used only inside App\Finance), which is correct and
 * not an obstacle: it takes an Invoice, so any caller already sits inside the module.
 */
final class GuardianPaymentAuthorisation
{
    public function __construct(private GuardianService $guardians) {}

    public function mayPay(User $user, Invoice $invoice): bool
    {
        return $this->guardians->isWardOf($user, (int) $invoice->student_id);
    }
}

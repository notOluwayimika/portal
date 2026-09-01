<?php

use App\Finance\Http\Controllers\GatewayPaymentController;
use App\Finance\Http\Controllers\GuardianFinanceController;
use Illuminate\Support\Facades\Route;

/*
 * THE PARENT PORTAL'S FINANCE READ — one endpoint, and the whole contract the guardian-facing
 * payment portal consumes.
 *
 * ── ITS OWN FILE AND ITS OWN GROUP, BEHIND parent_portal.access ──
 *
 * This is required into api.php inside a group gated on `parent_portal.access`, the ability the
 * `guardian` role already holds (RbacSeeder grantsMap). It is deliberately NOT in
 * routes/endpoints/finance.php, which is required inside the `finance.access` group: that
 * permission opens the ENTIRE admin finance surface — invoices, statements, allocation proposals,
 * bank accounts, opening balances, the approvals queues. Granting it to the guardian role to make
 * one parent read work would be a far larger hole than the guardian IDOR holes closed in the two
 * commits immediately before this one. No permission was added or changed by this commit; if a
 * future change to this endpoint appears to need `finance.access`, the design is wrong.
 *
 * ── /api/parent/... AND NOT /api/v1/finance/... ──
 *
 * The Finance API prefix is frozen at `/api/v1/finance/*` for FINANCE AGGREGATES, all of which sit
 * behind `finance.access`. This is not one of those: it is a parent-portal composition that happens
 * to read Finance, and it sits beside `/api/parent/wards` (the portal's identity feed) under the
 * same ability. Hanging it off the finance prefix would put a parent-gated route in the middle of
 * the admin namespace, where the next reader would reasonably assume the group's permission.
 *
 * ── NO IDENTIFIER ON THE URL, ON PURPOSE ──
 *
 * See GuardianFinanceController::wards. The subject is derived from the authenticated user, so
 * there is nothing on the request to tamper with. Do not add a per-student or per-invoice variant.
 */
Route::get('/parent/finance/wards', [GuardianFinanceController::class, 'wards'])
    ->name('parent.finance.wards');

/*
 * ── STARTING A PAYMENT (step 3) ────────────────────────────────────────────────────────────────
 *
 * SAME GROUP, SAME ABILITY, NO NEW PERMISSION. `parent_portal.access` gates the surface; WHICH
 * invoice this particular parent may pay is a relationship question, not a permission one, and
 * `GuardianPaymentAuthorisation::mayPay()` answers it in the FormRequest by asking whether the
 * invoice's student is their ward. A permission cannot express "this parent, that child", and
 * adding one would imply it could.
 *
 * ⚠️ THE READ ROUTE ABOVE SAYS "DO NOT ADD A PER-STUDENT OR PER-INVOICE VARIANT", AND THIS IS ONE.
 * The instruction is not being ignored; it is scoped to the READ. Its reason is that the wards feed
 * derives its subject from the authenticated user, so there is nothing on the request to tamper
 * with — a per-student read variant would reintroduce exactly the identifier the design removed.
 *
 * A WRITE cannot work that way: the payer must say which bill they are paying, so an identifier is
 * unavoidable and the protection has to come from elsewhere. It does — the FormRequest resolves the
 * uuid under `SchoolScope` and then asks `mayPay()` whether that invoice's student is this user's
 * ward, so a tampered identifier fails authorisation rather than merely failing to be found.
 * Recorded here because a reader meeting the two blocks together would otherwise reasonably
 * conclude one of them is wrong.
 *
 * ADDRESSED BY UUID. A sequential invoice id in a URL a parent holds is an invitation to walk it;
 * every parent-facing finance identifier in this system is a uuid for that reason. `SchoolScope`
 * is active on the lookup, so another school's uuid resolves to nothing and is refused as
 * unauthorised rather than as not-found — which of the two would itself disclose existence.
 *
 * WRITE ROUTE, SO IT IS THROTTLED TIGHTER THAN THE READ. Each accepted request creates a row and
 * calls a third party; a parent legitimately retrying a failed checkout does so a handful of times,
 * not dozens.
 */
Route::post('/parent/invoices/{invoice}/payment', [GatewayPaymentController::class, 'store'])
    ->middleware('throttle:12,1')
    ->name('parent.finance.payment.store');

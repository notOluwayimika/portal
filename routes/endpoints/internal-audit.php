<?php

use App\Finance\Http\Controllers\InvoiceReviewController;
use Illuminate\Support\Facades\Route;

/*
 * INTERNAL AUDIT'S REVIEW SURFACE — the pending feed, the batch release, and the return.
 *
 * ── ITS OWN FILE AND ITS OWN GROUP, BEHIND finance.invoice.approve ──
 *
 * Required into api.php inside a TOP-LEVEL group gated on `finance.invoice.approve`, the ability
 * `internal_auditor` holds and the only finance ability it holds. It is deliberately NOT inside the
 * `finance.access` group that requires routes/endpoints/finance.php: that permission opens the
 * ENTIRE admin finance surface — invoices, statements, allocation proposals, bank accounts, opening
 * balances, every approvals queue — and granting it to an audit-only seat to make one read work
 * would widen that seat into the whole bursar's desk. The audit seat does not need it and must not
 * have it.
 *
 * This is the same shape, and the same argument, as routes/endpoints/parent-finance.php: a finance
 * surface reached by a seat that is not the bursar gets its own declaration rather than a line
 * inside a longer list, so the gate is readable in one file.
 *
 * ── /api/internal-audit/... AND NOT /api/v1/finance/... ──
 *
 * The `/api/v1/finance/*` prefix is the FINANCE AGGREGATE namespace, and every route in it sits
 * behind `finance.access`. Hanging an audit-gated route off it would put a differently-gated route
 * in the middle of that namespace, where the next reader would reasonably assume the group's
 * permission — the same reasoning parent-finance.php gives for `/api/parent/...`.
 *
 * ── super_admin REACHES NEITHER, AND THAT IS NOT AN OVERSIGHT ──
 *
 * `finance.invoice.approve` terminates in `approve`, so ApprovalAbility::CHECKER_SEGMENTS excludes
 * it from the Gate::before bypass (ADR 0040): approval authority comes from an explicit grant,
 * never from platform authority. `finance.invoice.reject` terminates in `reject`, the OTHER checker
 * segment, so the return route is excluded on the same ground. RouteAccessMap::derive() models
 * that, which is why the committed access map shows only `internal_auditor` for all three routes.
 *
 * ── THE RETURN ROUTE CARRIES ITS OWN GATE, AND THE CONSEQUENCE IS THAT IT NEEDS BOTH ───────────
 *
 * The group above gates on `finance.invoice.approve`. A return route added here with NO middleware
 * of its own would be gated on the permission for the OTHER VERB — and it would pass every test,
 * because `internal_auditor` holds both. So the route declares
 * `permission:finance.invoice.reject` explicitly.
 *
 * WHICH MEANS THE RETURN ROUTE REQUIRES **BOTH** ABILITIES: the group's `approve` and its own
 * `reject`. STATED HERE RATHER THAN LEFT TO BE DISCOVERED, because it is a real property of the
 * declaration and not an accident of it.
 *
 * IT IS CORRECT TODAY. `internal_auditor` holds both; `app/Enums/Permission.php` calls approve and
 * reject "the two checker sides of one act"; both name the same maker in
 * `ApprovalAbility::MAKER_OVERRIDES`. There is no return-only seat and none is contemplated.
 *
 * IF ONE IS EVER CREATED, THE GROUP GATE IN `routes/api.php` IS WHAT MUST CHANGE — not this line —
 * and this paragraph is where that reader should start. The group is deliberately NOT restructured
 * now to accommodate a seat nobody has asked for: a gate widened for a hypothetical is a gate that
 * is wrong today in exchange for being right on a day that may not come.
 */
Route::prefix('internal-audit')->group(function () {
    // The auditor's queue: bills raised and not yet released to their payer.
    Route::get('/invoices/pending', [InvoiceReviewController::class, 'pending']);

    // Release many. One attestation per invoice, one transaction per invoice, and a per-invoice
    // outcome in the response — see the controller for why a blanket 200 would be the defect.
    Route::post('/invoices/approve', [InvoiceReviewController::class, 'approve']);

    // Return ONE bill to Finance. PER-INVOICE, PATH PARAMETER, NO BATCH — and the asymmetry with
    // `approve` above is the point rather than an inconsistency: a release carries no payload
    // beyond the attestation, so many uuids in a body is the right shape for it. A return carries a
    // REASON, and one reason applied to a hundred bills is a LABEL, not a reason — it would tell
    // Finance that something is wrong with a batch without telling them what is wrong with any bill
    // in it.
    //
    // The explicit gate is `finance.invoice.reject`; see the docblock above for why it is written
    // here and what requiring BOTH abilities means.
    Route::post('/invoices/{uuid}/return', [InvoiceReviewController::class, 'return'])
        ->middleware('permission:finance.invoice.reject');
});

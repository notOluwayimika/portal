<?php

use App\Finance\Http\Controllers\InvoiceReviewController;
use Illuminate\Support\Facades\Route;

/*
 * INTERNAL AUDIT'S REVIEW SURFACE — the pending feed, and the batch release.
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
 * never from platform authority. RouteAccessMap::derive() models that, which is why the committed
 * access map shows only `internal_auditor` for both routes.
 */
Route::prefix('internal-audit')->group(function () {
    // The auditor's queue: bills raised and not yet released to their payer.
    Route::get('/invoices/pending', [InvoiceReviewController::class, 'pending']);

    // Release many. One attestation per invoice, one transaction per invoice, and a per-invoice
    // outcome in the response — see the controller for why a blanket 200 would be the defect.
    Route::post('/invoices/approve', [InvoiceReviewController::class, 'approve']);
});

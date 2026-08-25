<?php

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

<?php

use App\Finance\Http\Controllers\CreditNoteController;
use App\Finance\Http\Controllers\DiscountPolicyChangeController;
use App\Finance\Http\Controllers\DiscountPolicyController;
use App\Finance\Http\Controllers\FeeScheduleChangeController;
use App\Finance\Http\Controllers\FeeScheduleController;
use App\Finance\Http\Controllers\FinanceAccountController;
use App\Finance\Http\Controllers\InvoiceController;
use App\Finance\Http\Controllers\PaymentController;
use App\Finance\Http\Controllers\VoidRequestController;
use Illuminate\Support\Facades\Route;

/*
 * Finance manual entry points (no automated billing trigger; that needs the
 * enrollment-create fan-out convergence + EnrollmentCreated event, 1.4e).
 * Required into api.php inside an auth + role group.
 *
 * Path is /api/v1/finance/* — the frozen Finance API prefix (§16); every Finance
 * aggregate hangs off /api/v1/finance from here on.
 */
Route::post('/v1/finance/invoices', [InvoiceController::class, 'generate'])
    ->middleware('permission:finance.invoice.generate');
Route::post('/v1/finance/invoices/{invoice:uuid}/payments', [PaymentController::class, 'store']);

/*
 * Invoice VOID is MAKER-CHECKER (Ph3b) — the second instance of the credit-note template. The
 * one-step cancel is RETIRED: reversing a whole charge takes two people, so it is a lifecycle:
 *   • SUBMIT (maker) proposes a void — the invoice stays issued, in the balance, no money moves.
 *   • APPROVE (checker ≠ maker) voids the invoice + posts the reversing ledger entry (−total).
 *   • REJECT (checker ≠ maker) closes it with a reason — the charge stands, no money.
 * Each route needs finance.access (the group) AND its own maker/checker permission; the
 * record-level maker ≠ checker rule is the VoidRequestPolicy (Gate::authorize in approve/reject),
 * with the DB CHECK as the backstop. The queue is unified with credit notes in the checker's screen.
 */
Route::post('/v1/finance/invoices/{invoice:uuid}/void-requests', [VoidRequestController::class, 'submit'])
    ->middleware('permission:finance.invoice.void-request.submit');
Route::get('/v1/finance/void-requests/pending', [VoidRequestController::class, 'pending'])
    ->middleware('permission:finance.invoice.void-request.approve');
Route::post('/v1/finance/void-requests/{voidRequest:uuid}/approve', [VoidRequestController::class, 'approve'])
    ->middleware('permission:finance.invoice.void-request.approve');
Route::post('/v1/finance/void-requests/{voidRequest:uuid}/reject', [VoidRequestController::class, 'reject'])
    ->middleware('permission:finance.invoice.void-request.reject');

/*
 * Credit-note issuance is MAKER-CHECKER (Ph3). Forgiving money takes two people, so it is
 * a lifecycle, not one call:
 *   • SUBMIT (maker) proposes a pending credit note — no money moves.
 *   • APPROVE (checker ≠ maker) posts the compensating ledger credit + moves the balance.
 *   • REJECT (checker ≠ maker) closes it with a reason — never any money.
 * Each route needs finance.access (the group) AND its own maker/checker permission; the
 * record-level maker ≠ checker rule is the CreditNotePolicy (Gate::authorize in approve/
 * reject), with the DB CHECK as the backstop. The pending queue is the checker's screen.
 */
Route::post('/v1/finance/invoices/{invoice:uuid}/credit-notes', [CreditNoteController::class, 'submit'])
    ->middleware('permission:finance.credit-note.submit');
Route::get('/v1/finance/credit-notes/pending', [CreditNoteController::class, 'pending'])
    ->middleware('permission:finance.credit-note.approve');
Route::post('/v1/finance/credit-notes/{creditNote:uuid}/approve', [CreditNoteController::class, 'approve'])
    ->middleware('permission:finance.credit-note.approve');
Route::post('/v1/finance/credit-notes/{creditNote:uuid}/reject', [CreditNoteController::class, 'reject'])
    ->middleware('permission:finance.credit-note.reject');

/*
 * Read side. Voided invoices are excluded by default; ?include_void=1 is the
 * explicit audit view. The exclusion lives in the read model, not a global scope —
 * a global scope would make the {invoice:uuid} bindings above miss a voided
 * invoice and turn the double-void 422 into a 404.
 */
Route::get('/v1/finance/students/{student:uuid}/invoices', [InvoiceController::class, 'forStudent']);

/*
 * The accounts index — the bursar's front door. A School-scoped, paginated read over
 * finance_student_accounts (per-row balance + live student display via the ACL port) plus
 * the School-wide KPI totals. Read-only; ?search=, ?status=, ?sort= drive the view.
 */
Route::get('/v1/finance/accounts', [FinanceAccountController::class, 'index']);

/*
 * Fee schedules (S1 commit 2, narrowed in commit 4) — the per-School pricing catalog per (term × class
 * level). Reads carry only the group's finance.access; DRAFT authoring (store/update) needs
 * finance.fee-schedule.manage. As of commit 4 `store`/`update` write DRAFTS ONLY — the act of making a
 * schedule `active` has moved entirely into the fee-schedule-change approval below, so there is no route
 * by which a schedule reaches `active` without an approved change (proof 31). `prefill` resolves the
 * ACTIVE schedule's items into prefilled charge lines for the bursar's generate form (a draft never prices).
 */
Route::get('/v1/finance/fee-schedules', [FeeScheduleController::class, 'index']);
Route::get('/v1/finance/fee-schedules/prefill', [FeeScheduleController::class, 'prefill']);
Route::post('/v1/finance/fee-schedules', [FeeScheduleController::class, 'store'])
    ->middleware('permission:finance.fee-schedule.manage');
Route::put('/v1/finance/fee-schedules/{feeSchedule:uuid}', [FeeScheduleController::class, 'update'])
    ->middleware('permission:finance.fee-schedule.manage');

/*
 * Fee-schedule governance (S1 commit 4). PUBLISHING a schedule is maker-checker: the Head submits a
 * publish/retire change (finance.fee-schedule.change.submit), the ED approves/rejects (…approve / …reject).
 * A schedule reaches `active` ONLY when ApproveFeeScheduleChange approves; the pending queue joins the
 * unified approvals screen by the ApprovalAbility convention (no route edit). Mirrors discount governance.
 */
Route::post('/v1/finance/fee-schedule-changes', [FeeScheduleChangeController::class, 'submit'])
    ->middleware('permission:finance.fee-schedule.change.submit');
Route::get('/v1/finance/fee-schedule-changes/pending', [FeeScheduleChangeController::class, 'pending'])
    ->middleware('permission:finance.fee-schedule.change.approve');
Route::post('/v1/finance/fee-schedule-changes/{change:uuid}/approve', [FeeScheduleChangeController::class, 'approve'])
    ->middleware('permission:finance.fee-schedule.change.approve');
Route::post('/v1/finance/fee-schedule-changes/{change:uuid}/reject', [FeeScheduleChangeController::class, 'reject'])
    ->middleware('permission:finance.fee-schedule.change.reject');

/*
 * Discount policies (S1 commit 3, axis A). The catalog is read-only here — editing and removing are
 * `amend` and `retire` change requests, never a direct PUT/DELETE. Governance is maker-checker: the
 * Head submits create/amend/retire (finance.discount-policy.change.submit), the ED approves/rejects
 * (…approve / …reject). The catalog changes ONLY when ApproveDiscountPolicyChange approves; the pending
 * queue joins the unified approvals screen by the ApprovalAbility convention (no route edit).
 */
Route::get('/v1/finance/discount-policies', [DiscountPolicyController::class, 'index']);
Route::post('/v1/finance/discount-policy-changes', [DiscountPolicyChangeController::class, 'submit'])
    ->middleware('permission:finance.discount-policy.change.submit');
Route::get('/v1/finance/discount-policy-changes/pending', [DiscountPolicyChangeController::class, 'pending'])
    ->middleware('permission:finance.discount-policy.change.approve');
Route::post('/v1/finance/discount-policy-changes/{change:uuid}/approve', [DiscountPolicyChangeController::class, 'approve'])
    ->middleware('permission:finance.discount-policy.change.approve');
Route::post('/v1/finance/discount-policy-changes/{change:uuid}/reject', [DiscountPolicyChangeController::class, 'reject'])
    ->middleware('permission:finance.discount-policy.change.reject');

/*
 * Bill a STUDENT (the bursar UI's path). Enrollment resolution is server-side via the
 * ACL port, so the frontend never handles an enrollment id. The read powers the "New
 * invoice" modal's episode-confirm + F7 preview; the write generates and delegates to the
 * same GenerateInvoice (unchanged). The old enrollment-id POST above stays for the harness.
 */
Route::get('/v1/finance/students/{student:uuid}/billable-enrollment', [InvoiceController::class, 'billableEnrollment']);
Route::post('/v1/finance/students/{student:uuid}/invoices', [InvoiceController::class, 'generateForStudent'])
    ->middleware('permission:finance.invoice.generate');

/*
 * Record a payment ON THE ACCOUNT — no invoice named (the "money at the window" door). Banks as
 * account credit and settles oldest-first at the next generation (ADR 0048). Ships under the
 * finance.access group with NO ability of its own: the dedicated finance.payment.record permission
 * (D1) is decided-and-scheduled as its own seeder-owned slice, sequenced before any pilot takes a
 * payment — recorded in ADR 0048, not omitted. Mirrors the student-addressed invoice POST above.
 */
Route::post('/v1/finance/students/{student:uuid}/payments', [PaymentController::class, 'storeForStudent']);

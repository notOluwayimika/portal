<?php

use App\Finance\Http\Controllers\CreditNoteController;
use App\Finance\Http\Controllers\FinanceAccountController;
use App\Finance\Http\Controllers\InvoiceController;
use App\Finance\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

/*
 * Finance manual entry points (no automated billing trigger; that needs the
 * enrollment-create fan-out convergence + EnrollmentCreated event, 1.4e).
 * Required into api.php inside an auth + role group.
 *
 * Path is /api/v1/finance/* — the frozen Finance API prefix (§16); every Finance
 * aggregate hangs off /api/v1/finance from here on.
 */
Route::post('/v1/finance/invoices', [InvoiceController::class, 'generate']);
Route::post('/v1/finance/invoices/{invoice:uuid}/cancel', [InvoiceController::class, 'cancel']);
Route::post('/v1/finance/invoices/{invoice:uuid}/payments', [PaymentController::class, 'store']);

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
 * Bill a STUDENT (the bursar UI's path). Enrollment resolution is server-side via the
 * ACL port, so the frontend never handles an enrollment id. The read powers the "New
 * invoice" modal's episode-confirm + F7 preview; the write generates and delegates to the
 * same GenerateInvoice (unchanged). The old enrollment-id POST above stays for the harness.
 */
Route::get('/v1/finance/students/{student:uuid}/billable-enrollment', [InvoiceController::class, 'billableEnrollment']);
Route::post('/v1/finance/students/{student:uuid}/invoices', [InvoiceController::class, 'generateForStudent']);

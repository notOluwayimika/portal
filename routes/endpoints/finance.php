<?php

use App\Finance\Http\Controllers\CreditNoteController;
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
 * Issuing a credit note / write-off forgives money, so beyond the group's
 * finance.access it requires a DISTINCT permission. Layered middleware: this route
 * needs BOTH finance.access (the group) AND finance.credit-note.issue.
 */
Route::post('/v1/finance/invoices/{invoice:uuid}/credit-notes', [CreditNoteController::class, 'store'])
    ->middleware('permission:finance.credit-note.issue');

/*
 * Read side. Voided invoices are excluded by default; ?include_void=1 is the
 * explicit audit view. The exclusion lives in the read model, not a global scope —
 * a global scope would make the {invoice:uuid} bindings above miss a voided
 * invoice and turn the double-void 422 into a 404.
 */
Route::get('/v1/finance/students/{student:uuid}/invoices', [InvoiceController::class, 'forStudent']);

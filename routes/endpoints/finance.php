<?php

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

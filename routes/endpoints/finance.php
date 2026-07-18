<?php

use App\Finance\Http\Controllers\InvoiceController;
use App\Finance\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

/*
 * Finance walking skeleton — the manual entry points (no automated billing
 * trigger; that needs the enrollment-create fan-out convergence + EnrollmentCreated
 * event, 1.4e). Required into api.php inside an auth + role group.
 *
 * Path is /api/finance/* (unversioned, matching the existing /api/* surface). The
 * §16 /api/v1/finance/* versioning is a Ph2 decision when the surface broadens.
 */
Route::post('/finance/invoices', [InvoiceController::class, 'generate']);
Route::post('/finance/invoices/{invoice:uuid}/cancel', [InvoiceController::class, 'cancel']);
Route::post('/finance/invoices/{invoice:uuid}/payments', [PaymentController::class, 'store']);

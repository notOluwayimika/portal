<?php

use App\Http\Controllers\GuardianController;
use App\Http\Controllers\GuardianImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('guardians')->group(function () {
    Route::get('/lookup',                            [GuardianController::class, 'lookup']);
    Route::get('/resources',                         [GuardianController::class, 'resources']);
    Route::get('/export',                            [GuardianController::class, 'export']);
    Route::post('/bulk-message',                     [GuardianController::class, 'bulkMessage']);
    Route::post('/bulk-enable-login',                [GuardianController::class, 'bulkEnableLogin']);
    Route::post('/bulk-disable-login',               [GuardianController::class, 'bulkDisableLogin']);
    Route::post('/bulk-status',                      [GuardianController::class, 'bulkStatus']);

    Route::get('/import/template',                   [GuardianImportController::class, 'template']);
    Route::post('/import',                           [GuardianImportController::class, 'store']);
    Route::get('/imports',                           [GuardianImportController::class, 'index']);
    Route::get('/import/{import:uuid}/status',       [GuardianImportController::class, 'status']);
    Route::get('/import/{import:uuid}/report',       [GuardianImportController::class, 'report']);

    Route::get('/',                                  [GuardianController::class, 'index']);
    Route::post('/',                                 [GuardianController::class, 'store']);
    Route::get('/{guardian:uuid}',                   [GuardianController::class, 'show']);
    Route::get('/{guardian:uuid}/students',          [GuardianController::class, 'students']);
    Route::get('/{guardian:uuid}/audit',             [GuardianController::class, 'auditHistory']);
    Route::get('/{guardian:uuid}/activity',          [GuardianController::class, 'activity']);
    Route::put('/{guardian:uuid}',                   [GuardianController::class, 'update']);
    Route::patch('/{guardian:uuid}',                 [GuardianController::class, 'update']);
    Route::post('/{guardian:uuid}/enable-login',     [GuardianController::class, 'enableLogin']);
    Route::post('/{guardian:uuid}/disable-login',    [GuardianController::class, 'disableLogin']);
    Route::post('/{guardian:uuid}/reset-password',   [GuardianController::class, 'resetPassword']);
    Route::post('/{guardian:uuid}/resend-invitation',[GuardianController::class, 'resendInvitation']);
    Route::delete('/{guardian:uuid}',                [GuardianController::class, 'destroy']);
});

Route::post('/students/{student:uuid}/guardians',                       [GuardianController::class, 'attach']);
Route::put('/students/{student:uuid}/guardians/{guardian:uuid}',        [GuardianController::class, 'updatePivot'])
    ->withoutScopedBindings();
Route::patch('/students/{student:uuid}/guardians/{guardian:uuid}',      [GuardianController::class, 'updatePivot'])
    ->withoutScopedBindings();
Route::delete('/students/{student:uuid}/guardians/{guardian:uuid}',     [GuardianController::class, 'detach'])
    ->withoutScopedBindings();

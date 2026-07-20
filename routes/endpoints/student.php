<?php

use App\Http\Controllers\StudentBulkUpdateController;
use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

Route::prefix('students')->group(function () {
    // NOTE: GET '/' (index) and GET '/{student:uuid}' (show) are declared in a
    // separate `role:...|principal` group in api.php so principals get read-only
    // access. Keep the write/bulk routes here (principal-excluded).
    Route::post('/', [StudentController::class, 'store']);
    Route::get('/resources', [StudentController::class, 'resources']);
    Route::get('/export', [StudentController::class, 'export']);
    Route::post('/import', [StudentController::class, 'import']);

    Route::get('/bulk-update/template', [StudentBulkUpdateController::class, 'template']);
    Route::post('/bulk-update', [StudentBulkUpdateController::class, 'store']);
    Route::get('/bulk-updates', [StudentBulkUpdateController::class, 'index']);
    Route::get('/bulk-update/{import:uuid}/status', [StudentBulkUpdateController::class, 'status']);
    Route::get('/bulk-update/{import:uuid}/report', [StudentBulkUpdateController::class, 'report']);

    Route::patch('/{student:uuid}', [StudentController::class, 'update']);
    Route::patch('/{student:uuid}/status', [StudentController::class, 'updateStatus']);
    Route::delete('/{student:uuid}', [StudentController::class, 'destroy']);
});

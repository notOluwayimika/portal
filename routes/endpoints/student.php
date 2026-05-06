<?php

use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->prefix('students')->group(function () {
    Route::get('/', [StudentController::class, 'index']);
    Route::post('/', [StudentController::class, 'store']);
    Route::get('/resources', [StudentController::class, 'resources']);
    Route::get('/{student:uuid}', [StudentController::class, 'show']);
    Route::patch('/{student:uuid}', [StudentController::class, 'update']);
    Route::delete('/{student:uuid}', [StudentController::class, 'destroy']);
});

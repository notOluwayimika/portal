<?php

use App\Http\Controllers\Api\AuthenticationController;
use App\Http\Controllers\ClassLevelArmController;
use App\Http\Controllers\ExamTypeController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SetupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
// Authentication
Route::post('/login', [AuthenticationController::class, 'login']);
Route::post('/register', [AuthenticationController::class, 'register']);

// get sessions
Route::get('/sessions', [SessionController::class, 'index']);
// get class level arm structure
Route::get('/class-structure', [ClassLevelArmController::class, 'index']);
// get exam types
Route::get('/exam-types', [ExamTypeController::class, 'index']);


Route::middleware(['auth:sanctum', 'role:admin|head_of_school'])->group(function () {
    Route::get('/user', [AuthenticationController::class, 'user']);

    // protected session routes
    Route::post('/sessions', [SessionController::class, 'store']);
    Route::put('/sessions/{session:uuid}', [SessionController::class, 'update']);
    Route::delete('/sessions/{session:uuid}', [SessionController::class, 'destroy']);
    Route::post('/sessions/{session:uuid}/current', [SessionController::class, 'setCurrent']);

    // protected class structure (level and arms)
    Route::post('/class-structure/toggle', [ClassLevelArmController::class, 'toggle']);
    Route::post('/class-structure/levels', [ClassLevelArmController::class, 'storeLevel']);
    Route::put('/class-structure/levels/{classLevel:uuid}', [ClassLevelArmController::class, 'updateLevel']);
    Route::delete('/class-structure/levels/{classLevel:uuid}', [ClassLevelArmController::class, 'destroyLevel']);
    Route::post('/class-structure/arms', [ClassLevelArmController::class, 'storeArm']);
    Route::put('/class-structure/arms/{arm:uuid}', [ClassLevelArmController::class, 'updateArm']);
    Route::delete('/class-structure/arms/{arm:uuid}', [ClassLevelArmController::class, 'destroyArm']);

    // protected exam types routes
    Route::post('/exam-types', [ExamTypeController::class, 'store']);
    Route::put('/exam-types/{examType:uuid}', [ExamTypeController::class, 'update']);
    Route::delete('/exam-types/{examType:uuid}', [ExamTypeController::class, 'destroy']);

    // get setup data
    Route::get('/setup-data', [SetupController::class, 'index']);

    Route::post('/logout', [AuthenticationController::class, 'logout']);
});

<?php

use App\Http\Controllers\Api\AuthenticationController;
use App\Http\Controllers\SessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
// Authentication
Route::post('/login', [AuthenticationController::class, 'login']);
Route::post('/register', [AuthenticationController::class, 'register']);

// get sessions
Route::get('/sessions', [SessionController::class, 'indexApi']);

Route::middleware(['auth:sanctum', 'role:admin|head_of_school'])->group(function () {
    Route::get('/user', [AuthenticationController::class, 'user']);

    // get sessions

    Route::post('/sessions', [SessionController::class, 'store']);
    Route::put('/sessions/{id}', [SessionController::class, 'update']);
    Route::delete('/sessions/{id}', [SessionController::class, 'destroy']);

    Route::post('/logout', [AuthenticationController::class, 'logout']);
});

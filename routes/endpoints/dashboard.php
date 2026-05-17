<?php

use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('dashboard')->group(function () {
    Route::get('/analysis', [DashboardController::class, 'analysis']);
    Route::post('/analysis/refresh', [DashboardController::class, 'refresh'])->middleware('throttle:1,1');
    Route::get('/widgets', [DashboardController::class, 'widgets']);
    Route::get('/widgets/{widget}', [DashboardController::class, 'widget']);
    Route::get('/onboarding', [DashboardController::class, 'onboarding']);
});
<?php

use App\Http\Controllers\BehavioralAssessmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('behavioral-assessments')->group(function () {
    Route::get('/', [BehavioralAssessmentController::class, 'index']);
    Route::post('/', [BehavioralAssessmentController::class, 'store']);
});

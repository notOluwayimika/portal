<?php

use App\Http\Controllers\ActivityLog\ActivityLogController;
use App\Http\Controllers\ActivityLog\SavedActivityFilterController;
use Illuminate\Support\Facades\Route;

Route::prefix('activity-logs')->group(function () {
    // Static segments first so they don't collide with /{id}.
    Route::get('/filters/options', [ActivityLogController::class, 'filterOptions']);
    Route::get('/stats', [ActivityLogController::class, 'stats']);
    Route::get('/export', [ActivityLogController::class, 'export']);
    Route::get('/exports/{export}', [ActivityLogController::class, 'downloadExport']);

    Route::get('/saved-filters', [SavedActivityFilterController::class, 'index']);
    Route::post('/saved-filters', [SavedActivityFilterController::class, 'store']);
    Route::delete('/saved-filters/{savedActivityFilter}', [SavedActivityFilterController::class, 'destroy']);

    Route::get('/', [ActivityLogController::class, 'index']);
    Route::get('/{id}', [ActivityLogController::class, 'show'])->whereNumber('id');
});

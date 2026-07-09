<?php

use App\Http\Controllers\BroadsheetController;
use Illuminate\Support\Facades\Route;

Route::prefix('broadsheets')->group(function () {
    Route::get('/groups', [BroadsheetController::class, 'groups']);
    Route::get('/{curriculum:uuid}', [BroadsheetController::class, 'show']);
    Route::get('/{curriculum:uuid}/export', [BroadsheetController::class, 'export']);
});

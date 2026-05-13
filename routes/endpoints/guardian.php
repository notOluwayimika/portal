<?php

use App\Http\Controllers\GuardianController;
use Illuminate\Support\Facades\Route;

Route::prefix('guardians')->group(function () {
    Route::get('/lookup',              [GuardianController::class, 'lookup']);
    Route::get('/resources',           [GuardianController::class, 'resources']);
    Route::get('/',                    [GuardianController::class, 'index']);
    Route::get('/{guardian:uuid}',     [GuardianController::class, 'show']);
    Route::delete('/{guardian:uuid}',  [GuardianController::class, 'destroy']);
});

Route::post('/students/{student:uuid}/guardians',                   [GuardianController::class, 'attach']);
Route::delete('/students/{student:uuid}/guardians/{guardian:uuid}', [GuardianController::class, 'detach'])
    ->withoutScopedBindings();

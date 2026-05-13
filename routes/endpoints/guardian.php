<?php

use App\Http\Controllers\GuardianController;
use Illuminate\Support\Facades\Route;

Route::prefix('guardians')->group(function () {
    Route::get('/lookup',                       [GuardianController::class, 'lookup']);
    Route::get('/resources',                    [GuardianController::class, 'resources']);
    Route::get('/',                             [GuardianController::class, 'index']);
    Route::get('/{guardian:uuid}',              [GuardianController::class, 'show']);
    Route::get('/{guardian:uuid}/students',     [GuardianController::class, 'students']);
    Route::put('/{guardian:uuid}',              [GuardianController::class, 'update']);
    Route::patch('/{guardian:uuid}',            [GuardianController::class, 'update']);
    Route::post('/{guardian:uuid}/enable-login', [GuardianController::class, 'enableLogin']);
    Route::delete('/{guardian:uuid}',           [GuardianController::class, 'destroy']);
});

Route::post('/students/{student:uuid}/guardians',                       [GuardianController::class, 'attach']);
Route::put('/students/{student:uuid}/guardians/{guardian:uuid}',        [GuardianController::class, 'updatePivot'])
    ->withoutScopedBindings();
Route::patch('/students/{student:uuid}/guardians/{guardian:uuid}',      [GuardianController::class, 'updatePivot'])
    ->withoutScopedBindings();
Route::delete('/students/{student:uuid}/guardians/{guardian:uuid}',     [GuardianController::class, 'detach'])
    ->withoutScopedBindings();

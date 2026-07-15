<?php

use App\Http\Controllers\PsychomotorSkillController;
use Illuminate\Support\Facades\Route;

Route::prefix('psychomotor-skills')->group(function () {
    Route::post('/', [PsychomotorSkillController::class, 'store']);
});

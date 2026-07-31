<?php

use App\Http\Controllers\KeyStageCoordinatorCommentController;
use Illuminate\Support\Facades\Route;

Route::prefix('key-stage-coordinator')->group(function () {
    Route::get('/students', [KeyStageCoordinatorCommentController::class, 'index']);
    Route::patch('/students/{studentCurriculum:uuid}/comment', [KeyStageCoordinatorCommentController::class, 'update']);
});

<?php

use App\Http\Controllers\HeadOfSchoolCommentController;
use Illuminate\Support\Facades\Route;

Route::prefix('head-of-school')->group(function () {
    Route::get('/students', [HeadOfSchoolCommentController::class, 'index']);
    Route::get('/students/{studentCurriculum:uuid}/result', [HeadOfSchoolCommentController::class, 'show']);
    Route::patch('/students/{studentCurriculum:uuid}/comment', [HeadOfSchoolCommentController::class, 'update']);
});

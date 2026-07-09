<?php

use App\Http\Controllers\FormTeacherCommentController;
use Illuminate\Support\Facades\Route;

Route::prefix('form-teacher')->group(function () {
    Route::get('/students', [FormTeacherCommentController::class, 'index']);
    Route::patch('/students/{studentCurriculum:uuid}/comment', [FormTeacherCommentController::class, 'update']);
});

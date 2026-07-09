<?php

use App\Http\Controllers\TeacherAssignmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('teacher-assignments')->group(function () {
    Route::get('/', [TeacherAssignmentController::class, 'index']);
    Route::get('/teachers', [TeacherAssignmentController::class, 'teachers']);
    Route::post('/', [TeacherAssignmentController::class, 'store']);
    Route::delete('/{classLevelArmTeacher:uuid}', [TeacherAssignmentController::class, 'destroy']);
});

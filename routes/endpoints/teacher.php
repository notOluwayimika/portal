<?php

use App\Http\Controllers\TeacherController;
use Illuminate\Support\Facades\Route;

Route::prefix('teachers')->group(function () {
    Route::get('/',                                                      [TeacherController::class, 'index']);
    Route::post('/',                                                     [TeacherController::class, 'store']);
    Route::get('/resources',                                             [TeacherController::class, 'resources']);
    Route::get('/{teacher:uuid}',                                        [TeacherController::class, 'show']);
    Route::patch('/{teacher:uuid}',                                      [TeacherController::class, 'update']);
    Route::patch('/{teacher:uuid}/status',                               [TeacherController::class, 'updateStatus']);
    Route::delete('/{teacher:uuid}',                                     [TeacherController::class, 'destroy']);
    Route::get('/{teacher:uuid}/subjects',                               [TeacherController::class, 'subjects']);
    Route::post('/{teacher:uuid}/subjects',                              [TeacherController::class, 'assignSubject']);
    Route::delete('/{teacher:uuid}/subjects/{assignment:uuid}',          [TeacherController::class, 'removeSubject']);
});

Route::get('/curricula/{curriculum:uuid}/subjects', [TeacherController::class, 'curriculumSubjects']);

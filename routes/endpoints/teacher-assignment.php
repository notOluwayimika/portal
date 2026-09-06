<?php

use App\Http\Controllers\TeacherAssignmentController;
use App\Support\AdminAreaGate;
use Illuminate\Support\Facades\Route;

Route::prefix('teacher-assignments')->group(function () {
    // The parent group carries AdminAreaGate::READ, so the two GETs are reachable by the read-only
    // admin seat; each write re-narrows to AdminAreaGate::ACCESS and is unchanged in effect.
    Route::get('/', [TeacherAssignmentController::class, 'index']);
    Route::get('/teachers', [TeacherAssignmentController::class, 'teachers']);
    Route::post('/', [TeacherAssignmentController::class, 'store'])->middleware(AdminAreaGate::ACCESS);
    Route::delete('/{classLevelArmTeacher:uuid}', [TeacherAssignmentController::class, 'destroy'])->middleware(AdminAreaGate::ACCESS);
});

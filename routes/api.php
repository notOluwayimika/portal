<?php

use App\Http\Controllers\Api\AuthenticationController;
use App\Http\Controllers\ClassLevelArmController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\CurriculumSubjectController;
use App\Http\Controllers\ExamTypeController;
use App\Http\Controllers\GradeBoundaryController;
use App\Http\Controllers\MarkingComponentController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StudentCurriculumController;
use App\Http\Controllers\StudentSubjectController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TermController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authentication
Route::post('/login', [AuthenticationController::class, 'login']);
Route::post('/register', [AuthenticationController::class, 'register']);

// get sessions
Route::get('/sessions', [SessionController::class, 'index']);
Route::get('/sessions/{session:uuid}/terms', [TermController::class, 'index']);

// get class level arm structure
Route::get('/class-structure', [ClassLevelArmController::class, 'index']);
// get exam types
Route::get('/exam-types', [ExamTypeController::class, 'index']);
// get subjects
Route::get('/subjects', [SubjectController::class, 'index']);
// get grade boundaries
Route::get('/grade-boundaries/{examType:uuid}', [GradeBoundaryController::class, 'index']);
// get curricula
Route::get('/curricula', [CurriculumController::class, 'index']);
Route::get('/curricula/active', [CurriculumController::class, 'active']);
Route::get('/curricula/{curriculum:uuid}', [CurriculumController::class, 'show']);
Route::middleware(['auth:sanctum', 'tenant', 'role:admin|head_of_school'])->group(function () {
    Route::get('/user', [AuthenticationController::class, 'user']);

    // Dashboard analytics API
    require __DIR__ . '/endpoints/dashboard.php';

    // Public/Shared data (now protected)
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::get('/class-structure', [ClassLevelArmController::class, 'index']);
    Route::get('/exam-types', [ExamTypeController::class, 'index']);
    Route::get('/subjects', [SubjectController::class, 'index']);
    Route::get('/grade-boundaries/{examType:uuid}', [GradeBoundaryController::class, 'index']);
    Route::get('/curricula', [CurriculumController::class, 'index']);
    Route::get('/curricula/{curriculum:uuid}', [CurriculumController::class, 'show']);

    // protected session routes
    Route::post('/sessions', [SessionController::class, 'store']);
    Route::put('/sessions/{session:uuid}', [SessionController::class, 'update']);
    Route::delete('/sessions/{session:uuid}', [SessionController::class, 'destroy']);
    Route::post('/sessions/{session:uuid}/current', [SessionController::class, 'setCurrent']);

    // protected class structure (level and arms)
    Route::post('/class-structure', [ClassLevelArmController::class, 'store']);
    Route::patch('/class-structure/{classLevelArm:uuid}', [ClassLevelArmController::class, 'update']);
    Route::delete('/class-structure/{classLevelArm:uuid}', [ClassLevelArmController::class, 'destroy']);
    Route::post('/class-structure/toggle', [ClassLevelArmController::class, 'toggle']);
    Route::post('/class-structure/levels', [ClassLevelArmController::class, 'storeLevel']);
    Route::put('/class-structure/levels/{classLevel:uuid}', [ClassLevelArmController::class, 'updateLevel']);
    Route::delete('/class-structure/levels/{classLevel:uuid}', [ClassLevelArmController::class, 'destroyLevel']);
    Route::post('/class-structure/arms', [ClassLevelArmController::class, 'storeArm']);
    Route::put('/class-structure/arms/{arm:uuid}', [ClassLevelArmController::class, 'updateArm']);
    Route::delete('/class-structure/arms/{arm:uuid}', [ClassLevelArmController::class, 'destroyArm']);
    Route::post('/class-structure/streams', [ClassLevelArmController::class, 'storeStream']);
    Route::put('/class-structure/streams/{stream:uuid}', [ClassLevelArmController::class, 'updateStream']);
    Route::delete('/class-structure/streams/{stream:uuid}', [ClassLevelArmController::class, 'destroyStream']);

    // protected exam types routes
    Route::post('/exam-types', [ExamTypeController::class, 'store']);
    Route::put('/exam-types/{examType:uuid}', [ExamTypeController::class, 'update']);
    Route::delete('/exam-types/{examType:uuid}', [ExamTypeController::class, 'destroy']);

    // protected subject routes
    Route::post('/subjects', [SubjectController::class, 'store']);
    Route::put('/subjects/{subject:uuid}', [SubjectController::class, 'update']);
    Route::delete('/subjects/{subject:uuid}', [SubjectController::class, 'destroy']);

    // protected grade boundary routes
    Route::post('/grade-boundaries', [GradeBoundaryController::class, 'store']);
    Route::put('/grade-boundaries/{gradeBoundary:uuid}', [GradeBoundaryController::class, 'update']);
    Route::delete('/grade-boundaries/{gradeBoundary:uuid}', [GradeBoundaryController::class, 'destroy']);

    // protected curricula routes
    Route::post('/curricula', [CurriculumController::class, 'store']);
    Route::post('/curricula/{curriculum:uuid}/subjects', [CurriculumController::class, 'assignSubject']);
    Route::put('/curricula/{curriculum:uuid}', [CurriculumController::class, 'update']);
    Route::patch('/curricula/{curriculum:uuid}/subjects/reorder', [CurriculumController::class, 'reorder']);
    Route::delete('/curricula/{curriculum:uuid}', [CurriculumController::class, 'destroy']);

    // protected curriculum subjects routes

    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/approve', [CurriculumSubjectController::class, 'approve']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/reject', [CurriculumSubjectController::class, 'reject']);
    Route::patch('/curriculum-subjects/{curriculumSubject:uuid}', [CurriculumSubjectController::class, 'update']);

    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/teachers', [CurriculumSubjectController::class, 'assignTeacher']);
    Route::delete('/curriculum-subjects/{curriculumSubject:uuid}/teachers/{teacher:uuid}', [CurriculumSubjectController::class, 'unassignTeacher'])->withoutScopedBindings();
    Route::delete('/curriculum-subjects/{curriculumSubject:uuid}', [CurriculumSubjectController::class, 'destroy']);
    // get setup data
    Route::get('/setup-data', [SetupController::class, 'index']);

    // protected term routes
    Route::post('/sessions/{session:uuid}/terms', [TermController::class, 'store']);
    Route::put('/sessions/{session:uuid}/terms/{term:uuid}', [TermController::class, 'update'])->withoutScopedBindings();
    Route::delete('/sessions/{session:uuid}/terms/{term:uuid}', [TermController::class, 'destroy'])->withoutScopedBindings();

    // protected marking components
    Route::put('/marking-components/{markingComponent}', [MarkingComponentController::class, 'update']);
    Route::delete('/marking-components/{markingComponent}', [MarkingComponentController::class, 'destroy']);

    // student curricula
    Route::post('/students/{student:uuid}/curricula/promote', [StudentCurriculumController::class, 'promote']);
    Route::post('/students/{student:uuid}/curricula', [StudentCurriculumController::class, 'register']);
    Route::patch('/student-curricula/{studentCurriculum:uuid}', [StudentCurriculumController::class, 'updateStatus']);

    // student subject management
    // withoutScopedBindings: prevents Laravel auto-scoping {studentCurriculum} to {student}
    // (it would look for $student->studentCurriculums() but the relation is studentCurricula()).
    Route::prefix('students/{student:uuid}/enrollments/{studentCurriculum:uuid}')
        ->withoutScopedBindings()
        ->group(function () {
            Route::get('subjects',                                          [StudentSubjectController::class, 'index']);
            Route::post('subjects',                                         [StudentSubjectController::class, 'store']);
            Route::patch('subjects/{studentSubject:uuid}/drop',             [StudentSubjectController::class, 'drop']);
            Route::patch('subjects/{studentSubject:uuid}/restore',          [StudentSubjectController::class, 'restore']);
            Route::get('subjects/history',                                  [StudentSubjectController::class, 'history']);
            Route::patch('end',                                             [StudentCurriculumController::class, 'unenroll']);
        });

    // curriculum subject archival
    Route::patch('/curriculum-subjects/{curriculumSubject:uuid}/archive',   [CurriculumSubjectController::class, 'archive']);
    Route::patch('/curriculum-subjects/{curriculumSubject:uuid}/unarchive', [CurriculumSubjectController::class, 'unarchive']);

    Route::post('/logout', [AuthenticationController::class, 'logout']);

    require __DIR__ . '/endpoints/student.php';
    require __DIR__ . '/endpoints/teacher.php';
    require __DIR__ . '/endpoints/guardian.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'role:admin|head_of_school|teacher|super_admin'])->group(function () {
    // Activity log module (read-only audit feed). Fine-grained access is
    // gated per-endpoint by activity_log.* permissions.
    require __DIR__ . '/endpoints/activity-log.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'role:admin|head_of_school|teacher'])->group(function () {
    // assign score and marking component for teachers;
    Route::get('/teachers/{teacher:uuid}/subjects', [TeacherController::class, 'subjects']);
    Route::get('/teachers/{teacher:uuid}', [TeacherController::class, 'show']);
    // protected marking components
    Route::put('/marking-components/{markingComponent}', [MarkingComponentController::class, 'update']);
    Route::delete('/marking-components/{markingComponent}', [MarkingComponentController::class, 'destroy']);
    // protected curriculum subject
    Route::get('/curriculum-subjects/{curriculumSubject:uuid}/result-status', [CurriculumSubjectController::class, 'getResultStatus']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/marking-components', [CurriculumSubjectController::class, 'assignMarkingComponent']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/scores', [CurriculumSubjectController::class, 'assignScore']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/submit', [CurriculumSubjectController::class, 'submit']);
});

<?php

use App\Http\Controllers\Api\AuthenticationController;
use App\Http\Controllers\ClassLevelArmController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\CurriculumSubjectController;
use App\Http\Controllers\ExamTypeController;
use App\Http\Controllers\GradeBoundaryController;
use App\Http\Controllers\GradingSchemeController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\HeadOfSchoolController;
use App\Http\Controllers\MarkingComponentController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\PrincipalApprovalController;
use App\Http\Controllers\ScholarshipController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SportHouseController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentCurriculumController;
use App\Http\Controllers\StudentSubjectController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SubjectResultStatusController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TeacherSchoolAccessController;
use App\Http\Controllers\TermController;
use Illuminate\Support\Facades\Route;

// Authentication (public)
Route::post('/login', [AuthenticationController::class, 'login']);

// Switch active school (session + token); accessible to any authenticated user
Route::middleware('auth:sanctum')->post('/switch-school', [AuthenticationController::class, 'switchSchool']);

// NOTE: the shared read endpoints (sessions, class-structure, exam-types,
// subjects, grade-boundaries, curricula, sport-houses, scholarships, …) used to
// be declared here without auth and re-declared inside the auth group below;
// Laravel matched the first (unauthenticated) declaration, leaking them. They
// now live only inside the authenticated groups.
Route::middleware(['auth:sanctum', 'tenant', 'role:admin|head_of_school|form_teacher'])->group(function () {
    Route::get('/user', [AuthenticationController::class, 'user']);

    // Dashboard analytics API
    require __DIR__.'/endpoints/dashboard.php';

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

    // protected sport house routes
    Route::get('/sport-houses', [SportHouseController::class, 'index']);
    Route::post('/sport-houses', [SportHouseController::class, 'store']);
    Route::put('/sport-houses/{sportHouse:uuid}', [SportHouseController::class, 'update']);
    Route::delete('/sport-houses/{sportHouse:uuid}', [SportHouseController::class, 'destroy']);

    // protected scholarship routes
    Route::get('/scholarships', [ScholarshipController::class, 'index']);
    Route::post('/scholarships', [ScholarshipController::class, 'store']);
    Route::put('/scholarships/{scholarship:uuid}', [ScholarshipController::class, 'update']);
    Route::delete('/scholarships/{scholarship:uuid}', [ScholarshipController::class, 'destroy']);

    // protected subject routes
    Route::post('/subjects', [SubjectController::class, 'store']);
    Route::put('/subjects/{subject:uuid}', [SubjectController::class, 'update']);
    Route::delete('/subjects/{subject:uuid}', [SubjectController::class, 'destroy']);

    // protected grade boundary routes
    Route::post('/grade-boundaries', [GradeBoundaryController::class, 'store']);
    Route::put('/grade-boundaries/{gradeBoundary:uuid}', [GradeBoundaryController::class, 'update']);
    Route::delete('/grade-boundaries/{gradeBoundary:uuid}', [GradeBoundaryController::class, 'destroy']);
    Route::get('/grading-schemes', [GradingSchemeController::class, 'index']);
    Route::post('/grading-schemes', [GradingSchemeController::class, 'store']);
    Route::put('/grading-schemes/{gradingScheme:uuid}', [GradingSchemeController::class, 'update']);

    // protected curricula routes
    Route::post('/curricula', [CurriculumController::class, 'store']);
    Route::post('/curricula/{curriculum:uuid}/subjects', [CurriculumController::class, 'assignSubject']);
    Route::put('/curricula/{curriculum:uuid}', [CurriculumController::class, 'update']);
    Route::patch('/curricula/{curriculum:uuid}/subjects/reorder', [CurriculumController::class, 'reorder']);
    Route::delete('/curricula/{curriculum:uuid}', [CurriculumController::class, 'destroy']);

    // protected curriculum subjects routes
    Route::get('/curriculum-subjects/{curriculumSubject:uuid}', [CurriculumSubjectController::class, 'show']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/approve', [CurriculumSubjectController::class, 'approve']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/reject', [CurriculumSubjectController::class, 'reject']);
    Route::patch('/curriculum-subjects/{curriculumSubject:uuid}', [CurriculumSubjectController::class, 'update']);
    Route::put('/curriculum-subjects/{curriculumSubject:uuid}/categorical-results/{student:uuid}', [CurriculumSubjectController::class, 'assignCategoricalResult'])
        ->withoutScopedBindings();

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
            Route::get('subjects', [StudentSubjectController::class, 'index']);
            Route::post('subjects', [StudentSubjectController::class, 'store']);
            Route::patch('subjects/{studentSubject:uuid}/drop', [StudentSubjectController::class, 'drop'])->withoutScopedBindings();
            Route::patch('subjects/{studentSubject:uuid}/restore', [StudentSubjectController::class, 'restore']);
            Route::get('subjects/history', [StudentSubjectController::class, 'history']);
            Route::patch('end', [StudentCurriculumController::class, 'unenroll']);
        });

    // curriculum subject archival
    Route::patch('/curriculum-subjects/{curriculumSubject:uuid}/archive', [CurriculumSubjectController::class, 'archive']);
    Route::patch('/curriculum-subjects/{curriculumSubject:uuid}/unarchive', [CurriculumSubjectController::class, 'unarchive']);

    // protected marking components
    Route::get('/marking-components', [MarkingComponentController::class, 'index']);
    Route::post('/marking-components', [MarkingComponentController::class, 'sync']);
    Route::put('/marking-components/{markingComponent}', [MarkingComponentController::class, 'update']);
    Route::delete('/marking-components/{markingComponent}', [MarkingComponentController::class, 'destroy']);

    // subject result status
    Route::get('/subject-result-statuses', [SubjectResultStatusController::class, 'index']);

    Route::post('/logout', [AuthenticationController::class, 'logout']);

    require __DIR__.'/endpoints/student.php';
    require __DIR__.'/endpoints/teacher.php';
    require __DIR__.'/endpoints/guardian.php';
    require __DIR__.'/endpoints/head-of-school.php';
});
Route::middleware(['auth:sanctum', 'tenant', 'role:admin'])->group(function () {
    // Head of Schools
    Route::get('/heads-of-schools', [HeadOfSchoolController::class, 'index']);
    Route::post('/heads-of-schools', [HeadOfSchoolController::class, 'store']);
    Route::delete('/heads-of-schools/{teacher:uuid}', [HeadOfSchoolController::class, 'destroy']);

    Route::post('/guardians/{guardian:uuid}/password', [GuardianController::class, 'setPassword']);

    // Teacher multi-school access (school_user pivot); admin can only grant
    // schools they themselves can access.
    Route::put('/teachers/{teacher:uuid}/schools', [TeacherSchoolAccessController::class, 'sync']);

    // CCM -> non-CCM curriculum migration
    Route::post('/curricula/{curriculum:uuid}/move-from-ccm', [CurriculumController::class, 'moveFromCcm']);

    // Mirror an active curriculum into a past (completed) term for retroactive entry
    Route::post('/curricula/{curriculum:uuid}/backfill-term', [CurriculumController::class, 'backfillTerm']);

    // Teacher role assignments (boarding parent / form teacher / head of school)
    require __DIR__.'/endpoints/teacher-assignment.php';

    // Notices (admin CRUD)
    require __DIR__.'/endpoints/notice.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'role:admin|principal'])->group(function () {
    Route::patch('/class-levels/{classLevel:uuid}/principal-approval', [PrincipalApprovalController::class, 'classLevel']);
    Route::patch('/class-level-arms/{classLevelArm:uuid}/principal-approval', [PrincipalApprovalController::class, 'classLevelArm']);
});

// Finance (walking skeleton) — manual invoice/payment entry points. Finance-
// specific roles (accounts_officer, …) + maker-checker are Ph2/Ph3; gated on admin
// for now.
Route::middleware(['auth:sanctum', 'tenant', 'role:admin|super_admin'])->group(function () {
    require __DIR__.'/endpoints/finance.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'role:admin|head_of_school|teacher|super_admin'])->group(function () {
    // Shared read data (previously leaked as unauthenticated public routes).
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::get('/sessions/{session:uuid}/terms', [TermController::class, 'index']);
    Route::get('/class-structure', [ClassLevelArmController::class, 'index']);
    Route::get('/class-level-arms', [ClassLevelArmController::class, 'list']);
    Route::get('/exam-types', [ExamTypeController::class, 'index']);
    Route::get('/subjects', [SubjectController::class, 'index']);
    Route::get('/grade-boundaries/{examType:uuid}', [GradeBoundaryController::class, 'index']);
    Route::get('/curricula', [CurriculumController::class, 'index']);
    Route::get('/curricula/active', [CurriculumController::class, 'active']);
    Route::get('/curricula/{curriculum:uuid}', [CurriculumController::class, 'show']);

    // Activity log module (read-only audit feed). Fine-grained access is
    // gated per-endpoint by activity_log.* permissions.
    require __DIR__.'/endpoints/activity-log.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'role:admin|head_of_school|principal'])->group(function () {
    // Enrollments failing the result-readiness check (incomplete results)
    Route::get('/results/incomplete', [CurriculumController::class, 'incompleteResults']);

    // Read-only subject listing; feeds the pending-results page, which
    // principals can also see.
    Route::get('/curriculum-subjects', [CurriculumSubjectController::class, 'index']);

    // Broadsheets + outstanding comments (read-only reports)
    require __DIR__.'/endpoints/broadsheet.php';
    require __DIR__.'/endpoints/outstanding-comments.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'role:admin|head_of_school|teacher'])->group(function () {
    Route::get('/marking-components/overlapping/{curriculum:uuid}', [MarkingComponentController::class, 'getOverlapping']);
    // comment on student subject
    Route::post('/student-subjects/{studentSubject:uuid}/comment', [StudentSubjectController::class, 'storeComment']);

    // assign score and marking component for teachers;
    Route::get('/teachers/{teacher:uuid}/subjects', [TeacherController::class, 'subjects']);
    Route::get('/teachers/{teacher:uuid}', [TeacherController::class, 'show']);

    // protected curriculum subject
    Route::get('/curriculum-subjects/{curriculumSubject:uuid}/result-status', [CurriculumSubjectController::class, 'getResultStatus']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/marking-components', [CurriculumSubjectController::class, 'assignMarkingComponent']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/scores', [CurriculumSubjectController::class, 'assignScore']);
    Route::post('/curriculum-subjects/{curriculumSubject:uuid}/submit', [CurriculumSubjectController::class, 'submit']);
});

Route::middleware(['auth:sanctum', 'tenant', 'role:admin|head_of_school|teacher|guardian'])->group(function () {
    // protected guardian routes
    Route::get('/guardians/{guardian:uuid}/students', [GuardianController::class, 'students']);
    Route::get('/students/{student:uuid}/result-status', [StudentController::class, 'activeResultStatus']);
    Route::get('/students/{student:uuid}/curriculum/{curriculum:uuid}/result-status', [CurriculumController::class, 'activeResultStatus'])->withoutScopedBindings();
});

Route::middleware(['auth:sanctum', 'tenant', 'role:admin|head_of_school|guardian'])->group(function () {
    Route::get('/curriculum-subjects/{curriculumSubject:uuid}/year-average', [CurriculumSubjectController::class, 'getYearAverage']);
    Route::get('/curriculum-subjects/{curriculumSubject:uuid}/teachers', [CurriculumSubjectController::class, 'getTeachers']);
    Route::get('/student-curricula/{studentCurriculum:uuid}', [StudentCurriculumController::class, 'getTeacherDetails']);
    Route::get('/student-curricula/{studentCurriculum:uuid}/curriculum-subject/{curriculumSubject:uuid}', [StudentCurriculumController::class, 'getScoresWithMarkingComponents'])->withoutScopedBindings();
});

Route::middleware(['auth:sanctum', 'tenant', 'role:guardian'])->group(function () {
    Route::get('/guardian/notices', [NoticeController::class, 'forGuardian']);
});

// Form teachers may record assessments when the school has no boarding
// parents (enforced server-side in ResolvesAssessmentAccess).
Route::middleware(['auth:sanctum', 'tenant', 'role:boarding_parent|form_teacher'])->group(function () {
    require __DIR__.'/endpoints/behavioral-assessment.php';
    require __DIR__.'/endpoints/psychomotor-skill.php';
});

Route::middleware(['auth:sanctum', 'tenant', 'role:form_teacher'])->group(function () {
    require __DIR__.'/endpoints/form-teacher.php';
});

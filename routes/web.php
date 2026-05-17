<?php

use App\Enums\StudentStatusEnum;
use App\Enums\TeacherStatusEnum;
use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StudentController;
use App\Http\Resources\CurriculumResource;
use App\Http\Resources\CurriculumSubjectResource;
use App\Http\Resources\GradeBoundaryResource;
use App\Http\Resources\GuardianResource;
use App\Http\Resources\StudentCurriculumResource;
use App\Http\Resources\StudentResource;
use App\Http\Resources\SubjectResultStatusResource;
use App\Http\Resources\TeacherResource;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\GradeBoundary;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\SubjectResultStatus;
use App\Models\Teacher;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('auth/login', [
        'canResetPassword' => Features::enabled(Features::resetPasswords()),
        'canRegister' => Features::enabled(Features::registration()),
        'status' => session('status'),
    ]);
})->middleware('guest')->name('home');

Route::middleware(['auth', 'tenant', 'role:admin|head_of_school'])->group(function () {

    Route::inertia('school-setup', 'admin/SchoolSetup')->name('school.setup');
    Route::inertia('setup', 'admin/school-setup')->name('setup');
    Route::get('setup/curricula/{curriculum:uuid}', function (Curriculum $curriculum) {
        return Inertia::render('admin/curriculum/show', [
            'curriculum' => new CurriculumResource($curriculum),
        ]);
    })->name('setup.curricula.show');

    // Students
    Route::get('students', function () {
        return Inertia::render('admin/students/index', [
            'student_statuses' => StudentStatusEnum::options()
        ]);
    })->name('students.index');

    Route::get('students/{student:uuid}', function (Student $student) {
        $student->load([
            'photoFile',
            'currentCurriculum.curriculum.classLevelArm.classLevel',
            'currentCurriculum.curriculum.classLevelArm.arm',
            'currentCurriculum.curriculum.classLevelArm.stream',
            'currentCurriculum.curriculum.term',
            'guardians.user',
            'guardians.photoFile',
            'studentCurricula.curriculum.classLevelArm.classLevel',
            'studentCurricula.curriculum.classLevelArm.arm',
            'studentCurricula.curriculum.term',
        ]);

        return Inertia::render('admin/students/show', [
            'student' => new StudentResource($student),
            'student_statuses' => StudentStatusEnum::options(),
        ]);
    })->name('students.show');

    // Teachers
    Route::get('teachers', function () {
        return Inertia::render('admin/teachers/index', [
            'teacher_statuses' => TeacherStatusEnum::options(),
        ]);
    })->name('teachers.index');


    // Guardian index
    Route::get('guardians', function () {
        return Inertia::render('admin/guardians/index', [
            'guardian_statuses' => \App\Enums\GuardianStatusEnum::options(),
        ]);
    })->name('guardians.index');

    // Bulk guardian import (must come before /{guardian:uuid} so it isn't shadowed).
    Route::get('guardians/import', function () {
        return Inertia::render('admin/guardians/import');
    })->name('guardians.import');

    // Activity log (read-only audit feed). Per-action access is gated by
    // activity_log.* permissions in the API layer.
    Route::get('activity-logs', function () {
        return Inertia::render('admin/activity-logs/index');
    })->name('activity-logs.index');

    Route::get('activity-logs/{id}', function (string $id) {
        return Inertia::render('admin/activity-logs/index', ['initialActivityId' => $id]);
    })->whereNumber('id')->name('activity-logs.show');

    // Guardian profile
    Route::get('guardians/{guardian:uuid}', function (Guardian $guardian) {
        $guardian->load([
            'user',
            'photoFile',
            'students.photoFile',
            'students.currentCurriculum.curriculum.classLevelArm.classLevel',
            'students.currentCurriculum.curriculum.classLevelArm.arm',
            'students.currentCurriculum.curriculum.term',
        ]);
        return Inertia::render('admin/guardians/show', [
            'guardian' => new GuardianResource($guardian),
        ]);
    })->name('guardians.show');

    // Guardian audit history
    Route::get('guardians/{guardian:uuid}/audit', function (Guardian $guardian) {
        $guardian->load(['user']);
        return Inertia::render('admin/guardians/audit', [
            'guardian' => new GuardianResource($guardian),
        ]);
    })->name('guardians.audit');


    Route::post('students', [App\Http\Controllers\StudentController::class, 'store']);
    Route::put('students/{student:uuid}', [App\Http\Controllers\StudentController::class, 'update']);



    // Review results
    Route::get('setup/review/results', function () {
        $subjectResults = SubjectResultStatus::with(['curriculumSubject.teacherAssignments.teacher', 'curriculumSubject.subject', 'curriculumSubject.curriculum.academicSession', 'curriculumSubject.curriculum.examType', 'curriculumSubject.curriculum.term', 'curriculumSubject.curriculum.classLevelArm', 'updatedBy'])->where('status', 'submitted')->get();
        return Inertia::render('admin/review/index', [
            'subjectResults' => SubjectResultStatusResource::collection($subjectResults)
        ]);
    })->name('setup.review.results');

    // student curricula
    Route::get('setup/student-curricula/{student:uuid}', function (Student $student) {
        $student->load(['studentCurricula.curriculum.examType', 'studentCurricula.curriculum.classLevelArm.classLevel', 'studentCurricula.curriculum.academicSession', 'studentCurricula.promotedTo']);
        return Inertia::render('admin/student-curricula/index', [
            'student' => new StudentResource($student),
        ]);
    })->name('setup.studentCurricula.index');

});

Route::middleware(['auth', 'tenant', 'role:admin|head_of_school|teacher|guardian'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
    Route::post('dashboard/refresh', [DashboardController::class, 'refresh'])->middleware('throttle:1,1')->name('dashboard.refresh');
    Route::get('dashboard/onboarding', [DashboardController::class, 'onboardingState'])->name('dashboard.onboarding');

    Route::get('setup/teacher/{teacher:uuid}', function (Teacher $teacher) {
        return Inertia::render('teacher/show', [
            'teacher' => new TeacherResource($teacher),
        ]);
    })->name('setup.teachers.show');

    Route::get('setup/curriculum-subject/{curriculumSubject:uuid}', function (CurriculumSubject $curriculumSubject) {
        $curriculumSubject->load(['curriculum', 'subject', 'markingComponents', 'scores.student', 'scores.markingComponent', 'studentAssignments.studentCurriculum.student', 'resultStatus']);

        return Inertia::render('curriculum-subject/show', [
            'curriculumSubject' => new CurriculumSubjectResource($curriculumSubject),
        ]);
    })->name('setup.curriculumSubjects.show');


});

Route::middleware(['auth', 'tenant', 'role:guardian|admin|head_of_school'])->group(function () {
    Route::get('students/{student:uuid}/results/active', function (Student $student) {
        $studentCurricula = StudentCurriculum::with(['curriculum.examType.gradeBoundaries', 'studentSubjects.curriculumSubject.studentResults.student', 'studentSubjects.curriculumSubject.resultStatus', 'studentSubjects.curriculumSubject.subject'])->where('student_id', $student->id)->where('status', 'active')->get();

        $defaultGradeBoundaries = GradeBoundary::where('exam_type_id', null)->get();
        // return response()->json([
        //     'student' => new StudentResource($student),
        //     'studentCurricula' => StudentCurriculumResource::collection($studentCurricula),
        //     'defaultGradeBoundaries' => GradeBoundaryResource::collection($defaultGradeBoundaries)
        // ]);
        return Inertia::render('student/results/active', [
            'student' => new StudentResource($student),
            'studentCurricula' => StudentCurriculumResource::collection($studentCurricula),
            'defaultGradeBoundaries' => GradeBoundaryResource::collection($defaultGradeBoundaries)
        ]);
    })->name('admin.dashboard');
    Route::get('students/{student:uuid}/results/{studentCurriculum:uuid}', function (Student $student, StudentCurriculum $studentCurriculum) {
        $studentCurricula = StudentCurriculum::with(['curriculum.examType.gradeBoundaries', 'studentSubjects.curriculumSubject.studentResults.student', 'studentSubjects.curriculumSubject.resultStatus', 'studentSubjects.curriculumSubject.subject'])->where('student_id', $student->id)->where('id', $studentCurriculum->id)->get();

        $defaultGradeBoundaries = GradeBoundary::where('exam_type_id', null)->get();
        // return response()->json([
        //     'student' => new StudentResource($student),
        //     'studentCurricula' => StudentCurriculumResource::collection($studentCurricula),
        //     'defaultGradeBoundaries' => GradeBoundaryResource::collection($defaultGradeBoundaries)
        // ]);
        return Inertia::render('student/results/active', [
            'student' => new StudentResource($student),
            'studentCurricula' => StudentCurriculumResource::collection($studentCurricula),
            'defaultGradeBoundaries' => GradeBoundaryResource::collection($defaultGradeBoundaries)
        ]);
    })->name('admin.dashboard')->withoutScopedBindings();
});

Route::middleware(['auth', 'tenant', 'role:guardian'])->group(function () {
    Route::inertia('parent/dashboard', 'parent/dashboard')->name('parent.dashboard');
});


require __DIR__ . '/settings.php';

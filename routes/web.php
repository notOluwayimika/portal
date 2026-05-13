<?php

use App\Enums\StudentStatusEnum;
use App\Enums\TeacherStatusEnum;
use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StudentController;
use App\Http\Resources\CurriculumResource;
use App\Http\Resources\CurriculumSubjectResource;
use App\Http\Resources\TeacherResource;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Teacher;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('auth/login', [
        'canResetPassword' => Features::enabled(Features::resetPasswords()),
        'canRegister'      => Features::enabled(Features::registration()),
        'status'           => session('status'),
    ]);
})->middleware('guest')->name('home');

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
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

    // Teachers
    Route::get('teachers', function () {
        return Inertia::render('admin/teachers/index', [
            'teacher_statuses' => TeacherStatusEnum::options(),
        ]);
    })->name('teachers.index');


    Route::post('students', [App\Http\Controllers\StudentController::class, 'store']);
    Route::put('students/{student:uuid}', [App\Http\Controllers\StudentController::class, 'update']);
});

Route::middleware(['auth'])->group(function () {
    Route::get('setup/teacher/{teacher:uuid}', function (Teacher $teacher) {
        return Inertia::render('teacher/show', [
            'teacher' => new TeacherResource($teacher),
        ]);
    })->name('setup.teachers.show');

    // Curriculum Subject
    Route::get('setup/curriculum-subject/{curriculumSubject:uuid}', function (CurriculumSubject $curriculumSubject) {
        $curriculumSubject->load(['curriculum', 'subject', 'markingComponents', 'scores.student', 'scores.markingComponent', 'studentAssignments.studentCurriculum.student']);
        return Inertia::render('curriculum-subject/show', [
            'curriculumSubject' => new CurriculumSubjectResource($curriculumSubject),
        ]);
    })->name('setup.curriculumSubjects.show');

});


require __DIR__ . '/settings.php';

<?php

use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\SessionController;
use App\Http\Resources\CurriculumResource;
use App\Http\Resources\TeacherResource;
use App\Models\Curriculum;
use App\Models\Teacher;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::inertia('school-setup', 'admin/SchoolSetup')->name('school.setup');
    Route::inertia('setup', 'admin/school-setup')->name('setup');
    Route::get('setup/curricula/{curriculum:uuid}', function (Curriculum $curriculum) {
        return Inertia::render('admin/curriculum/show', [
            'curriculum' => new CurriculumResource($curriculum),
        ]);
    })->name('setup.curricula.show');
});

Route::middleware(['auth'])->group(function () {
    Route::get('setup/teacher/{teacher:uuid}', function (Teacher $teacher) {
        return Inertia::render('teacher/show', [
            'teacher' => new TeacherResource($teacher),
        ]);
    })->name('setup.teachers.show');
});


require __DIR__ . '/settings.php';

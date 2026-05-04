<?php

use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\SessionController;
use App\Http\Resources\CurriculumResource;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::inertia('school-setup', 'admin/SchoolSetup')->name('school.setup');
    Route::inertia('setup', 'admin/school-setup')->name('setup');
    Route::get('setup/curricula/{curriculum:uuid}', function (\App\Models\Curriculum $curriculum) {
        return Inertia::render('admin/curriculum/show', [
            'curriculum' => new CurriculumResource($curriculum),
        ]);
    })->name('setup.curricula.show');

    // Students
    Route::get('students', function () {
        return Inertia::render('admin/students/index');
    })->name('students.index');



    Route::post('students', [App\Http\Controllers\StudentController::class, 'store']);
    Route::put('students/{student:uuid}', [App\Http\Controllers\StudentController::class, 'update']);
});


require __DIR__ . '/settings.php';

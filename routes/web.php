<?php

use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::inertia('school-setup', 'admin/SchoolSetup')->name('school.setup');
    Route::inertia('setup', 'admin/school-setup')->name('setup');
});


require __DIR__ . '/settings.php';

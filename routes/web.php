<?php

use App\Http\Controllers\AuthenticationController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});


Route::middleware(['guest'])->group(function () {
    Route::post('/register', [AuthenticationController::class, 'store'])->name('register.store');
    Route::get('/debug-auth', function (Request $request) {
        $attempt = Auth::attempt([
            'email' => 'mkyimika@gmail.com',
            'password' => 'password',
        ]);

        return response()->json([
            'attempt' => $attempt,
            'authenticated' => auth()->check(),
            'user' => auth()->user(),
            'session_id' => session()->getId(),
            'session_data' => session()->all(),
        ]);
    })->middleware('web');
});
require __DIR__ . '/settings.php';

<?php

use App\Http\Controllers\OutstandingCommentController;
use Illuminate\Support\Facades\Route;

Route::get('/outstanding-comments', [OutstandingCommentController::class, 'index']);

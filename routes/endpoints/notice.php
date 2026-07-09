<?php

use App\Http\Controllers\NoticeController;
use Illuminate\Support\Facades\Route;

Route::prefix('notices')->group(function () {
    Route::get('/', [NoticeController::class, 'index']);
    Route::post('/', [NoticeController::class, 'store']);
    Route::get('/categories', [NoticeController::class, 'categories']);
    Route::post('/categories', [NoticeController::class, 'storeCategory']);
    Route::delete('/categories/{noticeCategory:uuid}', [NoticeController::class, 'destroyCategory']);
    Route::get('/{notice:uuid}', [NoticeController::class, 'show']);
    Route::put('/{notice:uuid}', [NoticeController::class, 'update']);
    Route::post('/{notice:uuid}/end', [NoticeController::class, 'end']);
    Route::delete('/{notice:uuid}', [NoticeController::class, 'destroy']);
});

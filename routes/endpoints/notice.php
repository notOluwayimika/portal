<?php

use App\Http\Controllers\NoticeController;
use App\Support\AdminAreaGate;
use Illuminate\Support\Facades\Route;

Route::prefix('notices')->group(function () {
    /*
     * The parent group carries `admin_area.access|admin_area.view` (AdminAreaGate::READ), so the
     * three GETs below are reachable by the read-only admin seat. Every WRITE re-narrows to
     * AdminAreaGate::ACCESS on its own line; stacks intersect, so a write still requires exactly
     * `admin_area.access` and nobody's authority moved.
     *
     * ORDER IS UNCHANGED — `/categories` still precedes `/{notice:uuid}`, or the wildcard swallows
     * it. No declaration moved; only the writes gained a middleware call.
     */
    Route::get('/', [NoticeController::class, 'index']);
    Route::post('/', [NoticeController::class, 'store'])->middleware(AdminAreaGate::ACCESS);
    Route::get('/categories', [NoticeController::class, 'categories']);
    Route::post('/categories', [NoticeController::class, 'storeCategory'])->middleware(AdminAreaGate::ACCESS);
    Route::delete('/categories/{noticeCategory:uuid}', [NoticeController::class, 'destroyCategory'])->middleware(AdminAreaGate::ACCESS);
    Route::get('/{notice:uuid}', [NoticeController::class, 'show']);
    Route::put('/{notice:uuid}', [NoticeController::class, 'update'])->middleware(AdminAreaGate::ACCESS);
    Route::post('/{notice:uuid}/end', [NoticeController::class, 'end'])->middleware(AdminAreaGate::ACCESS);
    Route::delete('/{notice:uuid}', [NoticeController::class, 'destroy'])->middleware(AdminAreaGate::ACCESS);
});

<?php

use App\Http\Controllers\ActivityLog\ActivityLogController;
use App\Http\Controllers\ActivityLog\SavedActivityFilterController;
use Illuminate\Support\Facades\Route;

Route::prefix('activity-logs')->group(function () {
    // Static segments first so they don't collide with /{id}.
    Route::get('/filters/options', [ActivityLogController::class, 'filterOptions']);
    Route::get('/stats', [ActivityLogController::class, 'stats']);
    // GATED ON activity_log.export, NOT on the group's activity_log.view. The group
    // gate is the door to the audit feed; exporting it is a narrower authority held by
    // admin, head_of_school and internal_auditor (RbacSeeder::grantsMap $activityAdmin,
    // plus internal_auditor's explicit pair). `teacher` holds activity_log.view via
    // $activityStaff and so was admitted by the group, while the only thing standing
    // between it and a full CSV of the school's audit trail was
    // ActivityLogController@export's Authz::abilityCheck — an OBSERVE-mode check, which
    // records the would-be denial and streams the file anyway.
    //
    // Route-level, so it INTERSECTS with the group gate rather than replacing it:
    // internal_auditor holds both abilities and still reaches this endpoint (verified
    // before and after — tests/Feature/Rbac/AuthorizationWidthTest.php), which matters
    // because that seat was locked out of this feed entirely until 2026-08-31.
    Route::get('/export', [ActivityLogController::class, 'export'])
        ->middleware('permission:activity_log.export');
    Route::get('/exports/{export}', [ActivityLogController::class, 'downloadExport']);

    Route::get('/saved-filters', [SavedActivityFilterController::class, 'index']);
    Route::post('/saved-filters', [SavedActivityFilterController::class, 'store']);
    Route::delete('/saved-filters/{savedActivityFilter}', [SavedActivityFilterController::class, 'destroy']);

    Route::get('/', [ActivityLogController::class, 'index']);
    Route::get('/{id}', [ActivityLogController::class, 'show'])->whereNumber('id');
});

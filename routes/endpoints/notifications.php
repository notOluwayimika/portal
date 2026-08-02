<?php

use App\Notifications\Http\Controllers\NotificationFeedController;
use App\Notifications\Http\Controllers\NotificationQueueHealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications — in-app feed (v1)
|--------------------------------------------------------------------------
|
| NO `permission:` MIDDLEWARE ON THE FEED, and that is deliberate rather than an
| omission. Reading your own notifications is not a privilege any role grants —
| every authenticated user has a feed, and the authorization is the ownership
| filter inside the controller (this user, the active school), not a grant.
| Adding a permission here would mean a role could be configured to lock someone
| out of their own notifications.
|
| `tenant` IS required: the feed is per (user, school), and the active school is
| what the controller scopes to.
|
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('notifications')->group(function () {
    Route::get('/', [NotificationFeedController::class, 'index']);
    Route::get('/unread-count', [NotificationFeedController::class, 'unreadCount']);
    Route::post('/seen', [NotificationFeedController::class, 'markSeen']);
    Route::post('/read-all', [NotificationFeedController::class, 'markAllRead']);
    Route::patch('/{uuid}/read', [NotificationFeedController::class, 'markRead']);
});

/*
| Queue health is an OPERATOR view, not a user view — see the controller for why
| it reuses `activity_log.view_system` rather than minting a permission in v1.
*/
Route::middleware(['auth:sanctum', 'tenant', 'permission:activity_log.view_system'])
    ->get('notifications-queue-health', [NotificationQueueHealthController::class, 'show']);

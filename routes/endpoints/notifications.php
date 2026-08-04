<?php

use App\Notifications\Http\Controllers\NotificationActionController;
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

    /*
     * The tap. Route-model-bound by uuid on BOTH segments, so the action is resolved
     * against the notification named in the URL and the controller can assert they
     * belong together — a valid action uuid must not be tappable through a
     * notification the caller merely happens to receive.
     *
     * Deliberately inside the same auth+tenant group and behind NO `permission:`
     * middleware: acting on your own notification is not an ability a role grants,
     * and gating it on one would let a role be configured to lock a parent out of
     * revoking their own child's pickup. Authorization is the ownership predicate in
     * the controller — tenant + recipient, 404 on either.
     */
    Route::post(
        '/{notification:uuid}/actions/{action:uuid}',
        [NotificationActionController::class, 'store'],
    );
});

/*
| Queue health is an OPERATOR view, not a user view — see the controller for why
| it reuses `activity_log.view_system` rather than minting a permission in v1.
*/
Route::middleware(['auth:sanctum', 'tenant', 'permission:activity_log.view_system'])
    ->get('notifications-queue-health', [NotificationQueueHealthController::class, 'show']);

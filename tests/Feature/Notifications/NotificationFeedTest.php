<?php

use App\Models\User;
use App\Notifications\Enums\NotificationType;
use App\Notifications\Enums\RecipientReason;
use App\Notifications\Models\Notification;
use App\Notifications\Models\NotificationRecipient;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** A feed row for $user in $schoolId. */
function nf_row(int $schoolId, User $user): NotificationRecipient
{
    $notification = Notification::withoutEvents(fn () => Notification::forceCreate([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $schoolId,
        'type' => NotificationType::RESULT_READY->value,
        'payload' => [],
        'created_at' => now(),
    ]));

    return NotificationRecipient::withoutEvents(fn () => NotificationRecipient::forceCreate([
        'uuid' => (string) Str::orderedUuid(),
        'notification_id' => $notification->id,
        'school_id' => $schoolId,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'reason' => RecipientReason::RELATIONSHIP->value,
    ]));
}

/**
 * THE OWNERSHIP TEST. There is no policy on the feed — authorization here is the
 * WHERE clause, not a check after the fact — so this is what proves the scoping
 * actually binds. If the `notifiable_id` filter were ever dropped, every other
 * test in this file would still pass and this one would fail alone.
 */
it('never shows one user another user\'s notifications', function () {
    $school = al_makeSchool();
    $me = al_makeUser($school->id);
    $someoneElse = al_makeUser($school->id);

    nf_row($school->id, $me);
    $theirs = nf_row($school->id, $someoneElse);

    $response = $this->actingAs($me)
        ->withSession(['school_id' => $school->id])
        ->getJson('/api/notifications')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and(collect($response->json('data'))->pluck('id'))->not->toContain($theirs->uuid);
});

it('refuses to mark another user\'s notification read', function () {
    $school = al_makeSchool();
    $me = al_makeUser($school->id);
    $theirs = nf_row($school->id, al_makeUser($school->id));

    // 404, not 403: the row is not merely forbidden, it is not in this user's
    // feed at all, and saying "forbidden" would confirm it exists.
    $this->actingAs($me)
        ->withSession(['school_id' => $school->id])
        ->patchJson("/api/notifications/{$theirs->uuid}/read")
        ->assertNotFound();

    expect($theirs->fresh()->read_at)->toBeNull();
});

/**
 * `school_user` is a pivot, so one human really can be staff at one school and a
 * parent at another. A feed keyed by user alone would hand them the wrong
 * tenant's notifications.
 */
it('shows only the ACTIVE school\'s notifications for a multi-school user', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();
    $user = al_makeUser($schoolA->id);
    $user->schools()->attach([$schoolA->id, $schoolB->id]);
    // Accessible schools are cached; without the flush the `tenant` middleware
    // rejects school B and falls back, so this test would silently read school A
    // twice and pass for the wrong reason.
    $user->flushSchoolAccessCache();

    nf_row($schoolA->id, $user);
    nf_row($schoolB->id, $user);
    nf_row($schoolB->id, $user);

    // THE `Referer` IS LOAD-BEARING, and worth knowing about. statefulApi() is
    // enabled, so the SPA's /api calls carry the session — but Sanctum only
    // applies the session middleware to requests from a stateful domain, which it
    // reads from Origin/Referer. `getJson()` sends neither, so without this header
    // the request is treated as a pure token call, has no session at all, and
    // ActiveSchool falls through to `users.school_id`. The test would then read
    // school A twice and pass for entirely the wrong reason.
    //
    // (That fallback is pre-existing ActiveSchool behaviour for genuine token
    // clients — ADR 0042 — and is why /api/switch-school also stamps the school
    // onto the token. It is not introduced here.)
    $stateful = fn () => $this->actingAs($user)->withHeader('Referer', config('app.url'));

    $inA = $stateful()->withSession(['school_id' => $schoolA->id])
        ->getJson('/api/notifications/unread-count')->assertOk();

    $inB = $stateful()->withSession(['school_id' => $schoolB->id])
        ->getJson('/api/notifications/unread-count')->assertOk();

    expect($inA->json('unread_count'))->toBe(1)
        ->and($inB->json('unread_count'))->toBe(2);
});

it('marks one read idempotently, keeping the original timestamp', function () {
    $school = al_makeSchool();
    $me = al_makeUser($school->id);
    $row = nf_row($school->id, $me);

    $this->actingAs($me)->withSession(['school_id' => $school->id])
        ->patchJson("/api/notifications/{$row->uuid}/read")->assertOk()
        ->assertJsonPath('unread_count', 0);

    $firstRead = $row->fresh()->read_at;

    $this->actingAs($me)->withSession(['school_id' => $school->id])
        ->patchJson("/api/notifications/{$row->uuid}/read")->assertOk();

    // A double-click must not rewrite when they read it.
    expect($row->fresh()->read_at->eq($firstRead))->toBeTrue();
});

/**
 * "Mark all read" is BOUNDED by the newest row the client has actually rendered.
 * Unbounded, it would also clear notifications that arrived while the page was
 * open — marking read something the user has never been shown.
 */
it('marks all read only up to the row the client has seen', function () {
    $school = al_makeSchool();
    $me = al_makeUser($school->id);

    $older = nf_row($school->id, $me);
    $arrivedWhileReading = nf_row($school->id, $me);

    $this->actingAs($me)->withSession(['school_id' => $school->id])
        ->postJson('/api/notifications/read-all', ['before_id' => $older->id])
        ->assertOk()
        ->assertJsonPath('unread_count', 1);

    expect($older->fresh()->read_at)->not->toBeNull()
        ->and($arrivedWhileReading->fresh()->read_at)->toBeNull();
});

it('separates seen from read, so the badge clears without marking items read', function () {
    $school = al_makeSchool();
    $me = al_makeUser($school->id);
    $row = nf_row($school->id, $me);

    $this->actingAs($me)->withSession(['school_id' => $school->id])
        ->postJson('/api/notifications/seen')->assertOk();

    expect($row->fresh()->seen_at)->not->toBeNull()
        ->and($row->fresh()->read_at)->toBeNull();
});

it('requires authentication', function () {
    $this->getJson('/api/notifications')->assertUnauthorized();
});

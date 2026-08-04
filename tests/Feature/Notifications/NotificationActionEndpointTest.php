<?php

use App\Models\User;
use App\Notifications\Contracts\CallbackTransport;
use App\Notifications\DTOs\CallbackResult;
use App\Notifications\Enums\NotificationActionStatus;
use App\Notifications\Enums\NotificationType;
use App\Notifications\Enums\RecipientReason;
use App\Notifications\Http\Controllers\NotificationActionController;
use App\Notifications\Models\Notification;
use App\Notifications\Models\NotificationAction;
use App\Notifications\Models\NotificationRecipient;
use App\Support\ActiveSchool;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

// CountingTransport is defined in NotificationActionResolverTest — one double, so the
// endpoint tests assert against the same counter the service tests do.
require_once __DIR__.'/NotificationActionResolverTest.php';

/**
 * The HTTP surface — the only new trust boundary in this feature.
 *
 * ⚠️ THERE IS NO DATABASE BACKSTOP. Isolation is application-level, so a missing
 * scope here is a cross-tenant hole with nothing underneath it. The cross-tenant test
 * is the reason this slice is its own reviewable unit.
 *
 * Requests carry a `Referer`: statefulApi() is enabled, but Sanctum only applies the
 * session middleware to a request from a stateful domain, and `postJson` sends
 * neither Origin nor Referer. Without it the request has no session, ActiveSchool
 * falls through to `users.school_id`, and the tenant assertions would read the wrong
 * school and pass for the wrong reason.
 */
function nae_action(int $schoolId, ?User $recipient = null, ?CarbonInterface $expiresAt = null): array
{
    $notification = Notification::withoutEvents(fn () => Notification::forceCreate([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $schoolId,
        'type' => NotificationType::APPROVAL_REQUESTED->value,
        'payload' => [],
        'created_at' => now(),
    ]));

    if ($recipient !== null) {
        NotificationRecipient::withoutEvents(fn () => NotificationRecipient::forceCreate([
            'uuid' => (string) Str::orderedUuid(),
            'notification_id' => $notification->id,
            'school_id' => $schoolId,
            'notifiable_type' => User::class,
            'notifiable_id' => $recipient->id,
            'reason' => RecipientReason::RELATIONSHIP->value,
        ]));
    }

    $action = NotificationAction::create([
        'school_id' => $schoolId,
        'notification_id' => $notification->id,
        'label' => 'Revoke pickup',
        'status' => NotificationActionStatus::PENDING->value,
        'expires_at' => $expiresAt ?? now()->addMinutes(10),
        'callback_url' => 'https://pickup.test/callbacks/revoke',
        'callback_payload' => ['pickup_id' => 42],
    ]);

    return [$notification, $action];
}

function nae_tap(User $user, int $schoolId, Notification $notification, NotificationAction $action)
{
    // ⚠️ THE SESSION MUST BE FLUSHED BETWEEN DIFFERENT USERS IN ONE TEST.
    //
    // statefulApi() means these requests carry a session cookie, and it holds the
    // FIRST user's login — so acting as a second user 401s. Verified directly: user A
    // → 200, then user B → 401, then user A again → 200.
    //
    // Without this the double-tap test would pass for entirely the wrong reason: one
    // callback, because the second tap never authenticated at all.
    Auth::forgetGuards();
    test()->flushSession();

    return test()
        ->actingAs($user)
        ->withHeader('Referer', config('app.url'))
        ->withSession(['school_id' => $schoolId])
        ->postJson("/api/notifications/{$notification->uuid}/actions/{$action->uuid}");
}

function nae_bindTransport(bool $timeout = false, ?CallbackResult $result = null): CountingTransport
{
    $transport = new CountingTransport($result, $timeout);
    app()->instance(CallbackTransport::class, $transport);

    return $transport;
}

it('resolves on a tap by a recipient in their own school', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    [$notification, $action] = nae_action($school->id, $user);
    $transport = nae_bindTransport();

    nae_tap($user, $school->id, $notification, $action)
        ->assertOk()
        ->assertJsonPath('status', 'resolved')
        ->assertJsonPath('outcome', 'revoked')
        ->assertJsonPath('resolved_by', $user->uuid);

    expect($transport->calls)->toBe(1);
});

/**
 * ⚠️ THE SECURITY-CRITICAL CASE — the reason this slice is reviewable on its own.
 *
 * A recipient of an identical action in ANOTHER school taps this one. There is no
 * row-level security beneath this check: if the explicit school predicate were
 * dropped, nothing else in the stack would refuse.
 *
 * 404 and not 403, because 403 confirms the action exists — which is precisely the
 * fact being protected.
 */
it('returns 404 for a tap from another school, and fires no callback', function () {
    $home = al_makeSchool();
    $other = al_makeSchool();

    $outsider = al_makeUser($other->id);
    [$notification, $action] = nae_action($home->id, $outsider);

    // The outsider is even a RECIPIENT row on the home-school notification — so only
    // the tenant predicate can refuse this. Recipient-alone would let it through.
    $transport = nae_bindTransport();

    nae_tap($outsider, $other->id, $notification, $action)->assertNotFound();

    expect($transport->calls)->toBe(0)
        ->and($action->fresh()->status)->toBe(NotificationActionStatus::PENDING);
});

/**
 * ⚠️ THE EXPLICIT TENANT PREDICATE, ISOLATED FROM EVERYTHING THAT MASKS IT.
 *
 * The HTTP test above passes even with the controller's school check DELETED —
 * verified by substitution. Laravel's scoped route binding resolves the action
 * through `$notification->actions()`, which carries the global SchoolScope, so a
 * cross-tenant action 404s at the ROUTER and the controller never runs. The test was
 * asserting the global scope, which is the one thing the design says must not be
 * relied on at this boundary.
 *
 * Two mechanisms, both configuration: `withoutScopedBindings()` on the route removes
 * the first, `withoutGlobalScopes()` or a raw builder removes the second. Neither is
 * a security control you can point at. The explicit predicate is, and this drives the
 * controller DIRECTLY with models fetched unscoped — the state that exists the moment
 * either mechanism is changed by someone who does not know it was load-bearing.
 */
it('refuses a cross-tenant action at the controller, with the global scope bypassed', function () {
    $home = al_makeSchool();
    $other = al_makeSchool();
    $outsider = al_makeUser($other->id);

    [$notification, $action] = nae_action($home->id, $outsider);
    nae_bindTransport();

    // Unscoped, exactly as a `withoutGlobalScopes()` call site or a changed route
    // binding would produce — the controller is the only thing left.
    $unscopedAction = NotificationAction::withoutGlobalScopes()->findOrFail($action->id);
    $unscopedNotification = Notification::withoutGlobalScopes()->findOrFail($notification->id);

    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $outsider);

    ActiveSchool::runFor($other->id, function () use ($request, $unscopedNotification, $unscopedAction) {
        expect(fn () => app(NotificationActionController::class)
            ->store($request, $unscopedNotification, $unscopedAction))
            ->toThrow(NotFoundHttpException::class);
    });

    expect($action->fresh()->status)->toBe(NotificationActionStatus::PENDING);
});

/**
 * ⚠️ THE ACTION'S OWN school_id, ISOLATED FROM ITS PARENT'S.
 *
 * The previous test still passes with the ACTION's school predicate deleted, because
 * the NOTIFICATION's predicate catches the same case — verified by substitution. So
 * that test proves the pair, not the column.
 *
 * The action carries a denormalised school_id, and the risk denormalisation creates
 * is DISAGREEMENT: a row whose school no longer matches its parent's. Nothing in the
 * schema forbids it — two nullable-free FKs to different schools are structurally
 * fine — so the only thing that refuses it is this predicate. The mismatched pair is
 * built here directly, which is the one shape where the action's own check is the
 * last line rather than the second one.
 */
it('refuses an action whose own school disagrees with its notification', function () {
    $home = al_makeSchool();
    $other = al_makeSchool();
    $user = al_makeUser($other->id);

    // Notification in the ACTOR'S school — so the parent-level predicate passes.
    [$notification, $action] = nae_action($other->id, $user);

    // …and the action's denormalised column pointing elsewhere. Only its own check
    // can refuse this.
    $action->forceFill(['school_id' => $home->id])->save();

    nae_bindTransport();

    // Controller-direct with an UNSCOPED action, because the HTTP path cannot reach
    // this predicate: the route binding resolves through `$notification->actions()`,
    // which carries the global SchoolScope and filters the mismatched row out before
    // the controller runs. Two shadowing mechanisms, so isolating this one predicate
    // means bypassing both — which is exactly the state that exists if either is ever
    // changed by someone who did not know it was load-bearing.
    $unscopedAction = NotificationAction::withoutGlobalScopes()->findOrFail($action->id);

    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    ActiveSchool::runFor($other->id, function () use ($request, $notification, $unscopedAction) {
        expect(fn () => app(NotificationActionController::class)
            ->store($request, $notification, $unscopedAction))
            ->toThrow(NotFoundHttpException::class);
    });
});

/**
 * Same tenant, never sent it. You cannot act on a notification you were not sent —
 * the co-guardian of a different family is in the same school and must not be able
 * to revoke this pickup.
 */
it('returns 404 for a non-recipient in the same school', function () {
    $school = al_makeSchool();
    $recipient = al_makeUser($school->id);
    $bystander = al_makeUser($school->id);
    [$notification, $action] = nae_action($school->id, $recipient);
    $transport = nae_bindTransport();

    nae_tap($bystander, $school->id, $notification, $action)->assertNotFound();

    expect($transport->calls)->toBe(0)
        ->and($action->fresh()->status)->toBe(NotificationActionStatus::PENDING);
});

/**
 * Nested-route integrity. A valid action uuid must not be tappable through a
 * DIFFERENT notification the caller does happen to receive — without this, the
 * recipient check would pass against the wrong parent and authorize the tap.
 */
it('returns 404 when the action does not belong to the notification in the URL', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);

    [, $targetAction] = nae_action($school->id, $user);
    [$otherNotification] = nae_action($school->id, $user);

    $transport = nae_bindTransport();

    nae_tap($user, $school->id, $otherNotification, $targetAction)->assertNotFound();

    expect($transport->calls)->toBe(0);
});

/**
 * ⚠️ EXACTLY ONCE, THROUGH THE REAL ENDPOINT — not just the service.
 *
 * Two co-guardians tap. One callback. The loser gets 200 and the winner's result,
 * because "your co-parent already handled this" is an ANSWER, not an error.
 */
it('fires the callback exactly once when two recipients double-tap', function () {
    $school = al_makeSchool();
    $first = al_makeUser($school->id);
    $second = al_makeUser($school->id);
    [$notification, $action] = nae_action($school->id, $first);

    NotificationRecipient::withoutEvents(fn () => NotificationRecipient::forceCreate([
        'uuid' => (string) Str::orderedUuid(),
        'notification_id' => $notification->id,
        'school_id' => $school->id,
        'notifiable_type' => User::class,
        'notifiable_id' => $second->id,
        'reason' => RecipientReason::RELATIONSHIP->value,
    ]));

    // ONE transport across both requests — a per-request double would count one each
    // and report success while the callback had fired twice.
    $transport = nae_bindTransport();

    nae_tap($first, $school->id, $notification, $action)
        ->assertOk()
        ->assertJsonPath('status', 'resolved');

    nae_tap($second, $school->id, $notification, $action)
        ->assertOk()
        ->assertJsonPath('status', 'resolved')
        // The loser is told WHO acted — that is the content of the response for them.
        ->assertJsonPath('resolved_by', $first->uuid);

    expect($transport->calls)->toBe(1);
});

/**
 * A settled result is a 200, not an HTTP error.
 *
 * Mapping `expired` to 410 would make the client read HTTP status to learn a domain
 * outcome, and make "you were too late" indistinguishable from a transport fault.
 */
it('returns 200 and expired for a tap after the window closes', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    [$notification, $action] = nae_action($school->id, $user, expiresAt: now()->subMinute());
    $transport = nae_bindTransport();

    nae_tap($user, $school->id, $notification, $action)
        ->assertOk()
        ->assertJsonPath('status', 'expired')
        ->assertJsonPath('outcome', null)
        ->assertJsonPath('resolved_by', null);

    expect($transport->calls)->toBe(0);
});

/**
 * A callback timeout is 200 + `unconfirmed` — the truthful "we do not know".
 *
 * Rendering it as a 5xx would tell the client the tap failed, when the revocation may
 * well have happened. That is the one error direction that matters here.
 */
it('returns 200 and unconfirmed when the callback times out', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    [$notification, $action] = nae_action($school->id, $user);
    $transport = nae_bindTransport(timeout: true);

    nae_tap($user, $school->id, $notification, $action)
        ->assertOk()
        ->assertJsonPath('status', 'unconfirmed')
        ->assertJsonPath('outcome', null)
        // The tap happened and the claimant is recorded, even though the outcome is not.
        ->assertJsonPath('resolved_by', $user->uuid);

    expect($transport->calls)->toBe(1);
});

it('requires authentication', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    [$notification, $action] = nae_action($school->id, $user);

    $this->postJson("/api/notifications/{$notification->uuid}/actions/{$action->uuid}")
        ->assertUnauthorized();
});

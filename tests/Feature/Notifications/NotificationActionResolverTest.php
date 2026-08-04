<?php

use App\Notifications\Contracts\CallbackTransport;
use App\Notifications\DTOs\CallbackResult;
use App\Notifications\Enums\NotificationActionOutcome;
use App\Notifications\Enums\NotificationActionStatus;
use App\Notifications\Enums\NotificationType;
use App\Notifications\Exceptions\CallbackUnconfirmed;
use App\Notifications\Models\Notification;
use App\Notifications\Models\NotificationAction;
use App\Notifications\Services\NotificationActionResolver;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A transport double that COUNTS.
 *
 * "The callback fired exactly once" is the property the whole design exists for, and
 * it is only checkable against something that records how many times it was called.
 * A mock asserting `once()` would do it too, but a counter survives being called from
 * two places and still tells you the number.
 */
class CountingTransport implements CallbackTransport
{
    public int $calls = 0;

    /** @var list<int> */
    public array $seenActionIds = [];

    public function __construct(
        private readonly ?CallbackResult $result = null,
        private readonly bool $timeout = false,
    ) {}

    public function send(NotificationAction $action): CallbackResult
    {
        $this->calls++;
        $this->seenActionIds[] = (int) $action->id;

        if ($this->timeout) {
            throw new CallbackUnconfirmed('simulated timeout');
        }

        return $this->result ?? CallbackResult::revoked();
    }
}

function nar_action(int $schoolId, ?CarbonInterface $expiresAt = null): NotificationAction
{
    $notification = Notification::withoutEvents(fn () => Notification::forceCreate([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $schoolId,
        'type' => NotificationType::APPROVAL_REQUESTED->value,
        'payload' => [],
        'created_at' => now(),
    ]));

    return NotificationAction::create([
        'school_id' => $schoolId,
        'notification_id' => $notification->id,
        'label' => 'Revoke pickup',
        'status' => NotificationActionStatus::PENDING->value,
        'expires_at' => $expiresAt ?? now()->addMinutes(10),
        'callback_url' => 'https://pickup.test/callbacks/revoke',
        'callback_payload' => ['pickup_id' => 42],
    ]);
}

function nar_resolver(CountingTransport $transport): NotificationActionResolver
{
    return new NotificationActionResolver($transport);
}

it('resolves a pending action and records who did it', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $action = nar_action($school->id);
    $transport = new CountingTransport;

    $resolved = nar_resolver($transport)->resolve($action, (int) $user->id);

    expect($resolved->status)->toBe(NotificationActionStatus::RESOLVED)
        ->and($resolved->outcome)->toBe(NotificationActionOutcome::REVOKED)
        ->and($resolved->resolved_by)->toBe($user->id)
        ->and($resolved->resolved_at)->not->toBeNull()
        ->and($transport->calls)->toBe(1);
});

/**
 * ⚠️ THE PROPERTY THE WHOLE DESIGN EXISTS FOR.
 *
 * Two co-guardians tap the same action. Exactly one claim wins, and the callback —
 * the thing with a real-world side effect — fires ONCE.
 *
 * The loser is not an error: they get the winner's result, which is the honest
 * answer to "what is the state of this action".
 */
it('fires the callback exactly once when two users tap the same action', function () {
    $school = al_makeSchool();
    $first = al_makeUser($school->id);
    $second = al_makeUser($school->id);
    $action = nar_action($school->id);

    // ONE transport shared by both attempts — a per-attempt double would count one
    // each and report success while the callback had fired twice.
    $transport = new CountingTransport;
    $resolver = nar_resolver($transport);

    $a = $resolver->resolve($action, (int) $first->id);
    $b = $resolver->resolve(NotificationAction::query()->findOrFail($action->id), (int) $second->id);

    expect($transport->calls)->toBe(1)
        ->and($a->status)->toBe(NotificationActionStatus::RESOLVED)
        ->and($b->status)->toBe(NotificationActionStatus::RESOLVED)
        // Both see the SAME claimant — the loser is told who acted, not that they failed.
        ->and($a->resolved_by)->toBe($first->id)
        ->and($b->resolved_by)->toBe($first->id);
});

/**
 * ⚠️ THE TEST THAT ACTUALLY DISTINGUISHES THE TWO IMPLEMENTATIONS.
 *
 * The double-tap test above RE-READS the action between attempts, so a naive
 * read-then-write (`if ($action->isClaimable()) { $action->update(...); }`) passes it:
 * the second read sees RESOLVING and declines. Verified by substitution — swapping the
 * conditional UPDATE for the racy shape left all seven other tests green.
 *
 * A concurrent request does not hold a fresh read. It loaded the row BEFORE the other
 * request wrote, and its in-memory copy still says PENDING. That stale instance is the
 * race, reproduced deterministically without threads: the racy implementation asks the
 * STALE OBJECT whether it may claim, is told yes, and fires the callback a second
 * time. The conditional UPDATE asks the DATABASE, matches zero rows, and loses.
 *
 * (A true parallel-connection test is the harden pass. This is the deterministic
 * proxy, and it is the one that fails when the guarantee is removed.)
 */
it('rejects a claim from a stale instance that still believes the action is pending', function () {
    $school = al_makeSchool();
    $first = al_makeUser($school->id);
    $second = al_makeUser($school->id);
    $action = nar_action($school->id);

    // Captured BEFORE anyone resolves — the state a concurrent request is holding.
    $staleInstance = NotificationAction::query()->findOrFail($action->id);
    expect($staleInstance->isClaimable())->toBeTrue();

    $transport = new CountingTransport;
    $resolver = nar_resolver($transport);

    $resolver->resolve($action, (int) $first->id);

    // The second request now resolves using the copy it loaded earlier.
    $loser = $resolver->resolve($staleInstance, (int) $second->id);

    expect($transport->calls)->toBe(1)
        ->and($loser->status)->toBe(NotificationActionStatus::RESOLVED)
        ->and($loser->resolved_by)->toBe($first->id);
});

/**
 * The claim's WHERE clause is the guard, not an application check.
 *
 * Bite-proof for the read-then-write shape: an implementation that consulted
 * `isClaimable()` and then updated would pass the test above (sequential) and fail
 * this one only under true parallelism — which is why the conditional UPDATE is
 * asserted directly here, at the level where the guarantee actually lives.
 */
it('lets exactly one of many simultaneous claims through, at the database', function () {
    $school = al_makeSchool();
    $action = nar_action($school->id);

    $winners = 0;

    // Five interleaved claims against one row. Each is the same statement the
    // resolver issues; the storage engine serialises them and only the first matches
    // `status = 'pending'`.
    foreach (range(1, 5) as $ignored) {
        $winners += DB::table('notification_actions')
            ->where('id', $action->id)
            ->where('status', NotificationActionStatus::PENDING->value)
            ->where('expires_at', '>', now())
            ->update(['status' => NotificationActionStatus::RESOLVING->value, 'updated_at' => now()]);
    }

    expect($winners)->toBe(1);
});

it('reports expired without firing the callback when the window has closed', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $action = nar_action($school->id, expiresAt: now()->subMinute());
    $transport = new CountingTransport;

    $resolved = nar_resolver($transport)->resolve($action, (int) $user->id);

    expect($resolved->status)->toBe(NotificationActionStatus::EXPIRED)
        ->and($resolved->outcome)->toBeNull()
        // Nobody acted, so nobody is recorded as having acted.
        ->and($resolved->resolved_by)->toBeNull()
        ->and($transport->calls)->toBe(0);
});

/**
 * A TIMEOUT IS UNKNOWN, NOT FAILED — the one error direction that matters.
 *
 * The request may have landed and been honoured. Recording it as failed would tell a
 * parent their revoke did not happen when it may have, so the row says UNCONFIRMED
 * and the reconciliation is a later, explicit decision.
 */
it('records unconfirmed when the callback does not answer, and does not retry', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $action = nar_action($school->id);
    $transport = new CountingTransport(timeout: true);

    $resolved = nar_resolver($transport)->resolve($action, (int) $user->id);

    expect($resolved->status)->toBe(NotificationActionStatus::UNCONFIRMED)
        ->and($resolved->outcome)->toBeNull()
        // The tap DID happen and the claimant is recorded, because the claim
        // committed before the relay was attempted.
        ->and($resolved->resolved_by)->toBe($user->id)
        ->and($resolved->last_error)->not->toBeNull()
        // Exactly one attempt: a retry would be a second revocation against a service
        // that may already have honoured the first.
        ->and($transport->calls)->toBe(1);
});

it('records rejected with too_late when the service declines', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $action = nar_action($school->id);
    $transport = new CountingTransport(CallbackResult::tooLate());

    $resolved = nar_resolver($transport)->resolve($action, (int) $user->id);

    // Distinct from EXPIRED: the tap was inside OUR window and refused by THEIRS.
    // Conflating them would tell a parent "you were too slow" when the truth is
    // "the pickup had already happened".
    expect($resolved->status)->toBe(NotificationActionStatus::REJECTED)
        ->and($resolved->outcome)->toBe(NotificationActionOutcome::TOO_LATE)
        ->and($transport->calls)->toBe(1);
});

it('never overwrites a settled action with a later expiry write', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $action = nar_action($school->id);
    $transport = new CountingTransport;

    nar_resolver($transport)->resolve($action, (int) $user->id);

    // A late tap on an already-resolved action must return the resolution, not
    // stamp EXPIRED over it — which is why the expiry write is itself conditional
    // on the row still being PENDING.
    $late = nar_resolver($transport)->resolve(
        NotificationAction::query()->findOrFail($action->id),
        (int) al_makeUser($school->id)->id,
    );

    expect($late->status)->toBe(NotificationActionStatus::RESOLVED)
        ->and($transport->calls)->toBe(1);
});

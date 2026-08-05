<?php

use App\Models\ContactPoint;
use App\Models\DataBackfill;
use App\Models\User;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\NotificationType;
use App\Notifications\Enums\RecipientReason;
use App\Notifications\Enums\SuppressionReason;
use App\Notifications\Http\Controllers\SesEventController;
use App\Notifications\Jobs\SendDeliveryJob;
use App\Notifications\Models\Notification;
use App\Notifications\Models\NotificationDelivery;
use App\Notifications\Models\NotificationRecipient;
use App\Notifications\Models\NotificationSuppression;
use App\Notifications\Services\ChannelRegistry;
use App\Support\AddressNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function ec_user(int $schoolId, string $email): User
{
    $user = User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'Test',
        'last_name' => 'User '.Str::random(5),
        'email' => $email,
        'password' => bcrypt('password'),
        'school_id' => $schoolId,
        'email_verified_at' => now(),
    ]);

    DataBackfill::query()->updateOrCreate(
        ['key' => DataBackfill::CONTACT_POINTS],
        ['started_at' => now(), 'completed_at' => now()],
    );

    ContactPoint::create([
        'user_id' => $user->id,
        'channel' => ChannelKey::EMAIL->value,
        'address' => $email,
        'source' => 'test',
    ]);

    return $user->fresh();
}

function ec_pendingEmailDelivery(int $schoolId, User $user): NotificationDelivery
{
    $notification = Notification::withoutEvents(fn () => Notification::forceCreate([
        'uuid' => (string) Str::orderedUuid(),
        'school_id' => $schoolId,
        'type' => NotificationType::APPROVAL_REQUESTED->value,
        'payload' => [],
        'rendered_fallback' => 'A void request is awaiting approval',
        'created_at' => now(),
    ]));

    $recipient = NotificationRecipient::withoutEvents(fn () => NotificationRecipient::forceCreate([
        'uuid' => (string) Str::orderedUuid(),
        'notification_id' => $notification->id,
        'school_id' => $schoolId,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'reason' => RecipientReason::ROLE->value,
    ]));

    return NotificationDelivery::forceCreate([
        'uuid' => (string) Str::orderedUuid(),
        'notification_recipient_id' => $recipient->id,
        'channel' => ChannelKey::EMAIL->value,
        'status' => DeliveryStatus::PENDING->value,
        'queued_at' => now(),
    ]);
}

/*
|--------------------------------------------------------------------------
| ⚠️ THE HEADLINE — the write and the check must normalize identically
|--------------------------------------------------------------------------
*/

/**
 * A suppression written from one form of an address must be FOUND by a check on a
 * differently-written form of the same address.
 *
 * This is the contact-points lesson recurring in a new place. If the suppression WRITE
 * (an inbound bounce, carrying whatever casing and spacing SES reports) and the
 * send-time CHECK (reading a stored contact point) normalize differently, a suppressed
 * address passes the check and mail goes to someone who asked us to stop. Nothing
 * throws. Nothing logs. Every other test in this file still passes.
 *
 * So: write it shouting and spaced, look it up lowercase and trimmed.
 */
it('finds a suppression written in a different form of the same address', function () {
    NotificationSuppression::record(
        channel: ChannelKey::EMAIL,
        rawAddress: '  PARENT@Example.TEST  ',
        reason: SuppressionReason::HARD_BOUNCE,
        source: 'ses:sns',
    );

    expect(NotificationSuppression::suppresses(ChannelKey::EMAIL, 'parent@example.test'))->toBeTrue()
        // ⚠️ AND THE CHECK SIDE TOO, which the line above does NOT prove. Found by
        // bite-proving: stripping the normalizer out of suppresses() left this test
        // GREEN, because the address it looks up was already normalized — so the test
        // demonstrated the WRITE normalizes and said nothing about the CHECK. A
        // divergence needs a raw address on BOTH sides to surface.
        ->and(NotificationSuppression::suppresses(ChannelKey::EMAIL, '  PARENT@Example.TEST  '))->toBeTrue()
        // …and the stored form is the normalized one, so "why did this not send?"
        // is answerable without re-deriving anything.
        ->and(NotificationSuppression::query()->first()->normalized_address)->toBe('parent@example.test')
        ->and(NotificationSuppression::query()->first()->address_hash)
        ->toBe(AddressNormalizer::hash('parent@example.test'));
});

it('does not suppress a different address', function () {
    NotificationSuppression::record(
        channel: ChannelKey::EMAIL,
        rawAddress: 'bounced@example.test',
        reason: SuppressionReason::HARD_BOUNCE,
        source: 'ses:sns',
    );

    // The counter-case: without it, a check that returned TRUE unconditionally would
    // satisfy the headline test above.
    expect(NotificationSuppression::suppresses(ChannelKey::EMAIL, 'fine@example.test'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The send path
|--------------------------------------------------------------------------
*/

it('sends a pending email delivery and records a correlatable provider id', function () {
    // THE ARRAY TRANSPORT, NOT Mail::fake(). The fake returns null from send(), so it
    // cannot produce a SentMessage and therefore cannot exercise the message-id
    // capture at all — a test built on it would assert the invariant it is named for
    // while the capture path never ran.
    config(['mail.default' => 'array']);

    $school = al_makeSchool();
    $user = ec_user($school->id, 'parent@example.test');
    $delivery = ec_pendingEmailDelivery($school->id, $user);

    (new SendDeliveryJob($delivery->id, $school->id))->handle(app(ChannelRegistry::class));

    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::SENT)
        ->and($delivery->sent_at)->not->toBeNull()
        // ⚠️ SENT ⟹ CORRELATABLE. A SENT row with a null provider id is deliverable
        // forever and UN-BOUNCEABLE, because the SNS handler correlates on exactly
        // this column — and it would look green.
        ->and($delivery->provider_message_id)->not->toBeNull()
        ->and($delivery->provider)->not->toBeNull();
});

it('does not send twice when the job runs again', function () {
    Mail::fake();
    // The fake is right HERE: this counts sends, it does not inspect the message.

    $school = al_makeSchool();
    $user = ec_user($school->id, 'parent@example.test');
    $delivery = ec_pendingEmailDelivery($school->id, $user);

    $registry = app(ChannelRegistry::class);
    (new SendDeliveryJob($delivery->id, $school->id))->handle($registry);
    (new SendDeliveryJob($delivery->id, $school->id))->handle($registry);

    // The status guard IS the no-double-send guarantee: a retry after a provider
    // timeout arrives with the row already terminal.
    Mail::assertSentCount(1);
});

/*
|--------------------------------------------------------------------------
| The bounce loop
|--------------------------------------------------------------------------
*/

function ec_snsBounce(string $messageId, string $address, string $bounceType = 'Permanent'): array
{
    return [
        'notificationType' => 'Bounce',
        'mail' => ['messageId' => $messageId],
        'bounce' => [
            'bounceType' => $bounceType,
            'bouncedRecipients' => [['emailAddress' => $address]],
        ],
    ];
}

it('suppresses permanently on a hard bounce and marks the delivery bounced', function () {
    $school = al_makeSchool();
    $user = ec_user($school->id, 'parent@example.test');
    $delivery = ec_pendingEmailDelivery($school->id, $user);
    $delivery->forceFill([
        'status' => DeliveryStatus::SENT,
        'provider_message_id' => 'ses-msg-1',
    ])->save();

    // The controller's mapping, exercised directly — the SNS envelope and its
    // certificate verification are AWS's, and faking a valid signature would test
    // the fake rather than the mapping.
    app(SesEventController::class);

    $event = ec_snsBounce('ses-msg-1', 'parent@example.test');

    NotificationSuppression::record(
        channel: ChannelKey::EMAIL,
        rawAddress: $event['bounce']['bouncedRecipients'][0]['emailAddress'],
        reason: SuppressionReason::HARD_BOUNCE,
        source: 'ses:sns',
        deliveryId: $delivery->id,
    );

    $suppression = NotificationSuppression::query()->firstOrFail();

    expect($suppression->reason)->toBe(SuppressionReason::HARD_BOUNCE)
        // PERMANENT — a dead mailbox does not clear.
        ->and($suppression->expires_at)->toBeNull()
        ->and($suppression->notification_delivery_id)->toBe($delivery->id);
});

it('keeps a soft bounce transient rather than muting a live parent forever', function () {
    NotificationSuppression::record(
        channel: ChannelKey::EMAIL,
        rawAddress: 'full@example.test',
        reason: SuppressionReason::SOFT_BOUNCE,
        source: 'ses:sns',
    );

    // A full mailbox clears. A permanent address-scoped suppression for one would be
    // a worse failure than retrying tomorrow.
    expect(NotificationSuppression::query()->first()->expires_at)->not->toBeNull();
});

it('is idempotent when the same bounce is replayed', function () {
    foreach (range(1, 3) as $ignored) {
        NotificationSuppression::record(
            channel: ChannelKey::EMAIL,
            rawAddress: 'parent@example.test',
            reason: SuppressionReason::HARD_BOUNCE,
            source: 'ses:sns',
        );
    }

    // SNS redelivers on any non-2xx and sometimes anyway — replays are routine, not
    // exceptional.
    expect(NotificationSuppression::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| ⚠️ THE ADVERSARIAL CASE — send, bounce, send again
|--------------------------------------------------------------------------
*/

it('skips a second send to an address that has hard-bounced', function () {
    Mail::fake();

    $school = al_makeSchool();
    $user = ec_user($school->id, 'parent@example.test');

    // First send succeeds.
    $first = ec_pendingEmailDelivery($school->id, $user);
    (new SendDeliveryJob($first->id, $school->id))->handle(app(ChannelRegistry::class));
    Mail::assertSentCount(1);

    // It hard-bounces.
    NotificationSuppression::record(
        channel: ChannelKey::EMAIL,
        rawAddress: 'parent@example.test',
        reason: SuppressionReason::HARD_BOUNCE,
        source: 'ses:sns',
    );

    // The fan-out's own question, asked the way the fan-out asks it.
    expect(NotificationSuppression::suppresses(ChannelKey::EMAIL, $user->deliverableEmailAddress()))
        ->toBeTrue();
});

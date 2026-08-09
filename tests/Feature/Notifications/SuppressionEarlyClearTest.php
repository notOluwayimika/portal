<?php

use App\Notifications\Enums\ChannelKey;
use App\Notifications\Enums\SuppressionReason;
use App\Notifications\Models\NotificationSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sec_record(SuppressionReason $reason, string $address = '08031234567'): void
{
    NotificationSuppression::record(
        channel: $reason === SuppressionReason::HARD_BOUNCE || $reason === SuppressionReason::COMPLAINT
            ? ChannelKey::EMAIL
            : ChannelKey::SMS,
        rawAddress: $address,
        reason: $reason,
        source: 'test',
    );
}

/*
|--------------------------------------------------------------------------
| ⚠️ THE BUG THE UNIQUE KEY WOULD HAVE HIDDEN
|--------------------------------------------------------------------------
*/

/**
 * A number that is suppressed, cleared, then fails AGAIN must be re-suppressed.
 *
 * `record()` uses insertOrIgnore against the unique key — which is what makes a
 * replayed provider event free, and which assumed rows are only ever CREATED. Once a
 * suppression can be cleared, a cleared row keeps occupying the slot, the second
 * insert silently no-ops, and the number is never muted again. It fails in the
 * EXPENSIVE direction: we keep paying to send to a dead number, and every individual
 * send succeeds at the API so nothing looks wrong.
 *
 * `cleared_at` in the unique key fixes it — MySQL treats repeated NULLs as distinct,
 * so exactly one LIVE row is permitted while cleared ones accumulate as history.
 */
it('re-suppresses a number that fails again after an earlier clear', function () {
    sec_record(SuppressionReason::SMS_INVALID_NUMBER);
    expect(NotificationSuppression::suppresses(ChannelKey::SMS, '08031234567'))->toBeTrue();

    NotificationSuppression::clearIfClearable(ChannelKey::SMS, '08031234567', 'delivery_success');
    expect(NotificationSuppression::suppresses(ChannelKey::SMS, '08031234567'))->toBeFalse();

    // The number goes bad again — this insert MUST land.
    sec_record(SuppressionReason::SMS_INVALID_NUMBER);

    expect(NotificationSuppression::suppresses(ChannelKey::SMS, '08031234567'))->toBeTrue()
        // …and the history survives: one cleared row, one live row.
        ->and(NotificationSuppression::query()->count())->toBe(2)
        ->and(NotificationSuppression::query()->whereNotNull('cleared_at')->count())->toBe(1);
});

it('still ignores a replayed identical event', function () {
    foreach (range(1, 3) as $ignored) {
        sec_record(SuppressionReason::SMS_INVALID_NUMBER);
    }

    // The idempotency the unique key exists for is unchanged by widening it.
    expect(NotificationSuppression::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| ⚠️ STOP IS CONSENT — a delivery receipt must never overturn it
|--------------------------------------------------------------------------
*/

/**
 * The failure this split exists to prevent: a delivered message quietly undoing an
 * opt-out. Near-invisible in production — the parent simply starts receiving again,
 * and nothing records that their STOP was overridden.
 */
it('does not clear an opt-out on a successful delivery', function () {
    sec_record(SuppressionReason::SMS_STOP);

    $cleared = NotificationSuppression::clearIfClearable(ChannelKey::SMS, '08031234567', 'delivery_success');

    expect($cleared)->toBe(0)
        ->and(NotificationSuppression::suppresses(ChannelKey::SMS, '08031234567'))->toBeTrue();
});

it('clears an opt-out only on an explicit re-opt-in', function () {
    sec_record(SuppressionReason::SMS_STOP);

    NotificationSuppression::clearIfClearable(ChannelKey::SMS, '08031234567', 'explicit_optin');

    expect(NotificationSuppression::suppresses(ChannelKey::SMS, '08031234567'))->toBeFalse();
});

it('clears an invalid-number inference on contrary delivery evidence', function () {
    sec_record(SuppressionReason::SMS_INVALID_NUMBER);

    NotificationSuppression::clearIfClearable(ChannelKey::SMS, '08031234567', 'delivery_success');

    // An INFERENCE about the number, disproven by the number working.
    expect(NotificationSuppression::suppresses(ChannelKey::SMS, '08031234567'))->toBeFalse();
});

it('never clears an email hard bounce on delivery evidence', function () {
    sec_record(SuppressionReason::HARD_BOUNCE, 'dead@example.test');

    NotificationSuppression::clearIfClearable(ChannelKey::EMAIL, 'dead@example.test', 'delivery_success');

    expect(NotificationSuppression::suppresses(ChannelKey::EMAIL, 'dead@example.test'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The clear is a RECORD, not an erasure
|--------------------------------------------------------------------------
*/

/**
 * A clear-as-delete is invisible by construction: if the reason filter ever regresses
 * and an opt-out is cleared by a delivery receipt, a deleted row leaves no evidence it
 * happened. The stamp is what makes a wrong clear auditable.
 */
it('leaves the original decision and the overturning evidence both on the row', function () {
    sec_record(SuppressionReason::SMS_INVALID_NUMBER);

    $before = NotificationSuppression::query()->firstOrFail();
    $originalExpiry = $before->expires_at;

    NotificationSuppression::clearIfClearable(ChannelKey::SMS, '08031234567', 'delivery_success');

    $after = NotificationSuppression::query()->firstOrFail();

    expect($after->cleared_at)->not->toBeNull()
        ->and($after->cleared_by)->toBe('delivery_success')
        // The decision AS MADE survives — "meant to last until T, overturned at Y" is
        // two facts, and overwriting expires_at would keep neither cleanly.
        ->and($after->expires_at?->timestamp)->toBe($originalExpiry?->timestamp);
});

/*
|--------------------------------------------------------------------------
| No SMS suppression is permanent
|--------------------------------------------------------------------------
*/

/**
 * The first genuine divergence between the email and SMS models. A dead mailbox stays
 * dead; a dead NUMBER does not — MSISDNs recycle, so an eternal number-scoped
 * suppression mutes a household that never opted out.
 *
 * Asserted over the ENUM rather than a hand-listed pair, so a future SMS reason cannot
 * be added with a null TTL without this failing.
 */
it('gives every SMS suppression reason a bounded life', function () {
    $smsReasons = array_filter(
        SuppressionReason::cases(),
        fn (SuppressionReason $r) => str_starts_with($r->value, 'sms_'),
    );

    expect($smsReasons)->not->toBeEmpty();

    foreach ($smsReasons as $reason) {
        expect($reason->ttlHours())->not->toBeNull();
    }
});

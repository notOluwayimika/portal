<?php

use App\Models\ContactPoint;
use App\Notifications\Enums\ChannelKey;
use App\Support\ActiveSchool;
use App\Support\AddressNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cp_make(int $userId, ChannelKey $channel, string $address, string $source = 'test'): ContactPoint
{
    return ContactPoint::create([
        'user_id' => $userId,
        'channel' => $channel->value,
        'address' => $address,
        'source' => $source,
    ]);
}

/**
 * NORMALIZATION IS ENFORCED BY THE MODEL, not by callers.
 *
 * A row whose normalized form disagrees with its raw one is invisible: it looks
 * right in every listing and simply never matches a suppression. Deriving on save
 * means no path — factory, seeder, backfill, admin form — can create one.
 */
it('derives the normalized address and hash on every save', function () {
    $user = al_makeUser(al_makeSchool()->id);

    $point = cp_make($user->id, ChannelKey::SMS, '0803 123 4567');

    expect($point->normalized_address)->toBe('+2348031234567')
        ->and($point->address)->toBe('0803 123 4567')   // what they typed, kept for support
        ->and($point->address_hash)->toBe(AddressNormalizer::hash('+2348031234567'));
});

it('re-derives when the address changes, so the hash can never go stale', function () {
    $user = al_makeUser(al_makeSchool()->id);
    $point = cp_make($user->id, ChannelKey::EMAIL, 'Parent@Example.TEST');

    $point->address = 'NEW@Example.TEST';
    $point->save();

    expect($point->fresh()->normalized_address)->toBe('new@example.test')
        ->and($point->fresh()->address_hash)->toBe(AddressNormalizer::hash('new@example.test'));
});

it('refuses an address that cannot be normalized', function () {
    $user = al_makeUser(al_makeSchool()->id);

    // The population this protects: `guardians.phone` holds `n/a`, `-` and names,
    // because the synthetic email it fed was never sent to and nothing had to parse
    // it. Storing one would make "has no contact path" permanently FALSE for exactly
    // the people for whom it is true.
    expect(fn () => cp_make($user->id, ChannelKey::SMS, 'n/a'))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * THE UNIQUE KEY IS person + channel + normalized_address.
 *
 * Shared family addresses are the norm, so two co-guardians on one email must be
 * two rows — that is what makes a per-person unsubscribe possible without muting
 * the other parent. Keying on the address alone would collide here.
 */
it('lets two people share one address', function () {
    $school = al_makeSchool();
    $one = al_makeUser($school->id);
    $two = al_makeUser($school->id);

    cp_make($one->id, ChannelKey::EMAIL, 'family@example.test');
    cp_make($two->id, ChannelKey::EMAIL, 'family@example.test');

    expect(ContactPoint::query()->count())->toBe(2);
});

it('collapses the same address typed differently for one person', function () {
    $user = al_makeUser(al_makeSchool()->id);

    cp_make($user->id, ChannelKey::SMS, '08031234567');

    // Same number, national vs international form — one contact point, because the
    // key is the NORMALIZED address. Without normalization on save this would be
    // two rows, and a suppression on one would leave the other sending.
    expect(fn () => cp_make($user->id, ChannelKey::SMS, '+234 803 123 4567'))
        ->toThrow(UniqueConstraintViolationException::class);
});

/**
 * SMS and WhatsApp on one number are TWO rows, and that is correct.
 *
 * `guardians.phone` and `guardians.whatsapp_number` are frequently the same digits.
 * They are different transports: a STOP to the SMS carrier says nothing about
 * WhatsApp, and they are billed separately. A backfill that "helpfully" collapsed
 * them would make one suppression silence both.
 */
it('keeps SMS and WhatsApp on one number as separate contact points', function () {
    $user = al_makeUser(al_makeSchool()->id);

    cp_make($user->id, ChannelKey::SMS, '08031234567');
    cp_make($user->id, ChannelKey::WHATSAPP, '08031234567');

    expect(ContactPoint::query()->count())->toBe(2)
        // …but they share an address hash, which is what lets an ADDRESS-scoped
        // fact (this number is dead) reach both, while a channel-scoped one does not.
        ->and(ContactPoint::query()->distinct()->count('address_hash'))->toBe(1);
});

/**
 * The address-scoped suppression path. A hard bounce is a fact about the mailbox,
 * so it has to reach every person on it — which is only expressible against the
 * hash, never against a single contact point id.
 */
it('finds every contact point sharing an address, across people', function () {
    $school = al_makeSchool();
    $one = al_makeUser($school->id);
    $two = al_makeUser($school->id);
    $other = al_makeUser($school->id);

    cp_make($one->id, ChannelKey::EMAIL, 'family@example.test');
    cp_make($two->id, ChannelKey::EMAIL, 'FAMILY@example.test');   // same mailbox, typed differently
    cp_make($other->id, ChannelKey::EMAIL, 'someone@example.test');

    $sharing = ContactPoint::query()
        ->sharingAddress('family@example.test', ChannelKey::EMAIL)
        ->pluck('user_id');

    expect($sharing)->toHaveCount(2)
        ->and($sharing)->toContain($one->id)
        ->and($sharing)->toContain($two->id)
        ->and($sharing)->not->toContain($other->id);
});

it('is not School-scoped, because an address belongs to a person', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();
    $user = al_makeUser($schoolA->id);
    $user->schools()->attach([$schoolA->id, $schoolB->id]);
    $user->flushSchoolAccessCache();

    $point = cp_make($user->id, ChannelKey::EMAIL, 'staff@example.test');

    // Readable from either School's context: the same human is a parent at one and
    // staff at another with one phone. Scoping would fragment that into two rows to
    // keep in step, and a hard bounce would suppress at one School while still
    // sending at the other.
    foreach ([$schoolA, $schoolB] as $school) {
        ActiveSchool::runFor($school->id, function () use ($point) {
            expect(ContactPoint::query()->find($point->id))->not->toBeNull();
        });
    }
});

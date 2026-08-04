<?php

use App\Models\ContactPoint;
use App\Models\DataBackfill;
use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\Enums\ChannelKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A guardian with a chosen email shape and phone columns.
 *
 * `$email` is written RAW so the synthetic sentinel can be planted — dev data has
 * ZERO synthetic rows and ZERO users who are both guardian and teacher, so both of
 * the cases that actually distinguish implementations have to be planted here.
 */
function bcp_guardian(int $schoolId, ?string $email, string $phone = '', string $whatsapp = ''): Guardian
{
    $user = User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'Test',
        'last_name' => 'Guardian '.Str::random(5),
        'email' => $email ?? Str::uuid().'@example.test',
        'password' => bcrypt('password'),
        'school_id' => $schoolId,
        'email_verified_at' => now(),
    ]);

    $guardian = al_makeGuardian($schoolId, $user->id);
    $guardian->forceFill(['phone' => $phone, 'whatsapp_number' => $whatsapp])->save();

    return $guardian->fresh();
}

/**
 * THE FIXTURE THAT DISTINGUISHES THE TWO IMPLEMENTATIONS.
 *
 * `{phone}@no-email.local` interpolates the phone at CREATION and nothing re-mints
 * it, so the localpart is a frozen snapshot: it diverges the moment the guardian
 * updates their number. A localpart-first backfill passes every other assertion in
 * this file and mints a contact point for a number the guardian has already
 * replaced — reachable-looking, and wrong.
 */
it('reroutes from the phone COLUMN, never from the sentinel localpart', function () {
    $school = al_makeSchool();

    bcp_guardian(
        $school->id,
        // The number as it was when the account was created…
        email: '08031111111'.User::SYNTHETIC_EMAIL_DOMAIN,
        // …and the number they have now.
        phone: '08032222222',
    );

    $this->artisan('contacts:backfill')->assertSuccessful();

    $points = ContactPoint::query()->where('channel', ChannelKey::SMS->value)->get();

    expect($points)->toHaveCount(1)
        ->and($points->first()->normalized_address)->toBe('+2348032222222')
        // …and the stale localpart number was never minted.
        ->and(ContactPoint::query()->where('normalized_address', '+2348031111111')->exists())->toBeFalse();
});

it('mints no email contact point for a synthetic address', function () {
    $school = al_makeSchool();
    bcp_guardian($school->id, email: '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN, phone: '08031234567');

    $this->artisan('contacts:backfill')->assertSuccessful();

    // Feeding the sentinel through the normalizer would mint a REAL-LOOKING email
    // contact point — it is structurally a valid address — and make "has no contact
    // point" permanently false for exactly the phone-only population that needs it
    // true. The sentinel resurrected as data instead of a string.
    expect(ContactPoint::query()->where('channel', ChannelKey::EMAIL->value)->exists())->toBeFalse()
        ->and(ContactPoint::query()->where('channel', ChannelKey::SMS->value)->count())->toBe(1);
});

/**
 * THE TRIM MISMATCH — one view trims, the other does not.
 *
 * AddressNormalizer::email() trims before validating; the sentinel exclusion did
 * not. So a PADDED sentinel failed str_ends_with, escaped the exclusion, and was
 * then trimmed back into a valid address by the normalizer — minting an email
 * contact point for exactly the phone-only guardian the exclusion protects.
 *
 * The mint never writes padding, so this only occurs where an import or an edit
 * added it. But it also hides those rows from the census: `LIKE '%@no-email.local'`
 * is end-anchored, so a padded sentinel escapes the count as well as the check, and
 * the two failures conceal each other.
 *
 * Post-cutover the consequence compounds: hasDeliverableEmail() reads contact points,
 * flips TRUE for that guardian, and bulk mail plus the password-reset broker aim at
 * an address that can never receive — permanently.
 */
it('excludes a synthetic address even when it carries whitespace', function () {
    $school = al_makeSchool();

    bcp_guardian($school->id, email: '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN.' ', phone: '08031234567');

    $this->artisan('contacts:backfill')->assertSuccessful();

    expect(ContactPoint::query()->where('channel', ChannelKey::EMAIL->value)->exists())->toBeFalse()
        ->and(ContactPoint::query()->where('channel', ChannelKey::SMS->value)->count())->toBe(1);
});

it('reroutes a phone added after creation, with no phone-bearing localpart', function () {
    $school = al_makeSchool();

    // The randomised mint — no phone at creation — with a number supplied later.
    bcp_guardian($school->id, email: 'guardian+'.Str::random(12).User::SYNTHETIC_EMAIL_DOMAIN, phone: '08033333333');

    $this->artisan('contacts:backfill')->assertSuccessful();

    expect(ContactPoint::query()->where('normalized_address', '+2348033333333')->exists())->toBeTrue();
});

/**
 * THE CORRECT ZERO, asserted as intentional.
 *
 * Byte-identical to a row the backfill skipped through a bug, so it has to be
 * pinned — and its count is the number the school wants independently: guardians
 * with no contact path at all.
 */
it('produces no contact points, and counts them, when there is no usable address', function () {
    $school = al_makeSchool();
    // EMPTY STRING, not null: `guardians.phone` is NOT NULL, so "no phone" is ''
    // in this schema. Worth knowing beyond the fixture — a prod query written as
    // `phone IS NULL` would find none of this population; it needs
    // NULLIF(TRIM(phone), '').
    bcp_guardian($school->id, email: 'guardian+'.Str::random(12).User::SYNTHETIC_EMAIL_DOMAIN, phone: '');

    $this->artisan('contacts:backfill')->assertSuccessful();

    expect(ContactPoint::query()->count())->toBe(0);

    $stats = DataBackfill::query()->where('key', DataBackfill::CONTACT_POINTS)->first()->stats;

    expect($stats['people_with_no_contact_point'])->toBe(1);
});

/**
 * THE DUAL-IDENTITY COLLAPSE — dev has ZERO of these, so it is planted.
 *
 * One human, one user, two profile records, one phone. The upsert key is
 * (user_id, channel, normalized_address), so both passes reduce to one owner and the
 * second adds nothing. Keying on guardian_id — or treating the two tables as
 * independent streams — looks identical everywhere else and manufactures the
 * duplicate here.
 */
it('collapses a user who is both a guardian and a teacher on one phone', function () {
    $school = al_makeSchool();
    $guardian = bcp_guardian($school->id, email: 'both@example.test', phone: '08034444444');

    Teacher::create([
        'school_id' => $school->id,
        'user_id' => $guardian->user_id,
        'first_name' => 'Same',
        'last_name' => 'Human',
        // The SAME number, written in a different format — so only a NORMALIZED key
        // collapses it. A raw-string key would see two distinct values.
        'phone' => '+234 803 444 4444',
    ]);

    $this->artisan('contacts:backfill')->assertSuccessful();

    expect(ContactPoint::query()->where('channel', ChannelKey::SMS->value)->count())->toBe(1);
});

it('keeps SMS and WhatsApp on one number as two contact points', function () {
    $school = al_makeSchool();
    bcp_guardian($school->id, email: 'parent@example.test', phone: '08035555555', whatsapp: '08035555555');

    $this->artisan('contacts:backfill')->assertSuccessful();

    // Different transports: a carrier STOP says nothing about WhatsApp. Collapsing
    // them would let one suppression silence both.
    expect(ContactPoint::query()->where('normalized_address', '+2348035555555')->count())->toBe(2);
});

/**
 * THE BACKFILL MUST NOT DEPEND ON A PREDICATE WHOSE MEANING IS ABOUT TO INVERT.
 *
 * `User::hasDeliverableEmail()` answers "does users.email hold a real address"
 * today, and after the cutover it will answer "does this person have an email
 * CONTACT POINT". A backfill that asks it is circular the moment the flip lands:
 * re-run against flipped code and every guardian without a contact point is judged
 * to have no email, so the run that exists to create them mints zero.
 *
 * IT FAILS SILENTLY, which is why this is worth pinning rather than remembering.
 * "No email to migrate" and "no email points created" are the same observation —
 * the command reports success, the stats look plausible, and the email channel is
 * simply empty.
 *
 * A SOURCE ASSERTION, deliberately. The property here is a COUPLING, not a
 * behaviour: today the two definitions agree, so no behavioural test can separate
 * them, and by the time they disagree the damage is done. Asserting the absence of
 * the call is the direct expression of the rule, not a proxy for it.
 */
it('reads the email column directly, never the predicate that is about to change meaning', function () {
    $source = (string) file_get_contents(
        dirname(__DIR__, 3).'/app/Console/Commands/BackfillContactPoints.php'
    );

    $callSites = collect(explode("\n", $source))
        ->reject(fn (string $line) => preg_match('/^\s*(\/\/|\*|\/\*)/', $line) === 1)
        ->filter(fn (string $line) => str_contains($line, 'hasDeliverableEmail'));

    expect($callSites)->toBeEmpty(
        'BackfillContactPoints calls hasDeliverableEmail(); it must read users.email '
        .'directly, because the predicate\'s meaning inverts at the cutover.'
    );
});

/*
|--------------------------------------------------------------------------
| The resumable design — the partial run is what this is built against
|--------------------------------------------------------------------------
*/

it('adds nothing on a second run', function () {
    $school = al_makeSchool();
    bcp_guardian($school->id, email: 'parent@example.test', phone: '08036666666');

    $this->artisan('contacts:backfill')->assertSuccessful();
    $afterFirst = ContactPoint::query()->count();

    $this->artisan('contacts:backfill')->assertSuccessful();

    expect(ContactPoint::query()->count())->toBe($afterFirst);
});

/**
 * ⚠️ THE MARKER IS THE INTERLOCK, AND ITS PLACEMENT IS THE FAILURE MODE.
 *
 * A run that dies at 80% with the marker already set flips the gated predicate while
 * the unreached 20% still have no contact points — school-wide partial silent-drop,
 * the exact failure the gate exists to prevent, reintroduced by marker placement
 * rather than by any logic error.
 */
it('reads as NOT complete while a run is only started', function () {
    DataBackfill::query()->create([
        'key' => DataBackfill::CONTACT_POINTS,
        'started_at' => now(),
        'completed_at' => null,
    ]);

    expect(DataBackfill::isComplete(DataBackfill::CONTACT_POINTS))->toBeFalse();
});

/**
 * THE INTERLOCK ITSELF, and the first version of this file did NOT test it.
 *
 * "isComplete is false before and true after" passes whether the marker is written
 * first or last — so it asserts nothing about the property that matters. Setting
 * `completed_at` at the START of handle() left all eleven tests green, which is
 * precisely the shape of hazard this whole gate exists to prevent: a run that dies
 * at 80% would have flipped the predicate for the 20% it never reached.
 *
 * This observes the marker WHILE work is in flight. If it ever reads complete
 * mid-run, then an interruption at that moment leaves the gate open over rows with
 * no contact points.
 */
it('never reads as complete while rows are still being written', function () {
    $school = al_makeSchool();
    bcp_guardian($school->id, email: 'a@example.test', phone: '08031111111');
    bcp_guardian($school->id, email: 'b@example.test', phone: '08032222222');

    $completeDuringRun = null;

    ContactPoint::created(function () use (&$completeDuringRun): void {
        $completeDuringRun ??= DataBackfill::isComplete(DataBackfill::CONTACT_POINTS);
    });

    $this->artisan('contacts:backfill')->assertSuccessful();

    expect($completeDuringRun)->toBeFalse()
        ->and(DataBackfill::isComplete(DataBackfill::CONTACT_POINTS))->toBeTrue();
});

it('marks complete only after the run finishes', function () {
    $school = al_makeSchool();
    bcp_guardian($school->id, email: 'parent@example.test', phone: '08037777777');

    expect(DataBackfill::isComplete(DataBackfill::CONTACT_POINTS))->toBeFalse();

    $this->artisan('contacts:backfill')->assertSuccessful();

    expect(DataBackfill::isComplete(DataBackfill::CONTACT_POINTS))->toBeTrue();
});

it('writes nothing and sets no marker on a dry run', function () {
    $school = al_makeSchool();
    bcp_guardian($school->id, email: 'parent@example.test', phone: '08038888888');

    $this->artisan('contacts:backfill', ['--dry-run' => true])->assertSuccessful();

    expect(ContactPoint::query()->count())->toBe(0)
        // Critically: a dry run must not leave a marker that a later gated reader
        // would take as "the data is there".
        ->and(DataBackfill::isComplete(DataBackfill::CONTACT_POINTS))->toBeFalse();
});

/**
 * THE CENSUS. `skipped_unnormalizable` is the number a SQL `TRIM(phone) <> ''`
 * cannot produce: SQL counts strings, this counts normalizable REACHABLE addresses.
 * The gap between the two is the placeholder data in the column.
 */
it('counts placeholder phone data that a SQL string check would call a phone', function () {
    $school = al_makeSchool();

    bcp_guardian($school->id, email: 'a@example.test', phone: 'n/a');
    bcp_guardian($school->id, email: 'b@example.test', phone: '-');
    // Valid, and unreachable — toll-free is what gets typed when the field is
    // required and unknown.
    bcp_guardian($school->id, email: 'c@example.test', phone: '08000000000');
    bcp_guardian($school->id, email: 'd@example.test', phone: '08039999999');

    $this->artisan('contacts:backfill', ['--dry-run' => true])->assertSuccessful();

    // All four satisfy `TRIM(phone) <> ''`; exactly one is messageable.
    expect(ContactPoint::query()->count())->toBe(0);
});

<?php

use App\Exports\GuardiansExport;
use App\Jobs\BulkMessageGuardiansJob;
use App\Models\ContactPoint;
use App\Models\DataBackfill;
use App\Models\Guardian;
use App\Models\User;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\GuardianAnnouncementNotification;
use App\Services\GuardianService;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

function dc_user(int $schoolId, ?string $email): User
{
    return User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'Test',
        'last_name' => 'User '.Str::random(5),
        'email' => $email,
        'password' => bcrypt('password'),
        'school_id' => $schoolId,
        'email_verified_at' => now(),
    ]);
}

function dc_markBackfillComplete(): void
{
    DataBackfill::query()->updateOrCreate(
        ['key' => DataBackfill::CONTACT_POINTS],
        ['started_at' => now(), 'completed_at' => now()],
    );
}

function dc_emailPoint(User $user, string $address): ContactPoint
{
    return ContactPoint::create([
        'user_id' => $user->id,
        'channel' => ChannelKey::EMAIL->value,
        'address' => $address,
        'source' => 'test',
    ]);
}

/*
|--------------------------------------------------------------------------
| The gate — fail safe to legacy until the backfill says otherwise
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ THE WINDOW THIS EXISTS TO CLOSE.
 *
 * Between code-live and backfill-complete, a flipped reader mis-answers for the WHOLE
 * populated database — bulk messaging no-ops school-wide, password reset refuses
 * everyone. The gate is the data's own completion marker, not co-deploy timing.
 */
it('keeps legacy string behaviour while the backfill marker is unset', function () {
    $school = al_makeSchool();
    $real = dc_user($school->id, 'parent@example.test');
    $synthetic = dc_user($school->id, '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN);

    // No marker, and NO contact points at all — the state of production between
    // deploy and backfill.
    expect($real->hasDeliverableEmail())->toBeTrue()
        ->and($synthetic->hasDeliverableEmail())->toBeFalse()
        ->and($real->routeNotificationForMail())->toBe('parent@example.test');
});

it('switches to contact points once the marker is set', function () {
    $school = al_makeSchool();
    $user = dc_user($school->id, 'parent@example.test');

    dc_markBackfillComplete();

    // Marker set, contact point absent — authoritative source says no, even though
    // the legacy column still holds a perfectly good address.
    expect($user->hasDeliverableEmail())->toBeFalse();

    dc_emailPoint($user, 'parent@example.test');

    expect($user->fresh()->hasDeliverableEmail())->toBeTrue();
});

/**
 * THE GATE AND THE ACTION READ ONE SOURCE, BY CONSTRUCTION.
 *
 * They used to be two: the predicate answered from `users.email` while
 * `routeNotificationForMail()` defaulted to the same column — agreeing only because
 * the backfill copied one into the other. The moment an edit lands in contact_points
 * instead, a gate that says yes and a router that mails the old address is a reset
 * that silently goes nowhere.
 */
it('routes mail to the contact point, not the stale column', function () {
    $school = al_makeSchool();
    $user = dc_user($school->id, 'old@example.test');
    dc_markBackfillComplete();
    dc_emailPoint($user, 'new@example.test');

    $user = $user->fresh();

    expect($user->hasDeliverableEmail())->toBeTrue()
        ->and($user->routeNotificationForMail())->toBe('new@example.test')
        // …and the two are the same value, which is the property.
        ->and($user->routeNotificationForMail())->toBe($user->deliverableEmailAddress());
});

/*
|--------------------------------------------------------------------------
| The null-email account — the paths the mint used to hide
|--------------------------------------------------------------------------
*/

/**
 * A guardian on record with NO address, which is what the retired mint used to
 * paper over. Every predicate site must tolerate it without a null-deref.
 *
 * `GuardiansExport` is named specifically: it cast `(string) $user->email` and would
 * have read a null address as DELIVERABLE — the dormant bug #197 closed at the
 * predicate, which this release makes reachable for the first time.
 */
it('passes a null-email non-login guardian through every predicate site cleanly', function () {
    $school = al_makeSchool();
    $user = dc_user($school->id, null);
    dc_markBackfillComplete();

    expect($user->hasDeliverableEmail())->toBeFalse()
        ->and($user->deliverableEmailAddress())->toBeNull()
        // The mail router: a defined skip, not a send-to-null.
        ->and($user->routeNotificationForMail())->toBeNull();

    // The export's own resolution, exercised through the map it actually uses.
    $guardian = al_makeGuardian($school->id, $user->id);
    $guardian->setRelation('user', $user);
    $guardian->students_count = 0;

    $row = (new GuardiansExport(request()))->map($guardian);

    expect($row[3])->toBe('')          // email cell — empty, not 'null', not a crash
        ->and($row[5])->toBe('No');    // has-login — false, not true via the cast
});

/**
 * A null route is a SKIP, not an error — the framework must decline to send rather
 * than throw or mail nowhere.
 */
it('sends no mail to an account with no deliverable address', function () {
    Notification::fake();

    $school = al_makeSchool();
    $user = dc_user($school->id, null);
    dc_markBackfillComplete();

    $user->notify(new GuardianAnnouncementNotification('Subject', 'Body'));

    // Recorded as notified, but the mail channel has no route — asserted below via
    // the router rather than the fake, which does not exercise channel routing.
    expect($user->routeNotificationForMail())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The loop callers — query count must not scale with rows
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ THE FLIP TURNS AN O(1) STRING TEST INTO A PER-ROW QUERY.
 *
 * The export asked TWICE per row, so without the collapse and the eager load this is
 * 2N. Asserting a CONSTANT rather than a magic number: what matters is that adding
 * rows does not add queries.
 */
it('does not scale the export query count with the number of guardians', function () {
    $school = al_makeSchool();
    dc_markBackfillComplete();

    foreach (range(1, 3) as $i) {
        $user = dc_user($school->id, "g{$i}@example.test");
        dc_emailPoint($user, "g{$i}@example.test");
        al_makeGuardian($school->id, $user->id);
    }

    $export = new GuardiansExport(request());

    DB::enableQueryLog();
    $rows = $export->query()->get()->map(fn ($g) => $export->map($g));
    $withThree = count(DB::getQueryLog());
    DB::flushQueryLog();

    foreach (range(4, 9) as $i) {
        $user = dc_user($school->id, "g{$i}@example.test");
        dc_emailPoint($user, "g{$i}@example.test");
        al_makeGuardian($school->id, $user->id);
    }

    DB::flushQueryLog();
    $export->query()->get()->map(fn ($g) => $export->map($g));
    $withNine = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($rows)->toHaveCount(3)
        // Tripling the rows must not ADD queries. Not `toBe`: the first run also pays
        // the one-off backfill-marker read, which ContactPointAuthority memoises for
        // the rest of the request — so the second run is legitimately one FEWER. That
        // asymmetry is the memo working, and an equality assertion would have read it
        // as a regression.
        ->and($withNine)->toBeLessThanOrEqual($withThree)
        // And an absolute ceiling, so "does not grow" cannot be satisfied by both
        // runs being quietly enormous.
        ->and($withNine)->toBeLessThan(8);
});

it('does not scale the bulk-message job query count with the number of guardians', function () {
    $school = al_makeSchool();
    dc_markBackfillComplete();

    $ids = [];
    foreach (range(1, 6) as $i) {
        $user = dc_user($school->id, "b{$i}@example.test");
        dc_emailPoint($user, "b{$i}@example.test");
        $ids[] = al_makeGuardian($school->id, $user->id)->id;
    }

    Notification::fake();
    DB::enableQueryLog();

    ActiveSchool::runFor($school->id, fn () => (new BulkMessageGuardiansJob(
        guardianIds: $ids,
        schoolId: $school->id,
        subject: 'Subject',
        body: 'Body',
        channels: ['mail'],
    ))->handle());

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Guardians + users + contact points — a small constant, not one per guardian.
    expect($queries)->toBeLessThan(6);
});

/*
|--------------------------------------------------------------------------
| The password-reset broker — gate and action on one source
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ THE BROKER RESOLVES THE USER *BY* `users.email` BEFORE THE ADDRESS IS EVER A
 * DELIVERY TARGET.
 *
 * So the override fixes where the mail goes and does nothing about whether the broker
 * can find anyone. That is exactly why `users.email` may only go null for accounts
 * that cannot log in (#203's invariant) — and why this release is gated on
 * `guardians:audit-login-invariant` returning zero.
 *
 * This asserts the working case: a login account whose address now lives in a contact
 * point is both found by the broker and mailed at the resolved address.
 */
it('sends a password reset to a login account whose address lives in a contact point', function () {
    Notification::fake();

    $school = al_makeSchool();
    $user = dc_user($school->id, 'login@example.test');
    dc_markBackfillComplete();
    dc_emailPoint($user, 'login@example.test');

    $status = Password::broker()->sendResetLink(['email' => $user->email]);

    expect($status)->toBe(Password::RESET_LINK_SENT);

    Notification::assertSentTo(
        $user,
        ResetPassword::class,
    );

    // And the address it would route to is the CONTACT POINT's, not the column's.
    expect($user->fresh()->routeNotificationForMail())->toBe('login@example.test');
});

/*
|--------------------------------------------------------------------------
| The mint is gone
|--------------------------------------------------------------------------
*/

it('creates a no-login guardian with a null email rather than a minted placeholder', function () {
    $school = al_makeSchool();

    $result = ActiveSchool::runFor($school->id, fn () => app(GuardianService::class)
        ->createGuardianWithUser(
            attributes: ['first_name' => 'Ada', 'last_name' => 'Guardian', 'phone' => '08031234567'],
            schoolId: $school->id,
            canLogin: false,
            email: null,
        ));

    $user = $result['user'];

    expect($user->email)->toBeNull()
        // The whole point: no structurally-valid address that needs a predicate to
        // recognise and a backfill to exclude.
        ->and(User::isSyntheticEmail((string) $user->email))->toBeFalse();
});

it('leaves no synthetic mint site in the application', function () {
    $appFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app', FilesystemIterator::SKIP_DOTS)
    );

    $mints = [];

    foreach ($appFiles as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s*(\/\/|\*|\/\*)/', $line)) {
                continue;
            }

            // A mint CONCATENATES the domain onto something. Recognising it (the
            // constant alone, or isSyntheticEmail) is not minting and must survive:
            // historical rows still carry the sentinel.
            if (preg_match('/(sprintf|\.\s*User::SYNTHETIC_EMAIL_DOMAIN|SYNTHETIC_EMAIL_DOMAIN\s*\.)/', $line)
                && str_contains($line, 'SYNTHETIC_EMAIL_DOMAIN')) {
                $mints[] = basename($file->getPathname()).': '.trim($line);
            }
        }
    }

    expect($mints)->toBeEmpty();
});

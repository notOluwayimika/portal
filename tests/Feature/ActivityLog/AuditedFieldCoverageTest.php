<?php

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\GuardianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * What the audit trail records, per model.
 *
 * These are written against the CHANGE, not against a field list: each asserts that
 * altering a security- or identity-relevant column produces a row naming that
 * column. A test that asserted the logOnly array's contents would pass while
 * recording nothing, because `logOnly` with a typo is still a valid array.
 */
function afc_latestActivityFor(object $model): ?Activity
{
    return Activity::query()
        ->where('subject_type', $model::class)
        ->where('subject_id', $model->getKey())
        ->latest('id')
        ->first();
}

function afc_changedKeys(?Activity $activity): array
{
    $properties = $activity?->properties?->toArray() ?? [];

    return array_keys($properties['attributes'] ?? []);
}

it('records an email change on a user account', function () {
    $user = al_makeUser(al_makeSchool()->id);

    $user->email = 'changed-'.Str::random(6).'@example.test';
    $user->save();

    // The Tier-1 account-security signal the notification layer reads. Before this
    // change `User` logged `password` and nothing else, so an email takeover left
    // no trace at all.
    expect(afc_changedKeys(afc_latestActivityFor($user)))->toContain('email');
});

it('records enrolling and confirming two-factor authentication', function () {
    $user = al_makeUser(al_makeSchool()->id);

    // al_makeUser() already sets two_factor_confirmed_at, so re-setting it to the
    // same instant is not dirty and would silently assert nothing. Enrol from a
    // clean slate instead — which is also the real transition.
    $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

    $user->forceFill([
        'two_factor_secret' => encrypt('NEWSECRET'),
        'two_factor_confirmed_at' => now()->addMinute(),
    ])->save();

    $keys = afc_changedKeys(afc_latestActivityFor($user));

    expect($keys)->toContain('two_factor_secret')
        ->and($keys)->toContain('two_factor_confirmed_at');
});

it('records a teacher phone change, which is a delivery address', function () {
    $school = al_makeSchool();
    $teacher = Teacher::create([
        'school_id' => $school->id,
        'user_id' => al_makeUser($school->id)->id,
        'first_name' => 'Ada',
        'last_name' => 'Teacher',
        'phone' => '08000000000',
    ]);

    $teacher->phone = '08111111111';
    $teacher->save();

    // Teacher had NO LogsActivity at all before this change, so every one of its
    // fields was invisible to the trail.
    expect(afc_changedKeys(afc_latestActivityFor($teacher)))->toContain('phone');
});

it('records a change to a pupil identity field', function () {
    $school = al_makeSchool();
    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Ada',
        'last_name' => 'Pupil',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
        'date_of_birth' => '2015-01-01',
    ]);

    $student->date_of_birth = '2015-02-02';
    $student->save();

    expect(afc_changedKeys(afc_latestActivityFor($student)))->toContain('date_of_birth');
});

it('does not record cosmetic fields', function () {
    $school = al_makeSchool();
    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Ada',
        'last_name' => 'Pupil',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
        // Set EXPLICITLY. Omitted, `status` is filled by the column default, so the
        // model's in-memory null becomes 'active' on the next save and writes a
        // perfectly correct audit row — which would be mistaken here for the
        // cosmetic change failing to be excluded.
        'status' => 'active',
    ]);
    $student->refresh();

    $before = Activity::query()->count();

    // `previous_school` is not in the audited set — logging every column would put
    // real volume into the trail for nothing anyone can act on.
    $student->previous_school = 'Somewhere Primary';
    $student->save();

    expect(Activity::query()->count())->toBe($before);
});

/**
 * THE GAP THAT WAS ACTUALLY MISSING. `detached`, `login_enabled`, `login_disabled`
 * and `pivot_updated` were already logged by GuardianService::logPivotEvent —
 * creating the link was not. So "who took this adult's access away" was answerable
 * and "who gave it to them" was not.
 *
 * Spatie logs MODEL attributes and cannot observe a pivot write, so no amount of
 * configuration would have covered this; it needs the explicit call.
 */
it('records the guardian-to-student link being created, not only removed', function () {
    $school = al_makeSchool();
    $guardian = al_makeGuardian($school->id, al_makeUser($school->id)->id);
    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Ada',
        'last_name' => 'Pupil',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    app(GuardianService::class)->attachToStudent($guardian, $student, 'parent', true, false);

    $activity = Activity::query()
        ->where('log_name', 'guardian')
        ->where('event', 'attached')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['student_id'])->toBe($student->id)
        ->and($activity->subject_id)->toBe($guardian->id);
});

it('does not log a second attach when the link already exists', function () {
    $school = al_makeSchool();
    $guardian = al_makeGuardian($school->id, al_makeUser($school->id)->id);
    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Ada',
        'last_name' => 'Pupil',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    $service = app(GuardianService::class);
    $service->attachToStudent($guardian, $student, 'parent', true, false);
    $service->attachToStudent($guardian, $student, 'guardian', true, false);

    // Re-linking an existing pair is an UPDATE of the pivot, and is already
    // recorded as `pivot_updated`. Logging it as `attached` a second time would
    // make the trail claim access was granted twice.
    expect(Activity::query()->where('event', 'attached')->count())->toBe(1);
});

/**
 * The reason PR #195 must land first. `User` now logs the 2FA secret, so without
 * write-time redaction a live TOTP seed reaches the column — the same defect the
 * password hash had, on a secret that is directly usable rather than merely
 * crackable.
 */
it('never lets the two-factor secret reach the column', function () {
    // THE GATE IS A BEHAVIOURAL PROBE, not a substring match on a method name.
    //
    // The first version grepped app/Models/Activity.php for `maskProperties`. That
    // makes the safety property depend on a NAME: rename the method in #195 and this
    // test does not fail, it SKIPS — the quietest possible green for a load-bearing
    // guarantee, and nothing anywhere enforces that the name stays put. Writing a
    // throwaway activity and looking at what survives tests the property itself.
    //
    // Inside the test body rather than in ->skip(), so it runs with the container
    // booted and inside RefreshDatabase's transaction — the probe row is rolled back.
    $probe = activity()
        ->withProperties(['attributes' => ['password' => 'redaction-probe']])
        ->log('redaction-probe');

    $probeRaw = (string) DB::table('activity_log')->where('id', $probe->id)->value('properties');

    if (str_contains($probeRaw, 'redaction-probe')) {
        $this->markTestSkipped(
            'Write-time redaction is absent — this branch must merge AFTER #195 '
            .'(fix/activity-log-password-hash-exposure). See the PR description.'
        );
    }

    $user = al_makeUser(al_makeSchool()->id);

    $user->forceFill(['two_factor_secret' => encrypt('LIVETOTPSEED')])->save();

    $raw = (string) DB::table('activity_log')
        ->where('id', afc_latestActivityFor($user)?->id)
        ->value('properties');

    $properties = json_decode($raw, true);

    // ASSERT THE VALUE AT THE KEY, not two independent substrings over the whole
    // blob. `toContain('two_factor_secret')` plus `toContain('***')` passes whenever
    // the key appears ANYWHERE and a mask token appears ANYWHERE — which coincide
    // today only because this fixture happens to dirty one redacted field. Let the
    // fixture touch a second masked column (it nearly does: the 2FA test above nulls
    // and re-sets `two_factor_confirmed_at` too) and the pair goes green while the
    // seed sits in the column unmasked, under a test still named for preventing that.
    expect($properties['attributes']['two_factor_secret'] ?? null)->toBe('***');

    // Deliberately NOT `not->toContain('LIVETOTPSEED')`: the column stores the
    // CIPHERTEXT of the seed, so a plaintext-absence assertion is green with zero
    // redaction in place. The key-value form sidesteps that entirely.
});

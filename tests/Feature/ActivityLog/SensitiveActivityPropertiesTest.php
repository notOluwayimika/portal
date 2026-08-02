<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * Credential material must never reach `activity_log.properties`.
 *
 * The read-time mask in ActivitySensitiveService was never a control over data at
 * rest: it hides values on screen while the column holds the real thing, so every
 * mysqldump, phpMyAdmin export and backup carried live bcrypt hashes. These tests
 * pin the WRITE side.
 */
function sap_rawProperties(int $activityId): string
{
    // Read the COLUMN, not the model. Going through Eloquent would exercise the
    // cast and, in the read path, the masking service — the very layer these tests
    // must not rely on. What matters is what a dump would contain.
    return (string) DB::table('activity_log')->where('id', $activityId)->value('properties');
}

it('never writes a password hash when a user is created', function () {
    $user = al_makeUser(al_makeSchool()->id);

    $activity = Activity::query()
        ->where('subject_type', User::class)
        ->where('subject_id', $user->id)
        ->latest('id')
        ->firstOrFail();

    $raw = sap_rawProperties((int) $activity->id);

    // `created` was the larger half of the exposure — every signup wrote one.
    expect($raw)->not->toContain('$2y$')
        ->and($raw)->not->toContain($user->getAuthPassword());
});

it('never writes a password hash when a password changes, but still records that it changed', function () {
    $user = al_makeUser(al_makeSchool()->id);

    $user->password = bcrypt('a-new-password');
    $user->save();

    $activity = Activity::query()
        ->where('subject_type', User::class)
        ->where('subject_id', $user->id)
        ->where('description', 'updated')
        ->latest('id')
        ->firstOrFail();

    $raw = sap_rawProperties((int) $activity->id);

    expect($raw)->not->toContain('$2y$');

    // THE AUDIT SIGNAL SURVIVES. Masking replaces the value and keeps the key, so
    // the trail still answers "whose password changed, and when" — which is all it
    // legitimately needs. A fix that dropped the row entirely would trade a
    // confidentiality problem for an accountability one.
    expect($raw)->toContain('password')
        ->and($raw)->toContain('***');
});

it('redacts every configured sensitive field, not only passwords', function () {
    $activity = activity()
        ->withProperties([
            'attributes' => [
                'two_factor_secret' => 'OTPSECRETVALUE',
                'remember_token' => Str::random(60),
                'api_token' => 'tok_live_12345',
                // A non-sensitive neighbour, to prove the mask is targeted rather
                // than a blanket wipe of the properties bag.
                'first_name' => 'Ada',
            ],
        ])
        ->log('probe');

    $raw = sap_rawProperties((int) $activity->id);

    expect($raw)->not->toContain('OTPSECRETVALUE')
        ->and($raw)->not->toContain('tok_live_12345')
        ->and($raw)->toContain('Ada');
});

it('redacts nested properties, not just the top level', function () {
    // Spatie's own shape is nested — {"attributes": {...}, "old": {...}} — so a
    // top-level-only mask would have missed every real case.
    $activity = activity()
        ->withProperties([
            'old' => ['password' => '$2y$12$oldhashvalueoldhashvalueoldhash'],
            'attributes' => ['password' => '$2y$12$newhashvaluenewhashvaluenewhash'],
        ])
        ->log('probe');

    expect(sap_rawProperties((int) $activity->id))->not->toContain('$2y$');
});

/*
|--------------------------------------------------------------------------
| THE SCRUB OF EXISTING ROWS IS NOT IN THIS BRANCH — deliberately.
|--------------------------------------------------------------------------
|
| `activity_log` is append-only, enforced by DATABASE TRIGGERS
| (`activity_log_no_update` / `activity_log_no_delete`, Constitution §15C). A
| scrub is by definition an UPDATE, so it cannot run without first dropping a
| constitutional guarantee — and this repo has already ruled on that tension in
| the other direction: BackfillActivityLogSchoolId DETECTS the trigger and
| refuses rather than lifting it.
|
| Suspending §15C is the project lead's call, not a migration author's, so the
| remediation of the ~183 pre-existing rows is proposed rather than performed.
| The write-time fix above stops the exposure GROWING, which is the half that can
| be made safely without a policy decision.
*/

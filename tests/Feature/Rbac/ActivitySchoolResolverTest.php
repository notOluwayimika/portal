<?php

use App\Support\ActiveSchool;
use App\Support\ActivitySchoolResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

/**
 * S7 Step 2: audit attribution resolves through ActiveSchool, so an
 * ActiveSchool::runFor() override (queue workers / off-request jobs) is honoured
 * instead of the resolver reading a stale session or users.school_id directly.
 */
uses(RefreshDatabase::class);

it('attributes a new activity to the ActiveSchool::runFor override', function () {
    $school = al_makeSchool();
    $resolver = new ActivitySchoolResolver;

    // Off-request context (no session, no auth) — exactly a queue worker. The
    // only signal is the runFor override, which must win.
    $resolved = ActiveSchool::runFor($school->id, fn () => $resolver->resolveForNewActivity(new Activity));

    expect((int) $resolved)->toBe($school->id);
});

it('falls back to causer/subject school when no active context exists', function () {
    $school = al_makeSchool();
    $resolver = new ActivitySchoolResolver;

    // No override, no session, no auth → resolveFromRelations. A subject carrying
    // school_id supplies it.
    $activity = new Activity;
    $activity->setRelation('subject', al_makeGuardian($school->id, al_makeUser($school->id)->id));

    expect((int) $resolver->resolveForNewActivity($activity))->toBe($school->id);
});

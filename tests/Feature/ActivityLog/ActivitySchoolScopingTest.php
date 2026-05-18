<?php

use App\Models\Activity;
use App\Models\Guardian;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds the school_id column to activity_log', function () {
    expect(Schema::hasColumn('activity_log', 'school_id'))->toBeTrue();
});

it('auto-populates school_id from the request/session context', function () {
    $school = al_makeSchool();
    session(['school_id' => $school->id]);

    activity()->log('something happened in a request');

    expect(Activity::latest('id')->first()->school_id)->toEqual($school->id);
});

it('resolves school_id from the causer when there is no session context', function () {
    $school = al_makeSchool();
    $user   = al_makeUser($school->id);

    activity()->causedBy($user)->log('caused by a user, no session');

    expect(Activity::latest('id')->first()->school_id)->toEqual($school->id);
});

it('resolves school_id from the subject when there is no causer', function () {
    $school   = al_makeSchool();
    $user     = al_makeUser($school->id);
    $guardian = al_makeGuardian($school->id, $user->id);

    activity()->performedOn($guardian)->log('subject only');

    expect(Activity::latest('id')->first()->school_id)->toEqual($school->id);
});

it('writes null and logs to the untagged channel when school_id is unresolvable', function () {
    Log::shouldReceive('channel')->with('activity-log-untagged')->once()->andReturnSelf();
    Log::shouldReceive('warning')->once();

    activity()->log('no session, no causer, no subject');

    expect(Activity::latest('id')->first()->school_id)->toBeNull();
});

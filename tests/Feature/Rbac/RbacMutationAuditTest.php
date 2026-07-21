<?php

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new RbacSeeder)->run();
});

/**
 * C1: privilege escalation must leave a trace (v10 §7.5). Channel bite-proof
 * discipline per the brief: probe-write FIRST and confirm the row appears —
 * only then is an absence trusted as an absence.
 */
it('writes a durable rbac activity row when a role grants a permission (probe the channel)', function () {
    $teacher = Role::where('name', 'teacher')->where('guard_name', 'web')->first();

    $teacher->givePermissionTo('guardian.view');

    $row = Activity::where('log_name', 'rbac')->where('event', 'permission_attached')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->properties['permissions'])->toContain('guardian.view')
        ->and($row->subject_id)->toBe($teacher->id);
});

it('logs role attachment to a user with School attribution from the active team', function () {
    $school = School::factory()->create();
    $user = User::factory()->create();

    setPermissionsTeamId($school->id);
    session(['school_id' => $school->id]);

    $user->assignRole('teacher');

    $row = Activity::where('log_name', 'rbac')->where('event', 'role_attached')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->properties['roles'])->toContain('teacher')
        ->and((int) $row->properties['team_school_id'])->toBe($school->id)
        ->and((int) $row->school_id)->toBe($school->id);
});

it('attributes off-request role mutations to the runFor School, not null', function () {
    $schoolB = School::factory()->create();
    $user = User::factory()->create();

    ActiveSchool::runFor($schoolB->id, function () use ($user) {
        $user->assignRole('teacher');
    });

    $row = Activity::where('log_name', 'rbac')->where('event', 'role_attached')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->school_id)->toBe($schoolB->id)
        ->and((int) $row->properties['active_school_id'])->toBe($schoolB->id);
});

it('flushes the accessibleSchoolIds memo on role attach (single-source path proves it end-to-end)', function () {
    config(['rbac.single_source_access' => true]);

    $school = School::factory()->create();
    $user = User::factory()->create(['school_id' => null]);

    // Warm the memo while the user has no roles anywhere.
    expect($user->accessibleSchoolIds()->all())->toBe([]);

    setPermissionsTeamId($school->id);
    $user->assignRole('teacher');

    // Without the listener's flush, the warmed memo would still say [].
    expect($user->accessibleSchoolIds()->all())->toContain($school->id);
});

it('records role model row changes (rename) in the rbac log', function () {
    $role = Role::where('name', 'registrar')->where('guard_name', 'web')->first();

    $role->update(['name' => 'registrar_renamed']);

    $row = Activity::where('log_name', 'rbac')->where('event', 'updated')
        ->where('subject_type', Role::class)->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->properties['attributes']['name'])->toBe('registrar_renamed');
});

it('stays silent during seeding — an RbacSeeder run writes zero NEW rbac rows', function () {
    // The channel was probe-proven live above, so an unchanged count is a real
    // silence, not a disabled channel. (Counting a delta, not deleting: the
    // 1.4c DB trigger denies DELETE on activity_log — attempting it here is
    // how this test originally discovered the migration-time assignRole row.)
    $before = Activity::where('log_name', 'rbac')->count();

    (new RbacSeeder)->run();

    expect(Activity::where('log_name', 'rbac')->count())->toBe($before);
});

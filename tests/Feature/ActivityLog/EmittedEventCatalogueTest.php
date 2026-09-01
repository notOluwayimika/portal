<?php

use App\Finance\Models\StudentDiscountAward;
use App\Models\Activity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLog\ActivitySensitiveService;
use App\Services\ActivityLog\ActivitySeverityService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The catalogue arms that start from the EMITTED side.
 *
 * tests/Feature/ActivityLog/ActivityLogApiTest.php already asserts that the
 * resolver maps a DECLARED key to the intended tier, and it passed throughout
 * the period in which three declared keys matched no emitter at all. It proves
 * the resolver works; it proves nothing about whether any row carries the key
 * it is handed. 1,800 rows sat misclassified underneath a green suite.
 *
 * So every arm here CAUSES a real event — a real role attach, a real failed
 * login, a real password reset — reads `log_name` and `event` back OFF THE ROW
 * the emitter wrote, and asserts THAT pair resolves to the intended tier and
 * sensitivity. Nothing here names a key on the emitting side; a rename of an
 * emitted event reds these without touching the catalogue, and a wrong
 * catalogue key reds these without touching the emitters.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

beforeEach(function () {
    foreach ([
        'academic_data.view',
        'activity_log.view', 'activity_log.view_all',
        'activity_log.view_system', 'activity_log.view_sensitive',
    ] as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    foreach (['admin', 'teacher'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    // The two viewer roles, built HERE and not inside ec_viewer(), because
    // `Role` carries BelongsToSchool: created once a school is active it picks
    // up that school_id and then resolves only under a matching team, which the
    // request's own team context is not. Created before any school exists they
    // are team-less, like `admin` and `teacher`, and resolve everywhere.
    //
    // Two roles rather than one re-granted role: grantsMap already grants
    // `admin` activity_log.view_sensitive — database/seeders/RbacSeeder.php:150 (grantsMap)
    // — so a "plain" viewer built on a shared role holds the clearance these
    // arms exist to withhold.
    // `academic_data.view` is the route-level gate every /api/activity-logs
    // route carries (Spatie PermissionMiddleware); without it the request is
    // refused before any controller check and the arm reports 403 where it
    // means to distinguish 404 from 200.
    Role::firstOrCreate(['name' => 'ec_plain', 'guard_name' => 'web'])
        ->givePermissionTo([
            'academic_data.view',
            'activity_log.view', 'activity_log.view_all', 'activity_log.view_system',
        ]);

    Role::firstOrCreate(['name' => 'ec_cleared', 'guard_name' => 'web'])
        ->givePermissionTo([
            'academic_data.view',
            'activity_log.view', 'activity_log.view_all',
            'activity_log.view_system', 'activity_log.view_sensitive',
        ]);
});

/** Resolve tier + sensitivity from the row itself, never from a literal key. */
function ec_classify(Activity $row): array
{
    return [
        'severity' => ActivitySeverityService::make()->for($row->log_name, $row->event),
        'sensitive' => ActivitySensitiveService::make()->isSensitiveEntry($row->log_name, $row->event),
    ];
}

/**
 * A viewer holding activity_log.view (+ view_all, view_system) and holding
 * view_sensitive only when asked for.
 */
function ec_viewer(int|string $schoolId, bool $sensitive): User
{
    $u = al_makeUser($schoolId);
    setPermissionsTeamId($schoolId);

    $u->unsetRelation('roles');
    $u->assignRole($sensitive ? 'ec_cleared' : 'ec_plain');

    // BOTH halves. Asserting only "view_sensitive is false" for the plain
    // viewer is degenerate — it passes just as well when the viewer holds no
    // permissions at all, which is the state that silently turned a 404 arm
    // into a 403 while the negative assertion stayed green.
    $fresh = $u->fresh();
    expect($fresh->can('activity_log.view'))->toBeTrue()
        ->and($fresh->can('activity_log.view_sensitive'))->toBe($sensitive);

    return $u;
}

it('classifies a REAL role attach as critical and sensitive', function () {
    $school = al_makeSchool();
    $subject = al_makeUser($school->id);

    setPermissionsTeamId($school->id);
    session(['school_id' => $school->id]);

    $subject->assignRole('teacher');

    $row = Activity::where('subject_id', $subject->id)->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->log_name)->toBe('rbac')
        ->and($row->event)->toBe('role_attached');

    expect(ec_classify($row))->toBe(['severity' => 'critical', 'sensitive' => true]);
});

it('classifies a REAL role detach as critical and sensitive', function () {
    $school = al_makeSchool();
    $subject = al_makeUser($school->id);

    setPermissionsTeamId($school->id);
    session(['school_id' => $school->id]);

    $subject->assignRole('teacher');
    $subject->removeRole('teacher');

    $row = Activity::where('subject_id', $subject->id)
        ->where('event', 'role_detached')->latest('id')->first();

    expect($row)->not->toBeNull()->and($row->log_name)->toBe('rbac');
    expect(ec_classify($row))->toBe(['severity' => 'critical', 'sensitive' => true]);
});

it('classifies a REAL role->permission grant as critical and sensitive', function () {
    $school = al_makeSchool();

    setPermissionsTeamId($school->id);
    session(['school_id' => $school->id]);

    $permission = Permission::firstOrCreate(['name' => 'activity_log.view', 'guard_name' => 'web']);
    Role::findByName('teacher')->givePermissionTo($permission);

    $row = Activity::where('log_name', 'rbac')
        ->where('event', 'permission_attached')->latest('id')->first();

    expect($row)->not->toBeNull();
    expect(ec_classify($row))->toBe(['severity' => 'critical', 'sensitive' => true]);
});

it('classifies a REAL failed login as warning', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);

    $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password']);

    $row = Activity::where('log_name', 'auth')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->event)->toBe('failed_login');

    // WARNING, not sensitive: a failed attempt is the signal the people
    // holding activity_log.view are there to watch.
    expect(ec_classify($row))->toBe(['severity' => 'warning', 'sensitive' => false]);
});

it('classifies a REAL password reset as warning and sensitive', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);

    session(['school_id' => $school->id]);

    event(new PasswordReset($user));

    $row = Activity::where('causer_id', $user->id)->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->log_name)->toBe('authentication')
        ->and($row->event)->toBe('password_reset');

    expect(ec_classify($row))->toBe(['severity' => 'warning', 'sensitive' => true]);
});

it('hides a REAL privilege grant from activity_log.view without view_sensitive', function () {
    $school = al_makeSchool();
    $subject = al_makeUser($school->id);

    setPermissionsTeamId($school->id);
    session(['school_id' => $school->id]);

    $subject->assignRole('teacher');
    $row = Activity::where('subject_id', $subject->id)
        ->where('event', 'role_attached')->latest('id')->firstOrFail();

    // Addressed by ROW ID, not by a listing total: the fixture's own setup
    // writes rbac rows of its own, and a count cannot tell "this row" from
    // "some other rbac row".
    $plain = ec_viewer($school->id, sensitive: false);
    $this->actingAs($plain)->getJson("/api/activity-logs/{$row->id}")->assertNotFound();

    $cleared = ec_viewer($school->id, sensitive: true);
    $this->actingAs($cleared)->getJson("/api/activity-logs/{$row->id}")->assertOk();
});

it('hides a REAL password reset from activity_log.view without view_sensitive', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);

    session(['school_id' => $school->id]);
    event(new PasswordReset($user));

    $row = Activity::where('log_name', 'authentication')->latest('id')->firstOrFail();

    $plain = ec_viewer($school->id, sensitive: false);
    $this->actingAs($plain)->getJson("/api/activity-logs/{$row->id}")->assertNotFound();

    $cleared = ec_viewer($school->id, sensitive: true);
    $this->actingAs($cleared)->getJson("/api/activity-logs/{$row->id}")->assertOk();
});

it('counts failed_logins_24h against the EMITTED event name only', function () {
    $school = al_makeSchool();
    $viewer = ec_viewer($school->id, sensitive: true);

    session(['school_id' => $school->id]);

    // `created_at` is set at INSERT: activity_log carries the append-only
    // trigger (Constitution §15C), so back-dating a row with a follow-up
    // UPDATE raises 1644.
    $make = function (string $logName, string $event, $at) use ($school) {
        Activity::forceCreate([
            'log_name' => $logName,
            'description' => 'seeded',
            'event' => $event,
            'school_id' => $school->id,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    };

    // Three that must be counted.
    $make('auth', 'failed_login', now()->subHours(1));
    $make('auth', 'failed_login', now()->subHours(5));
    $make('auth', 'failed_login', now()->subHours(20));

    // Four decoys, one per way the count has been or could be wrong. The
    // expectation is a LITERAL 3, never derived from these rows.
    $make('auth', 'failed_login', now()->subDays(3));          // outside the 24h window
    $make('auth', 'login_failed', now()->subHours(2));         // the transposed name the tile used to match
    $make('authentication', 'failed_login', now()->subHours(2)); // right event, wrong log name
    $make('auth', 'login', now()->subHours(2));                // a successful login

    $res = $this->actingAs($viewer)->getJson('/api/activity-logs/stats')->assertOk();

    expect($res->json('data.failed_logins_24h'))->toBe(3);
});

it('files the StudentDiscountAward model row under the same log name as its Action', function () {
    // AwardStudentDiscount:274 writes activity('finance'). Without
    // useLogName('finance') on the model the two halves of one award split
    // across `finance` and `default`.
    // `LogOptions::$logName` is the exact field Spatie reads —
    // LogsActivity::getLogNameToUse() returns `$this->activitylogOptions->logName`
    // and otherwise falls through to config('activitylog.default_log_name')
    // (vendor/spatie/laravel-activitylog/src/Traits/LogsActivity.php:130-137).
    // The property is protected, so the option object is read directly rather
    // than through the accessor.
    $options = (new StudentDiscountAward)->getActivitylogOptions();

    // Both halves. `toBe('finance')` alone would still pass if `finance` ever
    // became the default log name, which is the state this arm exists to rule
    // out: the 23 siblings carrying `protected static $logName` all resolve to
    // the default and read as configured.
    expect($options->logName)->toBe('finance')
        ->and($options->logName)->not->toBe(config('activitylog.default_log_name'));
});

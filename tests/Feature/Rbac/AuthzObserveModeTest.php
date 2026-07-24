<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Authz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Proves observe mode is not silent by construction: a user lacking the
 * permission hits a wired route, the would-be denial is recorded with all fields,
 * and the request STILL SUCCEEDS (enforcement is off).
 */
uses(RefreshDatabase::class);

/**
 * C2: the route under test is now permission:student_status.view. Grant the
 * ROUTE-tier permission to the ad-hoc admin role so requests reach the
 * observe-mode layer under test — the fine-grained guardian.view stays
 * ungranted, which is the condition these tests exercise.
 */
function om_grantRouteAccess(): void
{
    Permission::firstOrCreate(['name' => 'student_status.view', 'guard_name' => 'web']);
    Role::findByName('admin', 'web')->givePermissionTo('student_status.view');
}

it('records a would-be denial and lets the request continue (observe mode)', function () {
    config(['authz.enforce' => false]);

    $school = al_makeSchool();
    // A real user who can access the School (so routing/middleware pass) but who
    // holds NO guardian.view permission — exactly the S5 target.
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'admin'); // 'admin' role holds no seeded permissions here
    om_grantRouteAccess();
    $user->flushSchoolAccessCache();

    $guardianUser = al_makeUser($school->id);
    $guardian = al_makeGuardian($school->id, $guardianUser->id);

    expect($user->can('guardian.view'))->toBeFalse(); // precondition: would be denied

    $response = $this->actingAs($user)
        ->withSession(['school_id' => $school->id])
        ->getJson("/api/guardians/{$guardian->uuid}/students");

    // 1. The request SUCCEEDED (not blocked) — observe mode never aborts.
    $response->assertOk();

    // 2. Exactly one observation was recorded, with every field correct.
    $obs = DB::table('authz_observations')->get();
    expect($obs)->toHaveCount(1);

    $row = $obs->first();
    expect($row->user_id)->toBe($user->id)
        ->and((int) $row->school_id)->toBe($school->id)
        ->and($row->ability)->toBe('guardian.view')
        ->and($row->check_type)->toBe('permission')
        ->and($row->controller_action)->toBe('GuardianController@students')
        ->and($row->transport)->toBe('api')
        ->and($row->method)->toBe('GET')
        ->and($row->request_uri)->toContain("/api/guardians/{$guardian->uuid}/students")
        ->and(json_decode($row->roles, true))->toContain('admin')
        ->and($row->occurred_at)->not->toBeNull();
});

it('records the path only — never the query string (data minimization)', function () {
    config(['authz.enforce' => false]);

    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'admin');
    om_grantRouteAccess();
    $user->flushSchoolAccessCache();
    $guardian = al_makeGuardian($school->id, al_makeUser($school->id)->id);

    // Include a query string carrying would-be PII.
    $this->actingAs($user)->withSession(['school_id' => $school->id])
        ->getJson("/api/guardians/{$guardian->uuid}/students?email=secret%40example.com&q=jane")
        ->assertOk();

    $uri = DB::table('authz_observations')->value('request_uri');
    expect($uri)->toContain("/api/guardians/{$guardian->uuid}/students")
        ->and($uri)->not->toContain('?')
        ->and($uri)->not->toContain('secret')
        ->and($uri)->not->toContain('email=');
});

it('does NOT record when the user holds the permission', function () {
    config(['authz.enforce' => false]);

    $school = al_makeSchool();
    Permission::firstOrCreate(['name' => 'guardian.view', 'guard_name' => 'web']);
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'admin');           // creates + assigns the admin role
    om_grantRouteAccess();
    Role::findByName('admin', 'web')->givePermissionTo('guardian.view');
    $user->flushSchoolAccessCache();

    $guardian = al_makeGuardian($school->id, al_makeUser($school->id)->id);

    $this->actingAs($user)->withSession(['school_id' => $school->id])
        ->getJson("/api/guardians/{$guardian->uuid}/students")
        ->assertOk();

    expect(DB::table('authz_observations')->count())->toBe(0);
});

it('ensure() records an ownership would-be denial in observe mode with the right check_type', function () {
    config(['authz.enforce' => false]);

    Authz::ensure(false, 'import.belongs_to_school', 'ownership', 'GuardianImportController@authorizeSchool', 404);

    $row = DB::table('authz_observations')->first();
    expect($row)->not->toBeNull()
        ->and($row->ability)->toBe('import.belongs_to_school')
        ->and($row->check_type)->toBe('ownership')
        ->and($row->controller_action)->toBe('GuardianImportController@authorizeSchool');
});

it('ensure() aborts with the supplied status (404) in enforce mode', function () {
    config(['authz.enforce' => true]);

    expect(fn () => Authz::ensure(false, 'enrollment.belongs_to_student', 'ownership', 'X@y', 404))
        ->toThrow(HttpException::class);

    expect(DB::table('authz_observations')->count())->toBe(0); // enforce aborts, never records
});

it('ensure() does nothing when the condition holds', function () {
    config(['authz.enforce' => false]);

    Authz::ensure(true, 'enrollment.belongs_to_student', 'ownership', 'X@y', 404);

    expect(DB::table('authz_observations')->count())->toBe(0);
});

it('enforces (403) when the enforce flag is on — proving the same gate can block', function () {
    config(['authz.enforce' => true]);

    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'admin');
    om_grantRouteAccess();
    $user->flushSchoolAccessCache();
    $guardian = al_makeGuardian($school->id, al_makeUser($school->id)->id);

    $this->actingAs($user)->withSession(['school_id' => $school->id])
        ->getJson("/api/guardians/{$guardian->uuid}/students")
        ->assertStatus(403);

    // Enforce path aborts; it does not record.
    expect(DB::table('authz_observations')->count())->toBe(0);
});

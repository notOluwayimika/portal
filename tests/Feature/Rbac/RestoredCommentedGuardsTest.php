<?php

use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `883ff6c` ("feat: phase 1 updates") blanket-commented 47 guards in one sweep.
 * Triaging the survivors showed the cluster is NOT uniform, and that difference is
 * the whole reason for triaging instead of blanket-uncommenting:
 *
 *   REAL gaps  — CurriculumSubjectController@approve (a form_teacher, allowed
 *                through the route middleware, could approve results), @submit (no
 *                teacher-owns-this-subject check at all), and
 *                SavedActivityFilterController@destroy (no ownership check).
 *                Restored in observe mode per ADR 0043.
 *   REDUNDANT  — StudentCurriculumController::authorizeReviewer() and
 *                CurriculumSubjectController@reject. These LOOK like holes —
 *                authorizeReviewer's entire body was the commented guard — but their
 *                callers all take FormRequests whose authorize() enforces the
 *                identical condition BEFORE the controller method runs.
 *
 * This file pins the second group, because that is the claim most likely to be
 * "corrected" by a future reader restoring a check that must not come back. If these
 * go red, the FormRequest lost its authorize() and the controller check IS needed
 * again.
 */
uses(RefreshDatabase::class);

/** @return array{0: User, 1: StudentCurriculum, 2: School} a non-reviewer, an episode, its School */
function guardFixture(): array
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);
    $episode = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]));

    // `form_teacher` is the discriminating role: the route group (C2:
    // permission:academic_setup.manage, which form_teacher holds) LETS IT
    // THROUGH, so a 403 here can only have come from the authorization layer
    // under test.
    //
    // This matters — an earlier version used `teacher`, whose 403 came from the ROUTE
    // MIDDLEWARE and never reached the code under test. It passed while proving
    // nothing, and was caught only by probing for the observation record.
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'form_teacher');
    Permission::firstOrCreate(['name' => 'academic_setup.manage', 'guard_name' => 'web']);
    Role::findByName('form_teacher', 'web')->givePermissionTo('academic_setup.manage');
    $user->flushSchoolAccessCache();

    return [$user, $episode, $school];
}

it('FORM REQUEST OWNS IT — a non-reviewer is denied before the controller runs', function () {
    // Enforcement is OFF, so nothing in Authz can deny. A 403 therefore proves the
    // FormRequest's authorize() is the live gate — which is precisely why restoring
    // authorizeReviewer()'s commented check would be a duplicate that never fires.
    config(['authz.enforce' => false]);
    [$user, $episode, $school] = guardFixture();

    $this->actingAs($user)
        ->withSession(['school_id' => $school->id])
        ->patchJson("/api/student-curricula/{$episode->uuid}", ['status' => 'withdrawn'])
        ->assertForbidden();
})->group('authz');

it('ANTI-INERT — a genuine reviewer is NOT denied', function () {
    // The discriminating half: a gate that denied everyone would satisfy the test
    // above. This one fails if the condition is wrong rather than merely present.
    config(['authz.enforce' => false]);
    [$user, $episode, $school] = guardFixture();

    $user->grantSchoolAccess($school, 'admin');
    Role::findByName('admin', 'web')->givePermissionTo('academic_setup.manage');
    // C3 (ADR 0044 step 2): the FormRequest under test authorizes by permission
    // now, not by the `admin` role name — so the genuine-reviewer fixture has to
    // hold the permission. That this test went red on the swap is the point:
    // it is asserting the FormRequest is the live gate.
    Permission::firstOrCreate(['name' => 'student_curriculum.update_status', 'guard_name' => 'web']);
    Role::findByName('admin', 'web')->givePermissionTo('student_curriculum.update_status');
    $user->flushSchoolAccessCache();

    $response = $this->actingAs($user)
        ->withSession(['school_id' => $school->id])
        ->patchJson("/api/student-curricula/{$episode->uuid}", ['status' => 'withdrawn']);

    expect($response->status())->not->toBe(403);
})->group('authz');

<?php

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\GuardianService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/*
|--------------------------------------------------------------------------
| The eight routes
|--------------------------------------------------------------------------
|
| Table-driven on purpose. Eight bespoke tests would have to be edited eight
| times to add a case, which is how five of these doors stayed open while two
| were being argued about. The dataset is the ROUTE KEY only — Pest resolves
| datasets at collection time, before any database exists, so the URL is built
| inside the test body from the world it just planted.
|
| TWO BINDING SHAPES, and the distinction is the whole point of the middleware:
|
|   student-bound        1, 2, 3, 5, 6   — {student} is on the URL
|   enrollment-bound     4, 7, 8         — only {studentCurriculum} is; the
|                                          student is reached THROUGH it
|
| A guard that looks for a `{student}` route parameter protects nothing on
| 4, 7 and 8 while appearing in `route:list` next to all eight.
*/

/** @return array<string,string> route key => "web"|"api" transport */
function gwa_transports(): array
{
    return [
        'web:students.results.active' => 'web',
        'web:students.results.show' => 'web',
        'web:studentCurricula.index' => 'web',
        'web:studentCurricula.subjects' => 'web',
        'api:student.result-status' => 'api',
        'api:student.curriculum.result-status' => 'api',
        'api:studentCurriculum.teachers' => 'api',
        'api:studentCurriculum.scores' => 'api',
    ];
}

function gwa_routeKeys(): array
{
    return array_keys(gwa_transports());
}

/**
 * The URL for one route key, aimed at one of the planted students.
 *
 * $target is 'ward' or 'stranger'; the world holds a matching
 * "{$target}Curriculum" enrollment for the enrollment-bound routes.
 */
function gwa_url(array $w, string $key, string $target): string
{
    $student = $w[$target];
    $enrollment = $w[$target.'Curriculum'];

    return match ($key) {
        // 1. routes/web.php — students.results.active
        'web:students.results.active' => "/students/{$student->uuid}/results/active",
        // 2. routes/web.php — students.results.show (withoutScopedBindings)
        'web:students.results.show' => "/students/{$student->uuid}/results/{$enrollment->uuid}",
        // 3. routes/web.php — setup.studentCurricula.index
        'web:studentCurricula.index' => "/setup/student-curricula/{$student->uuid}",
        // 4. routes/web.php — setup.studentCurricula.show; ENROLLMENT-BOUND
        'web:studentCurricula.subjects' => "/setup/student-curricula/{$enrollment->uuid}/subjects",
        // 5. routes/api.php — StudentController::activeResultStatus
        'api:student.result-status' => "/api/students/{$student->uuid}/result-status",
        // 6. routes/api.php — CurriculumController::activeResultStatus (withoutScopedBindings;
        //    {curriculum} is school-owned, not student-owned)
        'api:student.curriculum.result-status' => "/api/students/{$student->uuid}/curriculum/{$w['curriculum']->uuid}/result-status",
        // 7. routes/api.php — StudentCurriculumController::getTeacherDetails; ENROLLMENT-BOUND
        'api:studentCurriculum.teachers' => "/api/student-curricula/{$enrollment->uuid}",
        // 8. routes/api.php — StudentCurriculumController::getScoresWithMarkingComponents
        //    (withoutScopedBindings); ENROLLMENT-BOUND
        'api:studentCurriculum.scores' => "/api/student-curricula/{$enrollment->uuid}/curriculum-subject/{$w['curriculumSubject']->uuid}",
    };
}

/** Drive one route key with the right verb for its transport. */
function gwa_hit(array $w, string $key, string $target, User $actor)
{
    $url = gwa_url($w, $key, $target);
    $request = test()->actingAs($actor)->withSession(['school_id' => $w['school']->id]);

    return gwa_transports()[$key] === 'api'
        ? $request->getJson($url)
        : $request->get($url);
}

/**
 * One school, two students who differ ONLY in whether the guardian owns them.
 *
 * Both sit in the SAME curriculum, so every 403 this file asserts is an
 * ownership refusal and never a fixture difference: swap the two uuids in any
 * URL and the only thing that changed is the pivot row.
 */
function gwa_world(): array
{
    $school = al_makeSchool();

    $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);
    $arm = Arm::create(['school_id' => $school->id, 'label' => 'A']);
    $classLevelArm = ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $level->id,
        'arm_id' => $arm->id,
    ]);
    $session = AcademicSession::create([
        'school_id' => $school->id,
        'name' => 'Test Session',
        'slug' => 'session-'.Str::random(8),
        'is_current' => true,
    ]);
    $term = Term::create([
        'academic_session_id' => $session->id,
        'school_id' => $school->id,
        'name' => 'First Term',
        'slug' => 'term-'.Str::random(8),
        'order' => 1,
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonth(),
        'status' => 'active',
    ]);
    $examType = ExamType::create([
        'school_id' => $school->id,
        'name' => 'Exam',
        'slug' => 'exam-'.Str::random(8),
    ]);
    $curriculum = Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $classLevelArm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
    ]);
    $subject = Subject::create([
        'school_id' => $school->id,
        'name' => 'Mathematics',
        'code' => 'MTH-'.Str::random(4),
    ]);
    $curriculumSubject = CurriculumSubject::create([
        'curriculum_id' => $curriculum->id,
        'subject_id' => $subject->id,
        'is_compulsory' => true,
        'display_order' => 1,
        'active' => true,
    ]);

    $make = function (string $first) use ($school, $curriculum) {
        $student = Student::create([
            'school_id' => $school->id,
            'first_name' => $first,
            'last_name' => 'Child',
            'gender' => 'female',
            'admission_number' => 'ADM-'.Str::random(8),
        ]);
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
            'principal_approval' => true,
        ]);

        return [$student, $enrollment];
    };

    [$ward, $wardCurriculum] = $make('Ada');
    [$stranger, $strangerCurriculum] = $make('Zoe');

    // The guardian: guardian ROLE and a guardian ROW, both in this school.
    $guardianUser = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $guardianUser->assignRole('guardian');
    $guardianUser->schools()->syncWithoutDetaching([$school->id]);
    $guardian = al_makeGuardian($school->id, $guardianUser->id);
    $guardian->students()->attach($ward->id, [
        'relationship' => 'mother', 'is_primary' => true, 'can_login' => true,
    ]);

    $admin = al_makeUser($school->id);
    $admin->assignRole('admin');
    $admin->schools()->syncWithoutDetaching([$school->id]);

    $teacher = al_makeUser($school->id);
    $teacher->assignRole('teacher');
    $teacher->schools()->syncWithoutDetaching([$school->id]);

    return compact(
        'school', 'curriculum', 'curriculumSubject', 'term',
        'ward', 'wardCurriculum', 'stranger', 'strangerCurriculum',
        'guardianUser', 'guardian', 'admin', 'teacher',
    );
}

/*
|--------------------------------------------------------------------------
| The hole
|--------------------------------------------------------------------------
*/

it('lets a guardian read their own ward on every one of the eight routes', function (string $key) {
    $w = gwa_world();

    gwa_hit($w, $key, 'ward', $w['guardianUser'])->assertOk();
})->with(gwa_routeKeys());

it('refuses a guardian reading a student who is not their ward, on every one of the eight routes', function (string $key) {
    $w = gwa_world();

    // Same school, same curriculum, same everything — the ONLY difference
    // between this student and the one the test above reads is the absence of
    // a guardian_student pivot row.
    gwa_hit($w, $key, 'stranger', $w['guardianUser'])->assertForbidden();
})->with(gwa_routeKeys());

/*
| Route 2 (routes/web.php, students.results.show) carries withoutScopedBindings(),
| so {studentCurriculum}
| is NOT constrained to the {student} in front of it. A check that stops at the
| first student-owned binding it finds passes this request.
|
| Routes 6 and 8 also carry withoutScopedBindings(), but their SECOND binding is
| school-owned, not student-owned ({curriculum}, {curriculumSubject}), so route 2
| is the only one of the eight that carries two student-owned bindings. The
| enrollment-bound half of that pair is covered on 7 and 8 by the test above.
*/
it('refuses the mixed pair on route 2: own ward as {student}, a non-ward enrollment as {studentCurriculum}', function () {
    $w = gwa_world();

    $this->actingAs($w['guardianUser'])
        ->withSession(['school_id' => $w['school']->id])
        ->get("/students/{$w['ward']->uuid}/results/{$w['strangerCurriculum']->uuid}")
        ->assertForbidden();
});

it('refuses a user holding the guardian role who has no guardian row in the active school', function (string $key) {
    $w = gwa_world();

    $orphan = al_makeUser($w['school']->id);
    setPermissionsTeamId($w['school']->id);
    $orphan->assignRole('guardian');
    $orphan->schools()->syncWithoutDetaching([$w['school']->id]);

    gwa_hit($w, $key, 'ward', $orphan)->assertForbidden();
})->with(gwa_routeKeys());

/*
| The `$user->guardian` trap, documented on GuardianService::forUserInActiveSchool.
| This user owns a
| ward in school A and holds a guardian row there, but is ACTIVE in school B
| where they have none. `$user->guardian` is an unordered hasOne whose scope
| ORs on school access, so it hands back the school-A row — and an ownership
| check built on it would then authorise this request. Resolving through
| forUserInActiveSchool() returns null and the request is refused.
*/
it('refuses a guardian whose only guardian row is in another school', function (string $key) {
    $w = gwa_world();
    $other = al_makeSchool();

    $user = al_makeUser($w['school']->id);
    foreach ([$other, $w['school']] as $school) {
        setPermissionsTeamId($school->id);
        $user->assignRole('guardian');
        $user->schools()->syncWithoutDetaching([$school->id]);
    }

    // A real guardian row + a real ward, in the OTHER school only.
    $guardianElsewhere = al_makeGuardian($other->id, $user->id);
    $wardElsewhere = Student::create([
        'school_id' => $other->id,
        'first_name' => 'Bola',
        'last_name' => 'Child',
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);
    $guardianElsewhere->students()->attach($wardElsewhere->id, [
        'relationship' => 'father', 'is_primary' => true, 'can_login' => true,
    ]);

    setPermissionsTeamId($w['school']->id);

    gwa_hit($w, $key, 'ward', $user)->assertForbidden();
})->with(gwa_routeKeys());

/*
|--------------------------------------------------------------------------
| Staff are not touched
|--------------------------------------------------------------------------
*/

it('leaves an admin reading any student in the school entirely unaffected', function (string $key) {
    $w = gwa_world();

    gwa_hit($w, $key, 'ward', $w['admin'])->assertOk();
    gwa_hit($w, $key, 'stranger', $w['admin'])->assertOk();
})->with(gwa_routeKeys());

/*
| A teacher does NOT hold result.view (RbacSeeder::grantsMap), so four of the
| eight are 403 for a teacher BEFORE this middleware runs and stay 403 after —
| that is the permission gate, not ownership, and it is orthogonal. What this
| test pins is that the ownership middleware never DISCRIMINATES for staff: the
| status is identical for a student the teacher has no relationship with and one
| a guardian happens to own.
*/
it('never discriminates by ownership for a teacher', function (string $key) {
    $w = gwa_world();

    $ward = gwa_hit($w, $key, 'ward', $w['teacher'])->getStatusCode();
    $stranger = gwa_hit($w, $key, 'stranger', $w['teacher'])->getStatusCode();

    expect($ward)->toBe($stranger);

    // And on the four a teacher can actually reach, both are 200 — so the
    // assertion above is not two 403s agreeing with each other.
    if (in_array($key, [
        'web:studentCurricula.index',
        'web:studentCurricula.subjects',
        'api:student.result-status',
        'api:student.curriculum.result-status',
    ], true)) {
        expect($ward)->toBe(200);
    }
})->with(gwa_routeKeys());

/*
|--------------------------------------------------------------------------
| The predicate itself
|--------------------------------------------------------------------------
*/

it('answers isWardOf from the guardian_student pivot in the active school', function () {
    $w = gwa_world();

    $this->actingAs($w['guardianUser'])->withSession(['school_id' => $w['school']->id]);
    session(['school_id' => $w['school']->id]);

    $guardians = app(GuardianService::class);

    expect($guardians->isWardOf($w['guardianUser'], $w['ward']->id))->toBeTrue();
    expect($guardians->isWardOf($w['guardianUser'], $w['stranger']->id))->toBeFalse();
});

/*
| can_login is a PRODUCT decision about whether the parent may sign in at all,
| not an ownership fact — and only a handful of live pivots carry it. Folding it
| into the ownership predicate would lock nearly every legitimate parent out of
| their own child's results under cover of a security fix.
*/
it('does not fold can_login into ownership', function () {
    $w = gwa_world();

    DB::table('guardian_student')
        ->where('guardian_id', $w['guardian']->id)
        ->where('student_id', $w['ward']->id)
        ->update(['can_login' => false]);

    $this->actingAs($w['guardianUser'])->withSession(['school_id' => $w['school']->id]);
    session(['school_id' => $w['school']->id]);

    expect(app(GuardianService::class)->isWardOf($w['guardianUser'], $w['ward']->id))->toBeTrue();
});

/*
| THE RESOLVER, PINNED IN BOTH DIRECTIONS.
|
| The test above ("only guardian row is in another school") does NOT discriminate
| between forUserInActiveSchool() and `$user->guardian`: swap the resolver and it
| still passes, because the requested student is in the active School and the
| guardian_student_same_school triggers keep a pivot from ever spanning two
| Schools — so the pivot lookup misses either way. Bite-proved: with the wrong
| relation substituted, 51/51 stayed green. The two tests below are the ones that
| fail when the resolver is wrong, one per direction.
*/

// FALSE NEGATIVE — and the outage this fix would otherwise have caused. A parent
// with a Guardian row in two Schools is the exact case `$user->guardian` gets
// wrong (unordered hasOne, scope ORs on School access, so it returns whichever
// row the database hands back first — here the OTHER School's, which has the
// lower id). An ownership check built on it would lock this parent out of their
// own child, on all eight routes, with a 403 that reads like a security refusal.
it('does not lock out a parent who also has a guardian row in another school', function (string $key) {
    $w = gwa_world();
    $other = al_makeSchool();

    $user = al_makeUser($w['school']->id);
    foreach ([$other, $w['school']] as $school) {
        setPermissionsTeamId($school->id);
        $user->assignRole('guardian');
        $user->schools()->syncWithoutDetaching([$school->id]);
    }

    // Created FIRST, so it has the lower id — what an unordered hasOne returns.
    $guardianElsewhere = al_makeGuardian($other->id, $user->id);
    $wardElsewhere = Student::create([
        'school_id' => $other->id,
        'first_name' => 'Bola',
        'last_name' => 'Child',
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);
    $guardianElsewhere->students()->attach($wardElsewhere->id, [
        'relationship' => 'father', 'is_primary' => true, 'can_login' => true,
    ]);

    // And a real row, with a real ward, in the ACTIVE school.
    $guardianHome = al_makeGuardian($w['school']->id, $user->id);
    $guardianHome->students()->attach($w['ward']->id, [
        'relationship' => 'father', 'is_primary' => true, 'can_login' => true,
    ]);

    expect($guardianElsewhere->id)->toBeLessThan($guardianHome->id);

    setPermissionsTeamId($w['school']->id);

    gwa_hit($w, $key, 'ward', $user)->assertOk();
})->with(gwa_routeKeys());

// FALSE POSITIVE — the isolation half. The same-school triggers were added
// 2026_07_16_000003 and guard WRITES only; they validated nothing already in the
// table, so a pivot whose guardian and student sit in different Schools is
// possible in data that predates them. This plants exactly that (the guardian's
// school_id is moved AFTER the pivot is written, which the triggers — they are on
// guardian_student, not on guardians — do not see) and asserts the request is
// still refused. Resolving through the ACTIVE School is what holds this: the
// predicate's isolation guarantee does not rest on the trigger.
it('refuses a cross-school pivot that predates the same-school triggers', function (string $key) {
    $w = gwa_world();
    $other = al_makeSchool();

    $user = al_makeUser($w['school']->id);
    setPermissionsTeamId($w['school']->id);
    $user->assignRole('guardian');
    $user->schools()->syncWithoutDetaching([$w['school']->id, $other->id]);

    // Written while both sides are in the active school, so the trigger passes...
    $guardian = al_makeGuardian($w['school']->id, $user->id);
    $guardian->students()->attach($w['ward']->id, [
        'relationship' => 'father', 'is_primary' => true, 'can_login' => true,
    ]);

    // ...then the guardian is moved to another school, leaving the pivot behind.
    // The user now has NO guardian row in the active school.
    DB::table('guardians')->where('id', $guardian->id)->update(['school_id' => $other->id]);

    expect(
        DB::table('guardian_student')
            ->where('guardian_id', $guardian->id)->where('student_id', $w['ward']->id)->exists()
    )->toBeTrue();

    gwa_hit($w, $key, 'ward', $user)->assertForbidden();
})->with(gwa_routeKeys());

/*
| The dual-role case, pinned because it is a DECISION and not an accident.
|
| A teacher who is also a parent at the same school holds both roles. The bare
| `hasRole('guardian')` used by the visibility filters further down these routes
| would treat them as a parent and strip their staff reach — a functional outage
| for real staff, which this fix is explicitly not allowed to cause. The
| middleware therefore requires the guardian role AND no other; every other role
| in RbacSeeder::grantsMap() is a staff or oversight seat.
|
| Consequence, stated so it is not discovered later: such a user passes the
| ownership check but is STILL guardian-filtered by the approval/deadline logic
| inside the route closures. That divergence is the safe direction (less
| restrictive about which student, more restrictive about what is shown), and
| those filters are out of scope for this commit.
*/
it('does not restrict a member of staff who is also a parent at the same school', function (string $key) {
    $w = gwa_world();

    $staffParent = al_makeUser($w['school']->id);
    setPermissionsTeamId($w['school']->id);
    $staffParent->assignRole('teacher');
    $staffParent->assignRole('guardian');
    $staffParent->schools()->syncWithoutDetaching([$w['school']->id]);

    // A real guardian row and a real ward — so this is genuinely the dual-role
    // case and not just "a teacher who happens to hold an extra role name".
    $guardian = al_makeGuardian($w['school']->id, $staffParent->id);
    $guardian->students()->attach($w['ward']->id, [
        'relationship' => 'mother', 'is_primary' => true, 'can_login' => true,
    ]);

    $pureTeacher = gwa_hit($w, $key, 'stranger', $w['teacher'])->getStatusCode();
    $dualRole = gwa_hit($w, $key, 'stranger', $staffParent)->getStatusCode();

    // Identical to a plain teacher's: the ownership middleware stood aside.
    expect($dualRole)->toBe($pureTeacher);
    expect($dualRole)->not->toBe(403);
})->with([
    // The four a teacher can reach at all. On the other four a teacher lacks
    // result.view and gets 403 from the permission gate, which would make
    // "not 403" false for a reason that has nothing to do with ownership.
    'web:studentCurricula.index',
    'web:studentCurricula.subjects',
    'api:student.result-status',
    'api:student.curriculum.result-status',
]);

/*
| The no-op failure mode, pinned.
|
| The middleware's whole value is that `route:list` shows which routes are
| protected. That guarantee is worth nothing if the middleware can be attached
| and then find nothing to check — a renamed binding, a route that lost its
| parameter, an ordering change that puts it ahead of SubstituteBindings. It
| refuses in that case rather than passing, so the mistake surfaces as a visible
| 403 for parents instead of eight silently reopened doors.
*/
it('refuses a guardian on a protected route where no student binding resolves', function () {
    $w = gwa_world();

    Route::middleware(['web', 'auth', 'tenant', 'guardian_ward'])
        ->get('/__gwa_probe_no_binding', fn () => response('reached', 200));

    $this->actingAs($w['guardianUser'])
        ->withSession(['school_id' => $w['school']->id])
        ->get('/__gwa_probe_no_binding')
        ->assertForbidden();
});

it('still lets staff through that same route, so the refusal is ownership and not a blanket block', function () {
    $w = gwa_world();

    Route::middleware(['web', 'auth', 'tenant', 'guardian_ward'])
        ->get('/__gwa_probe_no_binding_staff', fn () => response('reached', 200));

    $this->actingAs($w['admin'])
        ->withSession(['school_id' => $w['school']->id])
        ->get('/__gwa_probe_no_binding_staff')
        ->assertOk()
        ->assertSee('reached');
});

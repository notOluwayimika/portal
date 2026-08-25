<?php

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/*
|--------------------------------------------------------------------------
| The four routes commit A deliberately left open
|--------------------------------------------------------------------------
|
| Commit A closed the eight routes that carry a STUDENT-owned binding: there
| the question is ownership, and the answer is per-student. These four carry no
| student binding to own, and three of them need no uuid guessing at all — the
| permission grant on the `guardian` role reaches the data on its own.
|
| TWO DIFFERENT RULES, and they are deliberately not collapsed into one
| predicate:
|
|   BULK (1, 2, 3)  the response is inherently many-students. There is nothing
|                   to own, so there is nothing to check — a parent must not
|                   reach these AT ALL.
|
|   SELF (4)        there IS something to own, but it is a GUARDIAN row, not a
|                   student. A parent may read their own and no other.
|
| Fixture helpers are `gbr_`-prefixed so they cannot collide with the `gwa_`
| set in GuardianWardAuthorisationTest (Pest test functions are global).
*/

/** @return array<int,string> the three bulk route keys */
function gbr_bulkKeys(): array
{
    return [
        'web:classLevel.results',
        'web:classLevelArm.results',
        'web:curriculumSubject.show',
    ];
}

function gbr_bulkUrl(array $w, string $key): string
{
    return match ($key) {
        // 1. routes/web.php — setup.classLevels.show; a whole class level's results
        'web:classLevel.results' => "/class-level/{$w['classLevel']->uuid}/results",
        // 2. routes/web.php — setup.classLevelArms.results; a whole arm's results
        'web:classLevelArm.results' => "/class-level-arm/{$w['classLevelArm']->uuid}/results",
        // 3. routes/web.php — setup.curriculumSubjects.show; loads scores.student and
        //    studentResults.student for EVERY student in the subject
        'web:curriculumSubject.show' => "/setup/curriculum-subject/{$w['curriculumSubject']->uuid}",
    };
}

function gbr_hitBulk(array $w, string $key, User $actor)
{
    return test()->actingAs($actor)
        ->withSession(['school_id' => $w['school']->id])
        ->get(gbr_bulkUrl($w, $key));
}

/** 4. routes/api.php — GuardianController::students; another guardian's ward list. */
function gbr_hitSelf(array $w, string $guardianKey, User $actor)
{
    return test()->actingAs($actor)
        ->withSession(['school_id' => $w['school']->id])
        ->getJson("/api/guardians/{$w[$guardianKey]->uuid}/students");
}

/**
 * One school, one class level / arm / curriculum / subject holding two enrolled
 * students, and TWO guardians — one of whom owns one of the students.
 *
 * Both guardians sit in the same School with the same role, so every 403 this
 * file asserts on route 4 is an identity refusal and never a fixture
 * difference: swap the two uuids and nothing else changed.
 */
function gbr_world(): array
{
    $school = al_makeSchool();

    $classLevel = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);
    $arm = Arm::create(['school_id' => $school->id, 'label' => 'A']);
    $classLevelArm = ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $classLevel->id,
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
        StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
            'principal_approval' => true,
        ]);

        return $student;
    };

    $ward = $make('Ada');
    $strangersChild = $make('Zoe');

    // The acting parent: guardian ROLE and a guardian ROW, both in this school.
    $guardianUser = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $guardianUser->assignRole('guardian');
    $guardianUser->schools()->syncWithoutDetaching([$school->id]);
    $ownGuardian = al_makeGuardian($school->id, $guardianUser->id);
    $ownGuardian->students()->attach($ward->id, [
        'relationship' => 'mother', 'is_primary' => true, 'can_login' => true,
    ]);

    // A DIFFERENT parent, same school, same role, with their own ward.
    $otherUser = al_makeUser($school->id);
    $otherUser->assignRole('guardian');
    $otherUser->schools()->syncWithoutDetaching([$school->id]);
    $otherGuardian = al_makeGuardian($school->id, $otherUser->id);
    $otherGuardian->students()->attach($strangersChild->id, [
        'relationship' => 'father', 'is_primary' => true, 'can_login' => true,
    ]);

    $admin = al_makeUser($school->id);
    $admin->assignRole('admin');
    $admin->schools()->syncWithoutDetaching([$school->id]);

    $teacher = al_makeUser($school->id);
    $teacher->assignRole('teacher');
    $teacher->schools()->syncWithoutDetaching([$school->id]);

    return compact(
        'school', 'classLevel', 'classLevelArm', 'curriculum', 'curriculumSubject',
        'ward', 'strangersChild',
        'guardianUser', 'ownGuardian', 'otherUser', 'otherGuardian',
        'admin', 'teacher',
    );
}

/*
|--------------------------------------------------------------------------
| BULK — a parent must not reach these at all
|--------------------------------------------------------------------------
*/

it('refuses a guardian a whole class, a whole arm, and a whole subject score grid', function (string $key) {
    $w = gbr_world();

    gbr_hitBulk($w, $key, $w['guardianUser'])->assertForbidden();
})->with(gbr_bulkKeys());

/*
| The bulk rule is unconditional, so it does not depend on the parent having a
| guardian row at all — a role-only account is refused on the same three routes.
*/
it('refuses a guardian with no guardian row in the active school on the bulk routes', function (string $key) {
    $w = gbr_world();

    $orphan = al_makeUser($w['school']->id);
    setPermissionsTeamId($w['school']->id);
    $orphan->assignRole('guardian');
    $orphan->schools()->syncWithoutDetaching([$w['school']->id]);

    gbr_hitBulk($w, $key, $orphan)->assertForbidden();
})->with(gbr_bulkKeys());

/*
|--------------------------------------------------------------------------
| SELF — a parent's own guardian row and no other
|--------------------------------------------------------------------------
*/

it('lets a guardian read their own guardian record', function () {
    $w = gbr_world();

    gbr_hitSelf($w, 'ownGuardian', $w['guardianUser'])->assertOk();
});

it("refuses a guardian reading a different guardian's record in the same school", function () {
    $w = gbr_world();

    // Same school, same role, same shape — the ONLY difference between this
    // guardian row and the one read above is whose user_id is on it.
    gbr_hitSelf($w, 'otherGuardian', $w['guardianUser'])->assertForbidden();
});

/*
| Guardian::applySchoolScope ORs on School ACCESS, not only on school_id, so a
| guardian row belonging to ANOTHER school still resolves through route-model
| binding here whenever its user can reach the active school. Comparing the
| bound row against the one resolved server-side by
| GuardianService::forUserInActiveSchool() is what closes that; a check that
| trusted the binding to be school-correct would not.
*/
it('refuses a guardian reading a cross-school guardian row that the binding still resolves', function () {
    $w = gbr_world();
    $other = al_makeSchool();

    // A user with access to BOTH schools whose only guardian row is in the other one.
    $elsewhereUser = al_makeUser($other->id);
    foreach ([$other, $w['school']] as $school) {
        setPermissionsTeamId($school->id);
        $elsewhereUser->assignRole('guardian');
        $elsewhereUser->schools()->syncWithoutDetaching([$school->id]);
    }
    $guardianElsewhere = al_makeGuardian($other->id, $elsewhereUser->id);

    setPermissionsTeamId($w['school']->id);

    // The binding resolves it (the OR on school access), so this is a real
    // reachable row and not a 404 in disguise.
    expect(
        Guardian::where('uuid', $guardianElsewhere->uuid)->exists()
    )->toBeTrue();

    $w['guardianElsewhere'] = $guardianElsewhere;

    gbr_hitSelf($w, 'guardianElsewhere', $w['guardianUser'])->assertForbidden();
});

it('refuses a guardian with no guardian row in the active school on the self route', function () {
    $w = gbr_world();

    $orphan = al_makeUser($w['school']->id);
    setPermissionsTeamId($w['school']->id);
    $orphan->assignRole('guardian');
    $orphan->schools()->syncWithoutDetaching([$w['school']->id]);

    gbr_hitSelf($w, 'ownGuardian', $orphan)->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Staff are not touched
|--------------------------------------------------------------------------
*/

it('leaves an admin unaffected on all four routes', function () {
    $w = gbr_world();

    foreach (gbr_bulkKeys() as $key) {
        expect(gbr_hitBulk($w, $key, $w['admin'])->getStatusCode())->toBe(200);
    }

    gbr_hitSelf($w, 'ownGuardian', $w['admin'])->assertOk();
    gbr_hitSelf($w, 'otherGuardian', $w['admin'])->assertOk();
});

/*
| A teacher does NOT hold result.view (RbacSeeder::grantsMap), so routes 1 and 2
| are 403 for a teacher BEFORE any of this and stay 403 after — that is the
| permission gate, not the new guards, and it is orthogonal. The admin test
| above is what discriminates on those two. Routes 3 and 4 a teacher does reach
| (curriculum_subject.view, student_status.view), and those must stay 200.
*/
it('leaves a teacher unaffected on the two routes a teacher can reach', function () {
    $w = gbr_world();

    expect(gbr_hitBulk($w, 'web:curriculumSubject.show', $w['teacher'])->getStatusCode())->toBe(200);

    gbr_hitSelf($w, 'ownGuardian', $w['teacher'])->assertOk();
    gbr_hitSelf($w, 'otherGuardian', $w['teacher'])->assertOk();
});

/*
| THE SHARED CONDITION, pinned across the extraction.
|
| Commit A scoped its ownership check to "the guardian role AND no other role",
| because a teacher who is also a parent at the same school holds both and a
| bare hasRole('guardian') would strip their staff reach. This commit needs the
| same condition, so it was EXTRACTED to one place
| (GuardianService::isActingAsGuardian) rather than copied — two copies of it
| drift, and a drifted copy is a hole. These assertions fail if either guard
| reimplements it as the bare role test.
*/
it('does not restrict a member of staff who is also a parent at the same school', function () {
    $w = gbr_world();

    $staffParent = al_makeUser($w['school']->id);
    setPermissionsTeamId($w['school']->id);
    $staffParent->assignRole('teacher');
    $staffParent->assignRole('guardian');
    $staffParent->schools()->syncWithoutDetaching([$w['school']->id]);

    // A real guardian row and a real ward, so this is genuinely the dual-role
    // case and not just a teacher carrying a spare role name.
    $guardian = al_makeGuardian($w['school']->id, $staffParent->id);
    $guardian->students()->attach($w['ward']->id, [
        'relationship' => 'mother', 'is_primary' => true, 'can_login' => true,
    ]);

    // NOT compared against a plain teacher on the bulk routes, and the reason is
    // worth stating: a dual-role user holds the UNION of both grant sets, so the
    // guardian role's own `result.view` legitimately carries them through routes
    // 1 and 2 where a plain teacher is 403 at the permission gate. Comparing the
    // two there would assert a permission difference, not an ownership one, and
    // could never hold. 200 on all three is the assertion that discriminates:
    // a guard written on the bare hasRole('guardian') makes every one of them 403.
    foreach (gbr_bulkKeys() as $key) {
        expect(gbr_hitBulk($w, $key, $staffParent)->getStatusCode())->toBe(200);
    }

    // Route 4: a dual-role user reads ANOTHER guardian's record, exactly as a
    // plain teacher does. The self-ownership rule stood aside for staff.
    $pureTeacher = gbr_hitSelf($w, 'otherGuardian', $w['teacher'])->getStatusCode();
    $dualRole = gbr_hitSelf($w, 'otherGuardian', $staffParent)->getStatusCode();

    expect($dualRole)->toBe($pureTeacher);
    expect($dualRole)->toBe(200);
});

/*
|--------------------------------------------------------------------------
| THE RESOLVER, PINNED IN BOTH DIRECTIONS
|--------------------------------------------------------------------------
|
| The cross-school test above does NOT discriminate between
| forUserInActiveSchool() and the `$user->guardian` trap: the row it requests
| belongs to a different USER, so the identity comparison fails whichever row
| the resolver returns. The pair below is the one that fails when the resolver
| is wrong, one per direction — a parent holding a guardian row in TWO Schools,
| which is the exact case `$user->guardian` gets wrong (an unordered hasOne
| whose scope ORs on School access, so it returns whichever row the database
| hands back first).
|
| Built so the OTHER School's row has the LOWER id — what that hasOne returns.
*/
function gbr_twoSchoolParent(array $w): array
{
    $other = al_makeSchool();

    $user = al_makeUser($w['school']->id);
    foreach ([$other, $w['school']] as $school) {
        setPermissionsTeamId($school->id);
        $user->assignRole('guardian');
        $user->schools()->syncWithoutDetaching([$school->id]);
    }

    // Created FIRST, so it has the lower id.
    $away = al_makeGuardian($other->id, $user->id);
    $home = al_makeGuardian($w['school']->id, $user->id);

    expect($away->id)->toBeLessThan($home->id);

    setPermissionsTeamId($w['school']->id);

    return [$user, $home, $away];
}

// FALSE NEGATIVE — the outage this fix would otherwise cause. A parent with a
// guardian row in two Schools is locked out of their OWN record in the School
// they are actually in, with a 403 that reads like a security refusal.
it('does not lock a two-school parent out of their own guardian record', function () {
    $w = gbr_world();
    [$user, $home] = gbr_twoSchoolParent($w);

    $w['home'] = $home;

    gbr_hitSelf($w, 'home', $user)->assertOk();
});

// FALSE POSITIVE — the isolation half. The SAME parent's row in the other
// School still resolves through the binding (Guardian::applySchoolScope ORs on
// School access), and must be refused: it is not the record they hold in the
// School they are in.
it('refuses a two-school parent their own record from the school they are not in', function () {
    $w = gbr_world();
    [$user, , $away] = gbr_twoSchoolParent($w);

    $w['away'] = $away;

    // The binding really does resolve it — this is a reachable row, not a 404
    // in disguise.
    expect(Guardian::where('uuid', $away->uuid)->exists())->toBeTrue();

    gbr_hitSelf($w, 'away', $user)->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The no-op failure mode, pinned
|--------------------------------------------------------------------------
|
| Both guards' value is that `route:list` shows which routes carry them. That
| guarantee is worth nothing if `guardian_self` can be attached and then find no
| Guardian to check — a renamed binding, a lost parameter, an ordering change
| that puts it ahead of SubstituteBindings. It refuses in that case rather than
| passing, so the mistake surfaces as a visible 403 for parents instead of a
| silently reopened door.
*/
it('refuses a guardian on a guardian_self route where no guardian binding resolves', function () {
    $w = gbr_world();

    Route::middleware(['web', 'auth', 'tenant', 'guardian_self'])
        ->get('/__gbr_probe_no_binding', fn () => response('reached', 200));

    $this->actingAs($w['guardianUser'])
        ->withSession(['school_id' => $w['school']->id])
        ->get('/__gbr_probe_no_binding')
        ->assertForbidden();
});

it('still lets staff through that same route, so the refusal is identity and not a blanket block', function () {
    $w = gbr_world();

    Route::middleware(['web', 'auth', 'tenant', 'guardian_self'])
        ->get('/__gbr_probe_no_binding_staff', fn () => response('reached', 200));

    $this->actingAs($w['admin'])
        ->withSession(['school_id' => $w['school']->id])
        ->get('/__gbr_probe_no_binding_staff')
        ->assertOk()
        ->assertSee('reached');
});

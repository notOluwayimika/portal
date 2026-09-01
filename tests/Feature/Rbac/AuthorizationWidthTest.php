<?php

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\SavedActivityFilter;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Subject;
use App\Models\SubjectResultStatus;
use App\Models\Teacher;
use App\Models\TeacherCurriculumSubject;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Three authorization holes, closed — each proven on BOTH sides.
 *
 * The census that produced them found the same shape three times: the route
 * middleware admits a WIDER set of seats than the ability the endpoint means,
 * and the two guards that would have narrowed it were `Authz::ensure` calls in
 * OBSERVE mode, which record a would-be denial and let the request through.
 *
 * Every arm below is independent of `authz.enforce`, and says so executably: the
 * flag is a DATASET AXIS, so each arm runs once with it off (the production
 * default, and the setting under which all three holes were open) and once with
 * it on. A green here can therefore not be an artefact of either setting.
 *
 * Each fix carries a REFUSED arm (the seat that got through and must not) and a
 * KNOWN-NEGATIVE arm (the legitimate seat, still admitted). The second is the
 * load-bearing one: an over-tight ownership guard silently stops real teachers
 * submitting results, and an over-tight route gate locks `internal_auditor` —
 * the one seat that exists to read this feed — back out of the export it was
 * only granted yesterday.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * The rollout flag is an AXIS, not a setting. Every arm below runs under BOTH
 * values: the three holes were open under the production default (observe), and
 * none of the three fixes may depend on it being flipped. Pinning the flag to one
 * value would leave "independent of AUTHZ_ENFORCE" a sentence nothing checks.
 */
dataset('authz enforce', [
    'observe (authz.enforce=false — the production default)' => [false],
    'enforce (authz.enforce=true)' => [true],
]);

/** A user holding exactly $roles in $school, through the real grant path. */
function aw_user(School $school, array $roles): User
{
    $user = al_makeUser($school->id);

    foreach ($roles as $role) {
        $user->grantSchoolAccess($school, $role);
    }

    $user->flushSchoolAccessCache();

    return $user;
}

/** Resolve an ability in $school's team context — `can()` outside a request has none. */
function aw_can(User $user, School $school, string $ability): bool
{
    $previous = getPermissionsTeamId();
    setPermissionsTeamId($school->id);
    $user->unsetRelation('roles');
    $can = $user->can($ability);
    setPermissionsTeamId($previous);
    $user->unsetRelation('roles');

    return $can;
}

/** A Teacher row for $user in $school. */
function aw_teacherRow(School $school, User $user): Teacher
{
    return ActiveSchool::runFor($school->id, fn () => Teacher::create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'first_name' => 'Tee',
        'last_name' => 'Cher '.Str::random(4),
        'status' => 'active',
    ]));
}

/**
 * A curriculum subject with one actively-enrolled student, inside a current
 * session and an active term — submit() resolves both, and a fixture without
 * them 500s rather than exercising the guard.
 */
function aw_curriculumSubject(School $school): CurriculumSubject
{
    return ActiveSchool::runFor($school->id, function () use ($school) {
        $classLevelArm = ClassLevelArm::create([
            'school_id' => $school->id,
            'class_level_id' => ClassLevel::create([
                'school_id' => $school->id,
                'name' => 'Year '.random_int(1000, 9999),
                'order' => 1,
            ])->id,
            'arm_id' => Arm::create([
                'school_id' => $school->id,
                'label' => strtoupper(Str::random(3)),
            ])->id,
        ]);

        $session = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '20'.random_int(10, 99).'/20'.random_int(10, 99).'-'.Str::random(4),
            'slug' => Str::slug(Str::random(8)),
            'is_current' => true,
        ]);

        $term = Term::create([
            'academic_session_id' => $session->id,
            'name' => 'Term '.Str::random(4),
            'slug' => Str::slug(Str::random(8)),
            'order' => 1,
            'status' => 'active',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
        ]);

        $curriculum = Curriculum::factory()->create([
            'school_id' => $school->id,
            'class_level_arm_id' => $classLevelArm->id,
            'term_id' => $term->id,
        ]);

        $subject = Subject::create([
            'school_id' => $school->id,
            'name' => 'Subject '.Str::random(5),
            'code' => strtoupper(Str::random(4)),
        ]);

        $curriculumSubject = CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'is_compulsory' => true,
            'active' => true,
        ]);

        StudentCurriculum::create([
            'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
            'school_id' => $school->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        return $curriculumSubject;
    });
}

// ── FIX 1 · result submission is assigned-teacher-only ──────────────────────

it('refuses a submission from a seat that holds result.submit but is not assigned to the subject', function (bool $enforce) {
    config(['authz.enforce' => $enforce]);

    $school = al_makeSchool();

    // admin+teacher, which is what one of the two census users holds. The
    // fixture is built so ONLY ownership can refuse:
    //   · the route group is permission:score.manage — admin AND teacher hold it,
    //     so the middleware admits;
    //   · isTeacher() is can('result.submit') — the teacher role holds it, so the
    //     ability check passes;
    //   · a Teacher row EXISTS, so `$teacherId !== null` is satisfied.
    // The only unsatisfied clause is the teacher_curriculum_subjects assignment.
    $user = aw_user($school, ['admin', 'teacher']);
    aw_teacherRow($school, $user);
    $curriculumSubject = aw_curriculumSubject($school);

    expect(aw_can($user, $school, 'score.manage'))->toBeTrue()      // the door is open
        ->and(aw_can($user, $school, 'result.submit'))->toBeTrue(); // and so is the ability

    $response = $this->actingAs($user)->withSession(['school_id' => $school->id])
        ->postJson("/api/curriculum-subjects/{$curriculumSubject->uuid}/submit")
        ->assertForbidden();

    // Refused, not merely reported: nothing was written.
    expect(SubjectResultStatus::where('curriculum_subject_id', $curriculumSubject->id)->exists())
        ->toBeFalse();

    // AND THE REFUSAL IS LEGIBLE. There is no HttpException renderable in
    // bootstrap/app.php, so a bare abort(403) returns {"message": ""}; the result panel
    // reads `?? 'Action failed.'`, which does not substitute for an empty string, and
    // renders nothing at all. The message is asserted NON-EMPTY rather than by its exact
    // prose: the property under test is that a refusal reaches the screen, not the wording.
    expect($response->json('message'))->not->toBeNull()->not->toBe('');
})->with('authz enforce');

it('KNOWN NEGATIVE — the assigned teacher still submits', function (bool $enforce) {
    config(['authz.enforce' => $enforce]);

    $school = al_makeSchool();

    $user = aw_user($school, ['teacher']);
    $teacher = aw_teacherRow($school, $user);
    $curriculumSubject = aw_curriculumSubject($school);

    ActiveSchool::runFor($school->id, fn () => TeacherCurriculumSubject::create([
        'teacher_id' => $teacher->id,
        'curriculum_subject_id' => $curriculumSubject->id,
    ]));

    $this->actingAs($user)->withSession(['school_id' => $school->id])
        ->postJson("/api/curriculum-subjects/{$curriculumSubject->uuid}/submit")
        ->assertOk();

    expect(SubjectResultStatus::where('curriculum_subject_id', $curriculumSubject->id)->value('status'))
        ->toBe('submitted');
})->with('authz enforce');

// ── FIX 2 · saved activity filters: same school, and yours ──────────────────

/** A saved filter owned by a fresh user of $school. */
function aw_savedFilter(School $school): SavedActivityFilter
{
    return SavedActivityFilter::create([
        'user_id' => al_makeUser($school->id)->id,
        'school_id' => $school->id,
        'name' => 'Filter '.Str::random(5),
        'filters' => ['event' => ['updated']],
        'is_default' => false,
    ]);
}

it('refuses a cross-school delete without confirming the row exists', function (bool $enforce) {
    config(['authz.enforce' => $enforce]);

    $victimSchool = al_makeSchool();
    $filter = aw_savedFilter($victimSchool);

    // A legitimate audit-log reader — in a DIFFERENT school. The model carries no
    // BelongsToSchool, so no SchoolScope narrows the route-model binding, and the
    // binding key is a sequential integer id: the row resolves.
    $attackerSchool = al_makeSchool();
    $attacker = aw_user($attackerSchool, ['internal_auditor']);

    expect(aw_can($attacker, $attackerSchool, 'activity_log.view'))->toBeTrue(); // the door is open

    $this->actingAs($attacker)->withSession(['school_id' => $attackerSchool->id])
        ->deleteJson("/api/activity-logs/saved-filters/{$filter->id}")
        ->assertNotFound(); // 404, the house convention: existence is not confirmed

    expect(SavedActivityFilter::whereKey($filter->id)->exists())->toBeTrue();
})->with('authz enforce');

it('refuses a same-school delete of another user\'s filter', function (bool $enforce) {
    config(['authz.enforce' => $enforce]);

    $school = al_makeSchool();
    $filter = aw_savedFilter($school);

    // Same school, so isolation is satisfied and cannot be what refuses; the only
    // unsatisfied clause is ownership.
    $other = aw_user($school, ['internal_auditor']);

    $response = $this->actingAs($other)->withSession(['school_id' => $school->id])
        ->deleteJson("/api/activity-logs/saved-filters/{$filter->id}")
        ->assertForbidden();

    expect(SavedActivityFilter::whereKey($filter->id)->exists())->toBeTrue();
    expect($response->json('message'))->not->toBeNull()->not->toBe('');
})->with('authz enforce');

it('KNOWN NEGATIVE — the owner still deletes their own filter', function (bool $enforce) {
    config(['authz.enforce' => $enforce]);

    $school = al_makeSchool();
    $owner = aw_user($school, ['internal_auditor']);

    $filter = SavedActivityFilter::create([
        'user_id' => $owner->id,
        'school_id' => $school->id,
        'name' => 'Mine '.Str::random(5),
        'filters' => ['event' => ['updated']],
        'is_default' => false,
    ]);

    $this->actingAs($owner)->withSession(['school_id' => $school->id])
        ->deleteJson("/api/activity-logs/saved-filters/{$filter->id}")
        ->assertNoContent();

    expect(SavedActivityFilter::whereKey($filter->id)->exists())->toBeFalse();
})->with('authz enforce');

// ── FIX 3 · the export route is gated on activity_log.export ────────────────

it('refuses the export to a teacher, who holds activity_log.view but not activity_log.export', function (bool $enforce) {
    config(['authz.enforce' => $enforce]);

    $school = al_makeSchool();
    $teacher = aw_user($school, ['teacher']);

    // The discriminating fixture: the teacher DOES hold the group's ability, so a
    // 403 here can only have come from the route-level activity_log.export gate.
    expect(aw_can($teacher, $school, 'activity_log.view'))->toBeTrue()
        ->and(aw_can($teacher, $school, 'activity_log.export'))->toBeFalse();

    $response = $this->actingAs($teacher)->withSession(['school_id' => $school->id])
        ->getJson('/api/activity-logs/export')
        ->assertForbidden();

    // AND THE REFUSAL IS LEGIBLE — same shape as the two guards this branch wrote,
    // for a different reason. This one refuses in MIDDLEWARE, so the body is not ours:
    // Spatie\Permission\Middleware\PermissionMiddleware throws
    // UnauthorizedException::forPermissions(), an HttpException carrying "User does not
    // have the right permissions.". That is VENDOR behaviour — read, and until now not
    // pinned, so a vendor bump could empty it with nothing going red. It is not caught by
    // the AuthorizationException renderable in bootstrap/app.php (different class), so an
    // empty message would reach the client as {"message": ""} exactly as a bare abort()
    // does. Asserted non-empty rather than by its prose, so a reworded vendor string is
    // not a false red while an emptied one is a true one.
    expect($response->json('message'))->not->toBeNull()->not->toBe('');
})->with('authz enforce');

it('KNOWN NEGATIVE — internal_auditor still reaches the export', function (bool $enforce) {
    config(['authz.enforce' => $enforce]);

    $school = al_makeSchool();
    $auditor = aw_user($school, ['internal_auditor']);

    expect(aw_can($auditor, $school, 'activity_log.export'))->toBeTrue();

    $this->actingAs($auditor)->withSession(['school_id' => $school->id])
        ->get('/api/activity-logs/export')
        ->assertOk();
})->with('authz enforce');

it('KNOWN NEGATIVE — admin and head_of_school still reach the export', function (string $role, bool $enforce) {
    config(['authz.enforce' => $enforce]);

    $school = al_makeSchool();
    $user = aw_user($school, [$role]);

    $this->actingAs($user)->withSession(['school_id' => $school->id])
        ->get('/api/activity-logs/export')
        ->assertOk();
})->with(['admin', 'head_of_school'])->with('authz enforce');

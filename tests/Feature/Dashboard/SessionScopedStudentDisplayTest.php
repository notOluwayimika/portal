<?php

use App\Enums\StudentStatusEnum;
use App\Http\Controllers\SetupController;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Services\Dashboard\DashboardAnalysisService;
use App\Support\ActiveSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * THE DISPLAYED STUDENT FIGURES ARE SESSION-SCOPED; THE ONBOARDING GATE IS NOT.
 *
 * A `Student` is a persistent person with no session column, so "students this session" is only
 * expressible through enrollment — students -> student_curricula (ACTIVE) -> curricula -> terms ->
 * academic_session. Both displayed surfaces (the setup overview card, the dashboard KPI) and the
 * population overview count that; `entities.students.active` keeps counting the whole school,
 * because three separate onboarding gates read it and a school between sessions must not regress to
 * "incomplete".
 *
 * The split is the deliverable, so it has its own arm rather than being implied by the others.
 */
beforeEach(function () {
    foreach (['admin', 'head_of_school', 'teacher', 'guardian'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

/** A session with one term, and a curriculum on that term for the given level+arm. */
function ss_session(School $school, string $name, bool $current): AcademicSession
{
    return AcademicSession::create([
        'school_id' => $school->id,
        'name' => $name,
        'slug' => 'sess-'.Str::random(8),
        'is_current' => $current,
    ]);
}

function ss_term(AcademicSession $session, int $order = 1): Term
{
    return Term::create([
        'academic_session_id' => $session->id,
        'school_id' => $session->school_id,
        'name' => "Term {$order}",
        'slug' => 'term-'.Str::random(8),
        'order' => $order,
        'start_date' => now()->addMonths($order * 3),
        'end_date' => now()->addMonths($order * 3 + 2),
        'status' => 'active',
    ]);
}

function ss_curriculum(School $school, Term $term, ClassLevelArm $arm, ExamType $examType): Curriculum
{
    return Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $arm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
    ]);
}

/**
 * A school with a PRIOR and a CURRENT session, each carrying one curriculum on the same class level.
 *
 * Both sessions get a curriculum on the SAME level deliberately: it means the population overview's
 * class-level bucket exists in both, so a missing session filter shows up as an inflated COUNT on a
 * label that is present either way — rather than as a label appearing or disappearing, which a
 * weaker assertion could mistake for correct behaviour.
 */
function ss_world(): array
{
    $school = al_makeSchool();

    $prior = ss_session($school, '2024/2025', false);
    $current = ss_session($school, '2025/2026', true);
    $priorTerm = ss_term($prior);
    $currentTerm = ss_term($current);

    $level = ClassLevel::forceCreate(['school_id' => $school->id, 'name' => 'Year 10', 'order' => 10]);
    $arm = ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $level->id,
        'arm_id' => Arm::firstOrCreate(['school_id' => $school->id, 'label' => 'A'])->id,
    ]);
    $examType = ExamType::create([
        'school_id' => $school->id,
        'name' => 'WAEC',
        'slug' => 'et-'.Str::random(8),
    ]);

    return [
        'school' => $school,
        'prior' => $prior,
        'current' => $current,
        'priorCurriculum' => ss_curriculum($school, $priorTerm, $arm, $examType),
        'currentCurriculum' => ss_curriculum($school, $currentTerm, $arm, $examType),
        'level' => $level,
    ];
}

function ss_enroll(School $school, Curriculum $curriculum, string $status = 'active'): Student
{
    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Pupil',
        'last_name' => Str::random(6),
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => $status,
    ]);

    return $student;
}

/** The setup overview's number, read through the real endpoint. */
function ss_setupStudents(School $school): int
{
    return ActiveSchool::runFor((int) $school->id, function () {
        return (int) app(SetupController::class)->index()->getData(true)['students'];
    });
}

// ---------------------------------------------------------------------------

it('excludes a student enrolled only in a PRIOR session from both displayed totals', function () {
    // THE REPRODUCTION. Before the fix both surfaces counted every student the school ever
    // registered, so the prior-session pupil was included in each.
    $w = ss_world();
    ss_enroll($w['school'], $w['priorCurriculum']);          // last year only
    $thisYear = ss_enroll($w['school'], $w['currentCurriculum']);

    // EQUALS the current-session distinct count (1), not "> 0" and not "fewer than before".
    expect(ss_setupStudents($w['school']))->toBe(1);

    $analysis = app(DashboardAnalysisService::class)->generate($w['school']);
    expect($analysis['entities']['students']['enrolled_current_session'])->toBe(1);

    // And it is genuinely the CURRENT-session pupil who is counted — the school holds two students.
    expect(Student::where('school_id', $w['school']->id)->count())->toBe(2);
    expect(
        StudentCurriculum::where('student_id', $thisYear->id)->exists()
    )->toBeTrue();
});

it('keeps the onboarding gate school-wide when every student is from a prior session', function () {
    // THE SPLIT GUARD — the load-bearing arm. A school BETWEEN SESSIONS (rolled, nobody enrolled in
    // the new session yet) must show a low displayed figure while the "Add your students" step stays
    // COMPLETE. Session-scoping `active` instead of adding a field regresses exactly this case, and
    // every other arm in this file would stay green while it did.
    Config::set('dashboard_thresholds.modules.students.dormant_threshold', 3);

    $w = ss_world();
    foreach (range(1, 5) as $ignored) {
        ss_enroll($w['school'], $w['priorCurriculum']);
    }

    expect(ss_setupStudents($w['school']))->toBe(0);

    $analysis = app(DashboardAnalysisService::class)->generate($w['school']);

    // Displayed: nobody is enrolled this session.
    expect($analysis['entities']['students']['enrolled_current_session'])->toBe(0);

    // Gate: school-wide volume is 5, threshold 3 — the step is DONE and stays done.
    expect($analysis['entities']['students']['active'])->toBe(5);
    expect($analysis['entities']['students']['total'])->toBe(5);

    // is_onboarding_state is decided by $hasStudents in the same service — the third consumer of
    // `active`, and the one most easily broken by editing this method. Curricula and curriculum
    // subjects are not configured here, so onboarding is still true overall; what this pins is that
    // the STUDENTS half of it reads the school-wide number.
    expect($analysis['entities']['students']['active'])
        ->toBeGreaterThanOrEqual(config('dashboard_thresholds.modules.students.dormant_threshold'));
});

it('counts a student with two active enrollments this session ONCE', function () {
    $w = ss_world();
    $student = ss_enroll($w['school'], $w['currentCurriculum']);

    // A second ACTIVE enrollment in the SAME session, on another curriculum of the same session.
    $second = ss_curriculum(
        $w['school'],
        Term::where('academic_session_id', $w['current']->id)->first(),
        ClassLevelArm::forceCreate([
            'school_id' => $w['school']->id,
            'class_level_id' => $w['level']->id,
            'arm_id' => Arm::firstOrCreate(['school_id' => $w['school']->id, 'label' => 'B'])->id,
        ]),
        ExamType::create(['school_id' => $w['school']->id, 'name' => 'IGCSE', 'slug' => 'et-'.Str::random(8)]),
    );
    StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $second->id,
        'status' => 'active',
    ]);

    expect(ss_setupStudents($w['school']))->toBe(1);

    $analysis = app(DashboardAnalysisService::class)->generate($w['school']);
    expect($analysis['entities']['students']['enrolled_current_session'])->toBe(1);
});

it('counts only ACTIVE enrollments — a withdrawn or reassigned episode does not display', function () {
    // The status axis, separate from the session axis. Without this arm a filter that dropped
    // `status` entirely would still pass every other test in this file.
    //
    // NOT `promoted`: the student_curricula_promoted_requires_link trigger refuses a promoted row
    // with a NULL link, so planting one here would test the trigger rather than the filter.
    // `withdrawn` and `transferred` are the two terminal statuses that carry no link.
    $w = ss_world();
    ss_enroll($w['school'], $w['currentCurriculum'], StudentStatusEnum::WITHDRAWN->value);
    ss_enroll($w['school'], $w['currentCurriculum'], StudentStatusEnum::TRANSFERRED->value);
    ss_enroll($w['school'], $w['currentCurriculum'], StudentStatusEnum::ACTIVE->value);

    expect(ss_setupStudents($w['school']))->toBe(1);
});

it('displays 0 and does not error when the school has no current session', function () {
    $w = ss_world();
    ss_enroll($w['school'], $w['currentCurriculum']);
    ss_enroll($w['school'], $w['priorCurriculum']);

    // No session is current any more.
    AcademicSession::where('school_id', $w['school']->id)->update(['is_current' => false]);

    expect(ss_setupStudents($w['school']))->toBe(0);

    $analysis = app(DashboardAnalysisService::class)->generate($w['school']);
    expect($analysis['entities']['students']['enrolled_current_session'])->toBe(0);

    // The gate is untouched by the absence of a session — still the school-wide volume.
    expect($analysis['entities']['students']['active'])->toBe(2);

    // And the population overview is empty rather than blowing up.
    expect($analysis['distributions']['students_by_class_level'])->toBe([]);
});

it('scopes the population overview to the current session', function () {
    $w = ss_world();
    // Two pupils last year, one this year — all on the SAME class level, so the bucket exists in
    // both sessions and an unfiltered query shows 3 against the label rather than a missing label.
    ss_enroll($w['school'], $w['priorCurriculum']);
    ss_enroll($w['school'], $w['priorCurriculum']);
    ss_enroll($w['school'], $w['currentCurriculum']);

    $analysis = app(DashboardAnalysisService::class)->generate($w['school']);

    expect($analysis['distributions']['students_by_class_level'])
        ->toBe([['name' => 'Year 10', 'count' => 1]]);
});

it('does not count a student from ANOTHER school with a current session of its own', function () {
    // The ORDINARY cross-school case. Note honestly what this does and does not prove: it is the
    // SESSION filter that carries it, because each school's current session id is its own, so this
    // arm stays green even with the `students.school_id` predicate deleted (measured). The arm below
    // is the one that makes that predicate load-bearing.
    $mine = ss_world();
    $theirs = ss_world();

    ss_enroll($mine['school'], $mine['currentCurriculum']);
    ss_enroll($theirs['school'], $theirs['currentCurriculum']);
    ss_enroll($theirs['school'], $theirs['currentCurriculum']);

    expect(ss_setupStudents($mine['school']))->toBe(1);

    $analysis = app(DashboardAnalysisService::class)->generate($mine['school']);
    expect($analysis['entities']['students']['enrolled_current_session'])->toBe(1);
    expect($analysis['distributions']['students_by_class_level'])
        ->toBe([['name' => 'Year 10', 'count' => 1]]);
});

it('excludes a soft-deleted student from the displayed total', function () {
    $w = ss_world();
    ss_enroll($w['school'], $w['currentCurriculum']);
    $gone = ss_enroll($w['school'], $w['currentCurriculum']);
    $gone->delete();

    expect(ss_setupStudents($w['school']))->toBe(1);

    $analysis = app(DashboardAnalysisService::class)->generate($w['school']);
    expect($analysis['entities']['students']['enrolled_current_session'])->toBe(1);
});

it('cannot even construct a foreign student in this schools curriculum — the composite FK refuses it', function () {
    // WHY THIS ARM IS SHAPED LIKE THIS, stated because the honest answer is more useful than the
    // one originally intended. `SessionEnrolledStudents::query()` pins `students.school_id`, and
    // deleting that predicate was MEASURED to red nothing — so the obvious conclusion would be that
    // the arm above is weak. It is not: the predicate is genuinely redundant, and the database is
    // what makes it so.
    //
    // `student_curricula` carries TWO composite foreign keys (2026_07_19_130000):
    //     student_curricula_student_school_foreign    (student_id, school_id) -> students (id, school_id)
    //     student_curricula_curriculum_school_foreign (curriculum_id, school_id) -> curricula (id, school_id)
    //
    // Together they force student.school_id = student_curricula.school_id = curriculum.school_id, so
    // a pupil of school B holding an enrollment in school A's curriculum is not a state the schema
    // permits. The `students.school_id` predicate is therefore defence in depth — kept because the
    // school is pinned explicitly rather than inferred (Constitution 13), and because it is the only
    // thing standing if either constraint is ever dropped.
    //
    // So the arm proves the REFUSAL, and names the error code rather than asserting "it threw":
    // 1452 is the foreign-key violation, and 1364/1048 (a missing or null column) would satisfy a
    // bare expectException just as well while meaning something entirely different.
    $mine = ss_world();
    $theirs = ss_world();

    $foreign = Student::create([
        'school_id' => $theirs['school']->id,
        'first_name' => 'Foreign',
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    try {
        StudentCurriculum::create([
            'student_id' => $foreign->id,
            'curriculum_id' => $mine['currentCurriculum']->id,
            'status' => 'active',
        ]);

        $this->fail('the composite foreign key should have refused a cross-school enrollment');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1452);
        expect($e->getMessage())->toContain('student_curricula_curriculum_school_foreign');
    }

    // And the count is unaffected — nothing was written.
    expect(ss_setupStudents($mine['school']))->toBe(0);
});

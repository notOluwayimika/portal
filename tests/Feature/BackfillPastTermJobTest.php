<?php

use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Jobs\BackfillPastTermJob;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\GradingScheme;
use App\Models\MarkingComponent;
use App\Models\MarkingScheme;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Score;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

function bpt_classLevelArm(School $school): ClassLevelArm
{
    $classLevel = ClassLevel::create([
        'school_id' => $school->id,
        'name' => 'JSS1',
        'order' => 1,
    ]);

    $arm = Arm::create([
        'school_id' => $school->id,
        'label' => 'Gold',
    ]);

    return ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $classLevel->id,
        'arm_id' => $arm->id,
    ]);
}

function bpt_session(School $school): AcademicSession
{
    return AcademicSession::create([
        'school_id' => $school->id,
        'name' => 'Test Session',
        'slug' => 'session-'.Str::random(8),
        'is_current' => true,
    ]);
}

function bpt_term(AcademicSession $session, int $order, string $status): Term
{
    return Term::create([
        'academic_session_id' => $session->id,
        'school_id' => $session->school_id,
        'name' => "Term {$order}",
        'slug' => 'term-'.Str::random(8),
        'order' => $order,
        'start_date' => now()->subMonths(10 - $order * 3),
        'end_date' => now()->subMonths(8 - $order * 3),
        'status' => $status,
    ]);
}

function bpt_examType(School $school): ExamType
{
    return ExamType::create([
        'school_id' => $school->id,
        'name' => 'Internal Exam',
        'slug' => 'exam-'.Str::random(8),
    ]);
}

/**
 * Build the "live" Term 3 world: an active curriculum with one compulsory
 * subject (2 marking components), one teacher assignment stub skipped for
 * brevity, and one actively enrolled student with a score.
 *
 * @return array{school: School, admin: User, arm: ClassLevelArm,
 *               session: AcademicSession, pastTerm: Term, activeTerm: Term,
 *               examType: ExamType, source: Curriculum, subject: Subject,
 *               sourceSubject: CurriculumSubject, student: Student,
 *               sourceEnrollment: StudentCurriculum}
 */
function bpt_world(): array
{
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    $arm = bpt_classLevelArm($school);
    $session = bpt_session($school);
    $pastTerm = bpt_term($session, 1, TermStatusEnum::COMPLETED->value);
    $activeTerm = bpt_term($session, 3, TermStatusEnum::ACTIVE->value);
    $examType = bpt_examType($school);

    $source = Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $activeTerm->id,
        'class_level_arm_id' => $arm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
    ]);

    $subject = Subject::create([
        'school_id' => $school->id,
        'name' => 'Mathematics',
    ]);

    $sourceSubject = CurriculumSubject::create([
        'curriculum_id' => $source->id,
        'subject_id' => $subject->id,
        'is_compulsory' => true,
    ]);

    MarkingComponent::create([
        'curriculum_subject_id' => $sourceSubject->id,
        'school_id' => $school->id,
        'name' => 'Continuous Assessment',
        'weight' => 0.3,
        'is_ccm' => false,
    ]);
    MarkingComponent::create([
        'curriculum_subject_id' => $sourceSubject->id,
        'school_id' => $school->id,
        'name' => 'Examination',
        'weight' => 0.7,
        'is_ccm' => false,
    ]);

    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Student',
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    // Auto-attaches the compulsory subject via StudentCurriculumObserver.
    $sourceEnrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $source->id,
        'status' => 'active',
    ]);

    Score::create([
        'student_id' => $student->id,
        'curriculum_subject_id' => $sourceSubject->id,
        'marking_component_id' => $sourceSubject->markingComponents()->first()->id,
        'score' => 25,
        'created_by' => $admin->id,
    ]);

    return compact(
        'school', 'admin', 'arm', 'session', 'pastTerm', 'activeTerm',
        'examType', 'source', 'subject', 'sourceSubject', 'student',
        'sourceEnrollment'
    );
}

function bpt_findTarget(array $w): ?Curriculum
{
    return Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('school_id', $w['school']->id)
        ->where('term_id', $w['pastTerm']->id)
        ->where('class_level_arm_id', $w['arm']->id)
        ->where('exam_type_id', $w['examType']->id)
        ->where('is_ccm', false)
        ->first();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('mirrors the source curriculum into the past term as closed, with promoted enrollments and no scores', function () {
    $w = bpt_world();

    (new BackfillPastTermJob($w['source'], $w['pastTerm'], $w['admin']->id, (int) $w['source']->school_id))->handle();

    $target = bpt_findTarget($w);
    expect($target)->not->toBeNull();
    expect($target->status)->toBe('closed');
    expect($target->min_subjects)->toBe($w['source']->min_subjects);

    // Source curriculum untouched.
    expect($w['source']->fresh()->status)->toBe('active');

    // Subject cloned with its own marking components (verbatim weights) and a draft result status.
    $newSubject = CurriculumSubject::where('curriculum_id', $target->id)
        ->where('subject_id', $w['subject']->id)
        ->first();
    expect($newSubject)->not->toBeNull();
    expect($newSubject->is_compulsory)->toBeTrue();

    $components = $newSubject->markingComponents()->orderBy('weight')->get();
    expect($components)->toHaveCount(2);
    expect((float) $components[0]->weight)->toBe(0.3);
    expect((float) $components[1]->weight)->toBe(0.7);
    expect($newSubject->resultStatus->status)->toBe('draft');

    // Enrollment backdated as promoted, pointing at the source enrollment; source row untouched.
    $backdated = StudentCurriculum::where('student_id', $w['student']->id)
        ->where('curriculum_id', $target->id)
        ->first();
    expect($backdated)->not->toBeNull();
    expect($backdated->status)->toBe(StudentStatusEnum::PROMOTED);
    expect($backdated->promoted_to_id)->toBe($w['sourceEnrollment']->id);
    expect($w['sourceEnrollment']->fresh()->status)->toBe(StudentStatusEnum::ACTIVE);

    // Subject selection cloned; no scores copied.
    expect(StudentSubject::where('student_curriculum_id', $backdated->id)->count())->toBe(1);
    expect(Score::where('curriculum_subject_id', $newSubject->id)->count())->toBe(0);

    // One-active-curriculum invariant holds.
    expect(
        StudentCurriculum::where('student_id', $w['student']->id)
            ->where('status', StudentStatusEnum::ACTIVE->value)
            ->count()
    )->toBe(1);
});

it('is idempotent on re-run', function () {
    $w = bpt_world();

    (new BackfillPastTermJob($w['source'], $w['pastTerm'], $w['admin']->id, (int) $w['source']->school_id))->handle();
    (new BackfillPastTermJob($w['source'], $w['pastTerm'], $w['admin']->id, (int) $w['source']->school_id))->handle();

    $target = bpt_findTarget($w);

    expect(
        Curriculum::withoutGlobalScope(SchoolScope::class)
            ->where('term_id', $w['pastTerm']->id)
            ->where('class_level_arm_id', $w['arm']->id)
            ->where('is_ccm', false)
            ->count()
    )->toBe(1);
    expect(CurriculumSubject::where('curriculum_id', $target->id)->count())->toBe(1);

    $newSubject = CurriculumSubject::where('curriculum_id', $target->id)->first();
    expect($newSubject->markingComponents()->count())->toBe(2);

    $backdated = StudentCurriculum::where('curriculum_id', $target->id)->get();
    expect($backdated)->toHaveCount(1);
    expect(StudentSubject::where('student_curriculum_id', $backdated->first()->id)->count())->toBe(1);
});

it('reuses the source marking scheme instead of cloning subject components', function () {
    $w = bpt_world();

    $scheme = MarkingScheme::create([
        'school_id' => $w['school']->id,
        'is_ccm' => false,
        'version' => 1,
        'status' => 'active',
    ]);
    $scheme->components()->createMany([
        [
            'school_id' => $w['school']->id,
            'name' => 'Coursework',
            'weight' => 0.4,
            'is_ccm' => false,
        ],
        [
            'school_id' => $w['school']->id,
            'name' => 'Examination',
            'weight' => 0.6,
            'is_ccm' => false,
        ],
    ]);
    $w['source']->update(['marking_scheme_id' => $scheme->id]);

    (new BackfillPastTermJob($w['source']->fresh(), $w['pastTerm'], $w['admin']->id, (int) $w['source']->school_id))->handle();

    $target = bpt_findTarget($w);
    $newSubject = CurriculumSubject::where('curriculum_id', $target->id)->firstOrFail();

    expect($target->marking_scheme_id)->toBe($scheme->id);
    expect($newSubject->markingComponents()->count())->toBe(0);
    expect($newSubject->effectiveMarkingComponents()->pluck('name')->all())
        ->toBe(['Coursework', 'Examination']);
});

it('reuses categorical grading and does not create numerical marking components', function () {
    $w = bpt_world();

    $scheme = GradingScheme::create([
        'school_id' => $w['school']->id,
        'name' => 'Nursery Progress',
        'mode' => 'categorical',
        'version' => 1,
        'status' => 'active',
    ]);
    $scheme->items()->createMany([
        ['code' => 'GP', 'label' => 'Good progress', 'display_order' => 1],
        ['code' => 'WS', 'label' => 'Working on skills', 'display_order' => 2],
    ]);
    $w['source']->update(['grading_scheme_id' => $scheme->id]);

    (new BackfillPastTermJob($w['source']->fresh(), $w['pastTerm'], $w['admin']->id, (int) $w['source']->school_id))->handle();

    $target = bpt_findTarget($w);
    $newSubject = CurriculumSubject::where('curriculum_id', $target->id)->firstOrFail();

    expect($target->grading_scheme_id)->toBe($scheme->id);
    expect($target->usesCategoricalGrading())->toBeTrue();
    expect($newSubject->markingComponents()->count())->toBe(0);
});

it('refuses ccm sources, non-completed target terms, the source term itself, and other schools\' terms', function () {
    $w = bpt_world();

    // CCM source
    $ccm = Curriculum::create([
        'school_id' => $w['school']->id,
        'term_id' => $w['activeTerm']->id,
        'class_level_arm_id' => $w['arm']->id,
        'exam_type_id' => $w['examType']->id,
        'status' => 'active',
        'is_ccm' => true,
        'min_subjects' => 1,
    ]);
    (new BackfillPastTermJob($ccm, $w['pastTerm'], $w['admin']->id, (int) $ccm->school_id))->handle();
    expect(bpt_findTarget($w))->toBeNull();

    // Target term not completed (upcoming)
    $upcoming = bpt_term($w['session'], 2, TermStatusEnum::UPCOMING->value);
    (new BackfillPastTermJob($w['source'], $upcoming, $w['admin']->id, (int) $w['source']->school_id))->handle();
    expect(
        Curriculum::withoutGlobalScope(SchoolScope::class)->where('term_id', $upcoming->id)->count()
    )->toBe(0);

    // Target term = source's own term
    (new BackfillPastTermJob($w['source'], $w['activeTerm'], $w['admin']->id, (int) $w['source']->school_id))->handle();
    expect(
        Curriculum::withoutGlobalScope(SchoolScope::class)->where('term_id', $w['activeTerm']->id)->count()
    )->toBe(2); // source + ccm fixture only, nothing new

    // Term belonging to another school
    $otherSchool = al_makeSchool();
    $otherSession = bpt_session($otherSchool);
    $otherTerm = bpt_term($otherSession, 1, TermStatusEnum::COMPLETED->value);
    (new BackfillPastTermJob($w['source'], $otherTerm, $w['admin']->id, (int) $w['source']->school_id))->handle();
    expect(
        Curriculum::withoutGlobalScope(SchoolScope::class)->where('term_id', $otherTerm->id)->count()
    )->toBe(0);

    // Inactive source
    $w['source']->update(['status' => 'draft']);
    (new BackfillPastTermJob($w['source']->fresh(), $w['pastTerm'], $w['admin']->id, (int) $w['source']->school_id))->handle();
    expect(bpt_findTarget($w))->toBeNull();
});

it('skips withdrawn students and picks up new enrollments on a delta re-run', function () {
    $w = bpt_world();

    $withdrawn = Student::create([
        'school_id' => $w['school']->id,
        'first_name' => 'Gone',
        'last_name' => Str::random(6),
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);
    StudentCurriculum::create([
        'student_id' => $withdrawn->id,
        'curriculum_id' => $w['source']->id,
        'status' => 'withdrawn',
    ]);

    (new BackfillPastTermJob($w['source'], $w['pastTerm'], $w['admin']->id, (int) $w['source']->school_id))->handle();

    $target = bpt_findTarget($w);
    expect(StudentCurriculum::where('curriculum_id', $target->id)->count())->toBe(1);

    // A student who enrolls later is picked up by a re-run.
    $late = Student::create([
        'school_id' => $w['school']->id,
        'first_name' => 'Late',
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);
    StudentCurriculum::create([
        'student_id' => $late->id,
        'curriculum_id' => $w['source']->id,
        'status' => 'active',
    ]);

    (new BackfillPastTermJob($w['source']->fresh(), $w['pastTerm'], $w['admin']->id, (int) $w['source']->school_id))->handle();
    expect(StudentCurriculum::where('curriculum_id', $target->id)->count())->toBe(2);
});

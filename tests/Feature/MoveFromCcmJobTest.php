<?php

use App\Enums\TermStatusEnum;
use App\Jobs\MoveFromCcmJob;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\MarkingComponent;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Score;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

function mfc_classLevelArm(School $school): ClassLevelArm
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

function mfc_term(School $school): Term
{
    $session = AcademicSession::create([
        'school_id' => $school->id,
        'name' => 'Test Session',
        'slug' => 'session-' . Str::random(8),
        'is_current' => true,
    ]);

    return Term::create([
        'academic_session_id' => $session->id,
        'name' => 'First Term',
        'slug' => 'term-' . Str::random(8),
        'order' => 1,
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2),
        'status' => TermStatusEnum::ACTIVE->value,
    ]);
}

function mfc_examType(School $school): ExamType
{
    return ExamType::create([
        'school_id' => $school->id,
        'name' => 'Internal Exam',
        'slug' => 'exam-' . Str::random(8),
    ]);
}

function mfc_curriculum(School $school, ClassLevelArm $classLevelArm, Term $term, ExamType $examType, bool $isCcm): Curriculum
{
    return Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $classLevelArm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => $isCcm,
        'min_subjects' => 1,
    ]);
}

function mfc_markingComponent(School $school, ?CurriculumSubject $curriculumSubject, string $name, float $weight, bool $isCcm): MarkingComponent
{
    return MarkingComponent::create([
        'curriculum_subject_id' => $curriculumSubject?->id,
        'school_id' => $school->id,
        'name' => $name,
        'weight' => $weight,
        'is_ccm' => $isCcm,
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('carries scores for overlapping marking components onto the new non-ccm subject', function () {
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);

    $classLevelArm = mfc_classLevelArm($school);
    $term = mfc_term($school);
    $examType = mfc_examType($school);

    // Global (school-wide) marking component templates for the target (non-CCM) curriculum.
    mfc_markingComponent($school, null, 'Continuous Assessment 1', 0.25, false);
    mfc_markingComponent($school, null, 'Half Term Exam', 0.25, false);
    mfc_markingComponent($school, null, 'Continuous Assessment 2', 0.25, false);
    mfc_markingComponent($school, null, 'Examination', 0.25, false);

    $ccmCurriculum = mfc_curriculum($school, $classLevelArm, $term, $examType, true);

    $subject = Subject::create([
        'school_id' => $school->id,
        'name' => 'Mathematics',
    ]);

    $ccmSubject = CurriculumSubject::create([
        'curriculum_id' => $ccmCurriculum->id,
        'subject_id' => $subject->id,
        'is_compulsory' => true,
    ]);

    $ca1 = mfc_markingComponent($school, $ccmSubject, 'Continuous Assessment 1', 0.5, true);
    $halfTerm = mfc_markingComponent($school, $ccmSubject, 'Half Term Exam', 0.5, true);

    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Student',
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-' . Str::random(8),
    ]);

    // Creating the enrollment auto-attaches the compulsory $ccmSubject as an
    // active StudentSubject via StudentCurriculumObserver.
    $studentCurriculum = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $ccmCurriculum->id,
        'status' => 'active',
    ]);

    Score::create([
        'student_id' => $student->id,
        'curriculum_subject_id' => $ccmSubject->id,
        'marking_component_id' => $ca1->id,
        'score' => 45.5,
        'created_by' => $admin->id,
    ]);

    Score::create([
        'student_id' => $student->id,
        'curriculum_subject_id' => $ccmSubject->id,
        'marking_component_id' => $halfTerm->id,
        'score' => 40,
        'created_by' => $admin->id,
    ]);

    (new MoveFromCcmJob($ccmCurriculum, $admin->id))->handle();

    $targetCurriculum = Curriculum::withoutGlobalScope(SchoolScope::class)
        ->where('school_id', $school->id)
        ->where('term_id', $term->id)
        ->where('class_level_arm_id', $classLevelArm->id)
        ->where('exam_type_id', $examType->id)
        ->where('is_ccm', false)
        ->first();

    expect($targetCurriculum)->not->toBeNull();

    $newSubject = CurriculumSubject::where('curriculum_id', $targetCurriculum->id)
        ->where('subject_id', $subject->id)
        ->first();

    expect($newSubject)->not->toBeNull();

    $newComponents = $newSubject->markingComponents()->get()
        ->keyBy(fn (MarkingComponent $component) => Str::lower(trim($component->name)));

    expect($newComponents)->toHaveCount(4);

    $newCa1 = $newComponents->get('continuous assessment 1');
    $newHalfTerm = $newComponents->get('half term exam');
    $newCa2 = $newComponents->get('continuous assessment 2');
    $newExam = $newComponents->get('examination');

    $migratedCa1 = Score::where('student_id', $student->id)
        ->where('marking_component_id', $newCa1->id)
        ->first();

    expect($migratedCa1)->not->toBeNull();
    // Old CA1 was /50 (weight 0.5), new CA1 is /25 (weight 0.25): 45.5 * (0.25/0.5) = 22.75,
    // rounded to the scores table's 1-decimal-place precision -> 22.8.
    expect((float) $migratedCa1->score)->toBe(22.8);
    expect($migratedCa1->curriculum_subject_id)->toBe($newSubject->id);

    $migratedHalfTerm = Score::where('student_id', $student->id)
        ->where('marking_component_id', $newHalfTerm->id)
        ->first();

    expect($migratedHalfTerm)->not->toBeNull();
    // Old Half Term was /50 (weight 0.5), new Half Term is /25 (weight 0.25): 40 * (0.25/0.5) = 20.
    expect((float) $migratedHalfTerm->score)->toBe(20.0);

    // The two non-overlapping (non-CCM-only) components get no migrated score.
    expect(Score::where('marking_component_id', $newCa2->id)->exists())->toBeFalse();
    expect(Score::where('marking_component_id', $newExam->id)->exists())->toBeFalse();

    // Re-running the job is idempotent: no duplicates, no value changes.
    (new MoveFromCcmJob($ccmCurriculum, $admin->id))->handle();

    expect(Score::where('marking_component_id', $newCa1->id)->count())->toBe(1);
    expect(Score::where('marking_component_id', $newHalfTerm->id)->count())->toBe(1);

    $migratedCa1->refresh();
    expect((float) $migratedCa1->score)->toBe(22.8);
});

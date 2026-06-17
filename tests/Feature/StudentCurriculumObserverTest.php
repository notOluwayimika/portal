<?php

use App\Enums\StudentSubjectStatus;
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
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\Term;
use App\Services\StudentSubjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

function sco_classLevelArm(School $school, string $className): ClassLevelArm
{
    $classLevel = ClassLevel::create([
        'school_id' => $school->id,
        'name' => $className,
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

function sco_curriculum(School $school, ClassLevelArm $classLevelArm, Term $term, ExamType $examType): Curriculum
{
    return Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $classLevelArm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('carries over optional subjects from the previous active enrollment', function () {
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    auth()->setUser($admin);

    $jss1 = sco_classLevelArm($school, 'JSS1');
    $jss2 = sco_classLevelArm($school, 'JSS2');

    $session = AcademicSession::create([
        'school_id' => $school->id,
        'name' => 'Test Session',
        'slug' => 'session-' . Str::random(8),
        'is_current' => true,
    ]);

    $term = Term::create([
        'academic_session_id' => $session->id,
        'name' => 'First Term',
        'slug' => 'term-' . Str::random(8),
        'order' => 1,
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2),
        'status' => 'active',
    ]);

    $examType = ExamType::create([
        'school_id' => $school->id,
        'name' => 'Internal Exam',
        'slug' => 'exam-' . Str::random(8),
    ]);

    $curriculumA = sco_curriculum($school, $jss1, $term, $examType);
    $curriculumB = sco_curriculum($school, $jss2, $term, $examType);

    $mathSubject = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics']);
    $artSubject = Subject::create(['school_id' => $school->id, 'name' => 'Art']);

    $mathA = CurriculumSubject::create(['curriculum_id' => $curriculumA->id, 'subject_id' => $mathSubject->id, 'is_compulsory' => true, 'active' => true]);
    $artA = CurriculumSubject::create(['curriculum_id' => $curriculumA->id, 'subject_id' => $artSubject->id, 'is_compulsory' => false, 'active' => true]);

    $mathB = CurriculumSubject::create(['curriculum_id' => $curriculumB->id, 'subject_id' => $mathSubject->id, 'is_compulsory' => true, 'active' => true]);
    $artB = CurriculumSubject::create(['curriculum_id' => $curriculumB->id, 'subject_id' => $artSubject->id, 'is_compulsory' => false, 'active' => true]);

    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Student',
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-' . Str::random(8),
    ]);

    // Enrolling in curriculum A auto-attaches compulsory Mathematics.
    $enrollmentA = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculumA->id,
        'status' => 'active',
    ]);

    // The student additionally opts into Art for curriculum A.
    app(StudentSubjectService::class)->addOptionalSubject($enrollmentA, $artA, $admin);

    expect($mathA)->not->toBeNull();

    // Promoting to curriculum B should auto-attach compulsory Mathematics
    // AND carry over the optional Art subject from curriculum A.
    $enrollmentB = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculumB->id,
        'status' => 'active',
    ]);

    $subjectsB = $enrollmentB->activeSubjects()
        ->get()
        ->keyBy('curriculum_subject_id');

    expect($subjectsB)->toHaveCount(2);

    $mathStudentSubject = $subjectsB->get($mathB->id);
    expect($mathStudentSubject)->not->toBeNull();
    expect($mathStudentSubject->status)->toBe(StudentSubjectStatus::Active);

    $artStudentSubject = $subjectsB->get($artB->id);
    expect($artStudentSubject)->not->toBeNull();
    expect($artStudentSubject->status)->toBe(StudentSubjectStatus::Active);
});

it('does not carry over optional subjects when none exist on the previous enrollment', function () {
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    auth()->setUser($admin);

    $jss1 = sco_classLevelArm($school, 'JSS1');
    $jss2 = sco_classLevelArm($school, 'JSS2');

    $session = AcademicSession::create([
        'school_id' => $school->id,
        'name' => 'Test Session',
        'slug' => 'session-' . Str::random(8),
        'is_current' => true,
    ]);

    $term = Term::create([
        'academic_session_id' => $session->id,
        'name' => 'First Term',
        'slug' => 'term-' . Str::random(8),
        'order' => 1,
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2),
        'status' => 'active',
    ]);

    $examType = ExamType::create([
        'school_id' => $school->id,
        'name' => 'Internal Exam',
        'slug' => 'exam-' . Str::random(8),
    ]);

    $curriculumA = sco_curriculum($school, $jss1, $term, $examType);
    $curriculumB = sco_curriculum($school, $jss2, $term, $examType);

    $mathSubject = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics']);
    $artSubject = Subject::create(['school_id' => $school->id, 'name' => 'Art']);

    $mathA = CurriculumSubject::create(['curriculum_id' => $curriculumA->id, 'subject_id' => $mathSubject->id, 'is_compulsory' => true, 'active' => true]);
    $mathB = CurriculumSubject::create(['curriculum_id' => $curriculumB->id, 'subject_id' => $mathSubject->id, 'is_compulsory' => true, 'active' => true]);
    $artB = CurriculumSubject::create(['curriculum_id' => $curriculumB->id, 'subject_id' => $artSubject->id, 'is_compulsory' => false, 'active' => true]);

    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Student',
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-' . Str::random(8),
    ]);

    $enrollmentA = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculumA->id,
        'status' => 'active',
    ]);

    expect($mathA)->not->toBeNull();

    $enrollmentB = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculumB->id,
        'status' => 'active',
    ]);

    $subjectsB = $enrollmentB->activeSubjects()->get();

    expect($subjectsB)->toHaveCount(1);
    expect($subjectsB->first()->curriculum_subject_id)->toBe($mathB->id);

    expect(StudentSubject::where('student_curriculum_id', $enrollmentB->id)
        ->where('curriculum_subject_id', $artB->id)
        ->exists())->toBeFalse();

    expect($enrollmentA)->not->toBeNull();
});

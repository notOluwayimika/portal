<?php

use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmTeacher;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\GradingScheme;
use App\Models\PsychomotorSkill;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Teacher;
use App\Models\Term;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new RbacSeeder)->run();
});

// ---------------------------------------------------------------------------
// Fixture helpers (ps_-prefixed copies of the ta_ fixtures)
// ---------------------------------------------------------------------------

function ps_teacher(School $school, string $firstName = 'Teacher'): Teacher
{
    $user = al_makeUser($school->id);

    return Teacher::create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'first_name' => $firstName,
        'last_name' => 'Test',
        'staff_number' => 'STF-'.Str::random(8),
    ]);
}

function ps_classLevelArm(School $school, string $className = 'JSS1', string $armLabel = 'Gold'): ClassLevelArm
{
    $classLevel = ClassLevel::create([
        'school_id' => $school->id,
        'name' => $className.'-'.Str::random(4),
        'order' => 1,
    ]);

    $arm = Arm::create([
        'school_id' => $school->id,
        'label' => $armLabel.'-'.Str::random(4),
    ]);

    return ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $classLevel->id,
        'arm_id' => $arm->id,
    ]);
}

function ps_term(School $school, string $status = TermStatusEnum::ACTIVE->value): Term
{
    $session = AcademicSession::create([
        'school_id' => $school->id,
        'name' => 'Test Session',
        'slug' => 'session-'.Str::random(8),
        'is_current' => true,
    ]);

    return Term::create([
        'academic_session_id' => $session->id,
        'school_id' => $session->school_id,
        'name' => 'First Term',
        'slug' => 'term-'.Str::random(8),
        'order' => 1,
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2),
        'status' => $status,
    ]);
}

function ps_curriculum(School $school, ClassLevelArm $classLevelArm, Term $term, bool $categorical = false): Curriculum
{
    $gradingSchemeId = null;

    if ($categorical) {
        $gradingSchemeId = GradingScheme::create([
            'school_id' => $school->id,
            'name' => 'EYFS '.Str::random(4),
            'mode' => 'categorical',
            'status' => 'active',
        ])->id;
    }

    $examType = ExamType::create([
        'school_id' => $school->id,
        'name' => 'Internal Exam',
        'slug' => 'exam-'.Str::random(8),
    ]);

    return Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $classLevelArm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => false,
        'grading_scheme_id' => $gradingSchemeId,
    ]);
}

function ps_enrolledStudent(Curriculum $curriculum, string $gender): StudentCurriculum
{
    $student = Student::create([
        'school_id' => $curriculum->school_id,
        'first_name' => 'Student',
        'last_name' => Str::random(6),
        'gender' => $gender,
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    return StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => StudentStatusEnum::ACTIVE->value,
    ]);
}

function ps_assign(School $school, Teacher $teacher, string $role, ClassLevelArm $arm, ?string $gender = null): void
{
    ClassLevelArmTeacher::create([
        'class_level_arm_id' => $arm->id,
        'teacher_id' => $teacher->id,
        'role' => $role,
        'gender' => $gender,
    ]);

    setPermissionsTeamId($school->id);
    $teacher->user->assignRole($role);
    setPermissionsTeamId(null);
    $teacher->user->unsetRelation('roles');
}

function ps_behavioralPayload(StudentCurriculum $studentCurriculum, string $grade = 'A'): array
{
    return [
        'student_curriculum_id' => $studentCurriculum->uuid,
        'punctuality' => $grade,
        'mental_alertness' => $grade,
        'respect' => $grade,
        'neatness' => $grade,
        'politeness' => $grade,
        'honesty' => $grade,
        'relationship_with_peers' => $grade,
        'teamwork' => $grade,
        'perseverance' => $grade,
        'comment' => 'Behavioral comment',
    ];
}

function ps_psychomotorPayload(StudentCurriculum $studentCurriculum, string $grade = 'B'): array
{
    return [
        'student_curriculum_id' => $studentCurriculum->uuid,
        'drawing_colouring' => $grade,
        'cutting_pasting' => $grade,
        'puzzles_building' => $grade,
        'climbing_sliding' => $grade,
        'comment' => 'Psychomotor comment',
    ];
}

// ---------------------------------------------------------------------------
// Boarding-parent psychomotor entry
// ---------------------------------------------------------------------------

it('lets a boarding parent create and update a psychomotor skill for a categorical enrollment', function () {
    $school = al_makeSchool();
    $arm = ps_classLevelArm($school);
    $term = ps_term($school);
    $curriculum = ps_curriculum($school, $arm, $term, categorical: true);
    $enrollment = ps_enrolledStudent($curriculum, 'male');
    $parent = ps_teacher($school, 'Boarding Parent');
    ps_assign($school, $parent, 'boarding_parent', $arm, 'male');

    $this->actingAs($parent->user)
        ->postJson('/api/psychomotor-skills', ps_psychomotorPayload($enrollment, 'B'))
        ->assertOk()
        ->assertJsonPath('data.drawing_colouring', 'B');

    $this->actingAs($parent->user)
        ->postJson('/api/psychomotor-skills', ps_psychomotorPayload($enrollment, 'C'))
        ->assertOk()
        ->assertJsonPath('data.drawing_colouring', 'C');

    expect(PsychomotorSkill::count())->toBe(1)
        ->and(PsychomotorSkill::first()->assessed_by)->toBe($parent->user->id);
});

it('rejects psychomotor skills for a numeric-grading enrollment', function () {
    $school = al_makeSchool();
    $arm = ps_classLevelArm($school);
    $term = ps_term($school);
    $curriculum = ps_curriculum($school, $arm, $term, categorical: false);
    $enrollment = ps_enrolledStudent($curriculum, 'male');
    $parent = ps_teacher($school, 'Boarding Parent');
    ps_assign($school, $parent, 'boarding_parent', $arm, 'male');

    $this->actingAs($parent->user)
        ->postJson('/api/psychomotor-skills', ps_psychomotorPayload($enrollment))
        ->assertStatus(422);
});

it('blocks a boarding parent from assessing a student outside their gender', function () {
    $school = al_makeSchool();
    $arm = ps_classLevelArm($school);
    $term = ps_term($school);
    $curriculum = ps_curriculum($school, $arm, $term, categorical: true);
    $enrollment = ps_enrolledStudent($curriculum, 'female');
    $parent = ps_teacher($school, 'Male Parent');
    ps_assign($school, $parent, 'boarding_parent', $arm, 'male');

    $this->actingAs($parent->user)
        ->postJson('/api/psychomotor-skills', ps_psychomotorPayload($enrollment))
        ->assertForbidden();
});

it('includes categorical flag and psychomotor record in the boarding parent index', function () {
    $school = al_makeSchool();
    $arm = ps_classLevelArm($school);
    $term = ps_term($school);
    $curriculum = ps_curriculum($school, $arm, $term, categorical: true);
    $enrollment = ps_enrolledStudent($curriculum, 'male');
    $parent = ps_teacher($school, 'Boarding Parent');
    ps_assign($school, $parent, 'boarding_parent', $arm, 'male');

    $this->actingAs($parent->user)
        ->postJson('/api/psychomotor-skills', ps_psychomotorPayload($enrollment, 'D'))
        ->assertOk();

    $this->actingAs($parent->user)
        ->getJson('/api/behavioral-assessments')
        ->assertOk()
        ->assertJsonPath('data.0.uses_categorical_grading', true)
        ->assertJsonPath('data.0.psychomotor.drawing_colouring', 'D');
});

// ---------------------------------------------------------------------------
// Form-teacher fallback
// ---------------------------------------------------------------------------

it('lets a form teacher record both assessments when the school has no boarding parents', function () {
    $school = al_makeSchool();
    $arm = ps_classLevelArm($school);
    $term = ps_term($school);
    $curriculum = ps_curriculum($school, $arm, $term, categorical: true);
    $enrollment = ps_enrolledStudent($curriculum, 'male');
    $formTeacher = ps_teacher($school, 'Form Teacher');
    ps_assign($school, $formTeacher, 'form_teacher', $arm);

    $this->actingAs($formTeacher->user)
        ->postJson('/api/behavioral-assessments', ps_behavioralPayload($enrollment))
        ->assertOk();

    $this->actingAs($formTeacher->user)
        ->postJson('/api/psychomotor-skills', ps_psychomotorPayload($enrollment))
        ->assertOk();

    expect(PsychomotorSkill::first()->assessed_by)->toBe($formTeacher->user->id);
});

it('blocks the form teacher fallback when the school has any boarding parent', function () {
    $school = al_makeSchool();
    $armA = ps_classLevelArm($school, 'JSS1');
    $armB = ps_classLevelArm($school, 'JSS2');
    $term = ps_term($school);
    $curriculum = ps_curriculum($school, $armA, $term, categorical: true);
    $enrollment = ps_enrolledStudent($curriculum, 'male');

    $formTeacher = ps_teacher($school, 'Form Teacher');
    ps_assign($school, $formTeacher, 'form_teacher', $armA);

    // A boarding parent exists elsewhere in the school.
    $parent = ps_teacher($school, 'Boarding Parent');
    ps_assign($school, $parent, 'boarding_parent', $armB, 'male');

    $this->actingAs($formTeacher->user)
        ->postJson('/api/behavioral-assessments', ps_behavioralPayload($enrollment))
        ->assertForbidden();

    $this->actingAs($formTeacher->user)
        ->postJson('/api/psychomotor-skills', ps_psychomotorPayload($enrollment))
        ->assertForbidden();
});

it('blocks a form teacher of a different arm even with no boarding parents', function () {
    $school = al_makeSchool();
    $armA = ps_classLevelArm($school, 'JSS1');
    $armB = ps_classLevelArm($school, 'JSS2');
    $term = ps_term($school);
    $curriculum = ps_curriculum($school, $armA, $term, categorical: true);
    $enrollment = ps_enrolledStudent($curriculum, 'male');

    $formTeacher = ps_teacher($school, 'Other Form Teacher');
    ps_assign($school, $formTeacher, 'form_teacher', $armB);

    $this->actingAs($formTeacher->user)
        ->postJson('/api/behavioral-assessments', ps_behavioralPayload($enrollment))
        ->assertForbidden();

    $this->actingAs($formTeacher->user)
        ->postJson('/api/psychomotor-skills', ps_psychomotorPayload($enrollment))
        ->assertForbidden();
});

it('rejects psychomotor skills for an upcoming term', function () {
    $school = al_makeSchool();
    $arm = ps_classLevelArm($school);
    $term = ps_term($school, TermStatusEnum::UPCOMING->value);
    $curriculum = ps_curriculum($school, $arm, $term, categorical: true);
    $enrollment = ps_enrolledStudent($curriculum, 'male');
    $formTeacher = ps_teacher($school, 'Form Teacher');
    ps_assign($school, $formTeacher, 'form_teacher', $arm);

    $this->actingAs($formTeacher->user)
        ->postJson('/api/psychomotor-skills', ps_psychomotorPayload($enrollment))
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Form-teacher students payload
// ---------------------------------------------------------------------------

it('returns can_assess with assessment data on the form teacher students endpoint', function () {
    $school = al_makeSchool();
    $arm = ps_classLevelArm($school);
    $term = ps_term($school);
    $curriculum = ps_curriculum($school, $arm, $term, categorical: true);
    $enrollment = ps_enrolledStudent($curriculum, 'male');
    $formTeacher = ps_teacher($school, 'Form Teacher');
    ps_assign($school, $formTeacher, 'form_teacher', $arm);

    $this->actingAs($formTeacher->user)
        ->postJson('/api/behavioral-assessments', ps_behavioralPayload($enrollment, 'E'))
        ->assertOk();

    $this->actingAs($formTeacher->user)
        ->getJson('/api/form-teacher/students')
        ->assertOk()
        ->assertJsonPath('data.can_assess', true)
        ->assertJsonPath('data.rows.0.student_curriculum_id', $enrollment->uuid)
        ->assertJsonPath('data.rows.0.uses_categorical_grading', true)
        ->assertJsonPath('data.rows.0.assessment.punctuality', 'E')
        ->assertJsonPath('data.rows.0.psychomotor', null);

    // Once a boarding parent exists, the fallback disappears.
    $parent = ps_teacher($school, 'Boarding Parent');
    ps_assign($school, $parent, 'boarding_parent', $arm, 'male');

    $this->actingAs($formTeacher->user)
        ->getJson('/api/form-teacher/students')
        ->assertOk()
        ->assertJsonPath('data.can_assess', false);
});

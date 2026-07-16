<?php

use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\BehavioralAssessment;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmTeacher;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TeacherAssignmentPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new RoleSeeder)->run();
    (new TeacherAssignmentPermissionSeeder)->run();
});

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

function ta_admin(School $school): User
{
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('admin');

    return $user;
}

function ta_teacher(School $school, string $firstName = 'Teacher'): Teacher
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

function ta_classLevelArm(School $school, string $className = 'JSS1', string $armLabel = 'Gold'): ClassLevelArm
{
    $classLevel = ClassLevel::create([
        'school_id' => $school->id,
        'name' => $className,
        'order' => 1,
    ]);

    $arm = Arm::create([
        'school_id' => $school->id,
        'label' => $armLabel,
    ]);

    return ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $classLevel->id,
        'arm_id' => $arm->id,
    ]);
}

function ta_activeTerm(School $school): Term
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
        'status' => TermStatusEnum::ACTIVE->value,
    ]);
}

function ta_examType(School $school): ExamType
{
    return ExamType::create([
        'school_id' => $school->id,
        'name' => 'Internal Exam',
        'slug' => 'exam-'.Str::random(8),
    ]);
}

function ta_curriculum(School $school, ClassLevelArm $classLevelArm, Term $term, ExamType $examType): Curriculum
{
    return Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $classLevelArm->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => false,
    ]);
}

function ta_enrolledStudent(Curriculum $curriculum, string $gender): StudentCurriculum
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

/**
 * Directly create class_level_arm_teacher pivot rows and grant the matching
 * Spatie role, bypassing the admin wizard endpoint.
 */
function ta_assign(School $school, Teacher $teacher, string $role, array $classLevelArmIds, ?string $gender = null): void
{
    foreach ($classLevelArmIds as $classLevelArmId) {
        ClassLevelArmTeacher::create([
            'class_level_arm_id' => $classLevelArmId,
            'teacher_id' => $teacher->id,
            'role' => $role,
            'gender' => $gender,
        ]);
    }

    if ($teacher->user) {
        setPermissionsTeamId($school->id);
        $teacher->user->assignRole($role);
    }
}

// ---------------------------------------------------------------------------
// Admin assignment wizard
// ---------------------------------------------------------------------------

it('assigns a form teacher and grants the form_teacher role', function () {
    $school = al_makeSchool();
    $admin = ta_admin($school);
    $arm = ta_classLevelArm($school);
    $teacher = ta_teacher($school, 'Form Teacher One');

    $this->actingAs($admin)
        ->postJson('/api/teacher-assignments', [
            'teacher_id' => $teacher->uuid,
            'role' => 'form_teacher',
            'class_level_arm_ids' => [$arm->uuid],
        ])
        ->assertCreated();

    $assignment = ClassLevelArmTeacher::where('class_level_arm_id', $arm->id)
        ->where('role', 'form_teacher')
        ->first();

    expect($assignment)->not->toBeNull();
    expect($assignment->teacher_id)->toBe($teacher->id);
    expect($assignment->gender)->toBeNull();
    expect($teacher->user->fresh()->hasRole('form_teacher'))->toBeTrue();
});

it('replaces the existing form teacher for an arm and revokes the role from the previous teacher', function () {
    $school = al_makeSchool();
    $admin = ta_admin($school);
    $arm = ta_classLevelArm($school);
    $teacherA = ta_teacher($school, 'Form Teacher A');
    $teacherB = ta_teacher($school, 'Form Teacher B');

    $this->actingAs($admin)->postJson('/api/teacher-assignments', [
        'teacher_id' => $teacherA->uuid,
        'role' => 'form_teacher',
        'class_level_arm_ids' => [$arm->uuid],
    ])->assertCreated();

    $this->actingAs($admin)->postJson('/api/teacher-assignments', [
        'teacher_id' => $teacherB->uuid,
        'role' => 'form_teacher',
        'class_level_arm_ids' => [$arm->uuid],
    ])->assertCreated();

    $assignments = ClassLevelArmTeacher::where('class_level_arm_id', $arm->id)
        ->where('role', 'form_teacher')
        ->get();

    expect($assignments)->toHaveCount(1);
    expect($assignments->first()->teacher_id)->toBe($teacherB->id);
    expect($teacherB->user->fresh()->hasRole('form_teacher'))->toBeTrue();
    expect($teacherA->user->fresh()->hasRole('form_teacher'))->toBeFalse();
});

it('limits boarding parents to one male and one female per arm and replaces on reassignment', function () {
    $school = al_makeSchool();
    $admin = ta_admin($school);
    $arm = ta_classLevelArm($school);
    $maleParentA = ta_teacher($school, 'Male Parent A');
    $femaleParent = ta_teacher($school, 'Female Parent');
    $maleParentB = ta_teacher($school, 'Male Parent B');

    $this->actingAs($admin)->postJson('/api/teacher-assignments', [
        'teacher_id' => $maleParentA->uuid,
        'role' => 'boarding_parent',
        'gender' => 'male',
        'class_level_arm_ids' => [$arm->uuid],
    ])->assertCreated();

    $this->actingAs($admin)->postJson('/api/teacher-assignments', [
        'teacher_id' => $femaleParent->uuid,
        'role' => 'boarding_parent',
        'gender' => 'female',
        'class_level_arm_ids' => [$arm->uuid],
    ])->assertCreated();

    expect(ClassLevelArmTeacher::where('class_level_arm_id', $arm->id)->where('role', 'boarding_parent')->count())->toBe(2);

    // Replace the male boarding parent
    $this->actingAs($admin)->postJson('/api/teacher-assignments', [
        'teacher_id' => $maleParentB->uuid,
        'role' => 'boarding_parent',
        'gender' => 'male',
        'class_level_arm_ids' => [$arm->uuid],
    ])->assertCreated();

    $assignments = ClassLevelArmTeacher::where('class_level_arm_id', $arm->id)
        ->where('role', 'boarding_parent')
        ->get();

    expect($assignments)->toHaveCount(2);

    $maleAssignment = $assignments->first(fn ($a) => $a->gender?->value === 'male');
    $femaleAssignment = $assignments->first(fn ($a) => $a->gender?->value === 'female');

    expect($maleAssignment->teacher_id)->toBe($maleParentB->id);
    expect($femaleAssignment->teacher_id)->toBe($femaleParent->id);
    expect($maleParentB->user->fresh()->hasRole('boarding_parent'))->toBeTrue();
    expect($maleParentA->user->fresh()->hasRole('boarding_parent'))->toBeFalse();
    expect($femaleParent->user->fresh()->hasRole('boarding_parent'))->toBeTrue();
});

it('assigns a head of school across multiple class arms with one pivot row per arm and replaces per-arm on reassignment', function () {
    $school = al_makeSchool();
    $admin = ta_admin($school);
    $armA = ta_classLevelArm($school, 'JSS1', 'Gold');
    $armB = ta_classLevelArm($school, 'JSS2', 'Gold');
    $hos = ta_teacher($school, 'Head One');

    $this->actingAs($admin)->postJson('/api/teacher-assignments', [
        'teacher_id' => $hos->uuid,
        'role' => 'head_of_school',
        'class_level_arm_ids' => [$armA->uuid, $armB->uuid],
    ])->assertCreated();

    $assignments = ClassLevelArmTeacher::where('role', 'head_of_school')->get();

    expect($assignments)->toHaveCount(2);
    expect($assignments->pluck('class_level_arm_id')->sort()->values()->all())
        ->toEqual([$armA->id, $armB->id]);
    expect($hos->user->fresh()->hasRole('head_of_school'))->toBeTrue();

    // Replace just armA's head of school
    $hos2 = ta_teacher($school, 'Head Two');

    $this->actingAs($admin)->postJson('/api/teacher-assignments', [
        'teacher_id' => $hos2->uuid,
        'role' => 'head_of_school',
        'class_level_arm_ids' => [$armA->uuid],
    ])->assertCreated();

    $armAAssignment = ClassLevelArmTeacher::where('class_level_arm_id', $armA->id)->where('role', 'head_of_school')->first();
    $armBAssignment = ClassLevelArmTeacher::where('class_level_arm_id', $armB->id)->where('role', 'head_of_school')->first();

    expect($armAAssignment->teacher_id)->toBe($hos2->id);
    expect($armBAssignment->teacher_id)->toBe($hos->id);
    expect($hos->user->fresh()->hasRole('head_of_school'))->toBeTrue(); // still supervises armB
    expect($hos2->user->fresh()->hasRole('head_of_school'))->toBeTrue();
});

it('rejects a boarding parent assignment without a gender', function () {
    $school = al_makeSchool();
    $admin = ta_admin($school);
    $arm = ta_classLevelArm($school);
    $teacher = ta_teacher($school);

    $this->actingAs($admin)->postJson('/api/teacher-assignments', [
        'teacher_id' => $teacher->uuid,
        'role' => 'boarding_parent',
        'class_level_arm_ids' => [$arm->uuid],
    ])->assertStatus(400);

    expect(ClassLevelArmTeacher::count())->toBe(0);
});

it('rejects a form teacher assignment to more than one class arm', function () {
    $school = al_makeSchool();
    $admin = ta_admin($school);
    $armA = ta_classLevelArm($school, 'JSS1', 'Gold');
    $armB = ta_classLevelArm($school, 'JSS2', 'Gold');
    $teacher = ta_teacher($school);

    $this->actingAs($admin)->postJson('/api/teacher-assignments', [
        'teacher_id' => $teacher->uuid,
        'role' => 'form_teacher',
        'class_level_arm_ids' => [$armA->uuid, $armB->uuid],
    ])->assertStatus(400);

    expect(ClassLevelArmTeacher::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// StudentCurriculum helper methods
// ---------------------------------------------------------------------------

it('resolves form teacher, gender-matched boarding parents, and head of school via StudentCurriculum helpers', function () {
    $school = al_makeSchool();
    $term = ta_activeTerm($school);
    $examType = ta_examType($school);
    $arm = ta_classLevelArm($school);
    $curriculum = ta_curriculum($school, $arm, $term, $examType);

    $formTeacher = ta_teacher($school, 'Form Teacher');
    $maleParent = ta_teacher($school, 'Male Parent');
    $femaleParent = ta_teacher($school, 'Female Parent');
    $headOfSchool = ta_teacher($school, 'Head of School');

    ta_assign($school, $formTeacher, 'form_teacher', [$arm->id]);
    ta_assign($school, $maleParent, 'boarding_parent', [$arm->id], 'male');
    ta_assign($school, $femaleParent, 'boarding_parent', [$arm->id], 'female');
    ta_assign($school, $headOfSchool, 'head_of_school', [$arm->id]);

    $maleStudentCurriculum = ta_enrolledStudent($curriculum, 'male');
    $femaleStudentCurriculum = ta_enrolledStudent($curriculum, 'female');

    expect($maleStudentCurriculum->formTeacher()->id)->toBe($formTeacher->id);
    expect($maleStudentCurriculum->headOfSchool()->id)->toBe($headOfSchool->id);
    expect($maleStudentCurriculum->maleBoardingParent()->id)->toBe($maleParent->id);
    expect($maleStudentCurriculum->femaleBoardingParent()->id)->toBe($femaleParent->id);
    expect($maleStudentCurriculum->boardingParent()->id)->toBe($maleParent->id);
    expect($femaleStudentCurriculum->boardingParent()->id)->toBe($femaleParent->id);
});

// ---------------------------------------------------------------------------
// Behavioral assessments (boarding parent)
// ---------------------------------------------------------------------------

it('shows a boarding parent only their gender-matched students and upserts assessments per term', function () {
    $school = al_makeSchool();
    $term = ta_activeTerm($school);
    $examType = ta_examType($school);
    $arm = ta_classLevelArm($school);
    $curriculum = ta_curriculum($school, $arm, $term, $examType);

    $maleParent = ta_teacher($school, 'Male Parent');
    ta_assign($school, $maleParent, 'boarding_parent', [$arm->id], 'male');

    $maleSC = ta_enrolledStudent($curriculum, 'male');
    $femaleSC = ta_enrolledStudent($curriculum, 'female');

    $response = $this->actingAs($maleParent->user)->getJson('/api/behavioral-assessments');
    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.student_curriculum_id'))->toBe($maleSC->uuid);

    $payload = [
        'student_curriculum_id' => $maleSC->uuid,
        'punctuality' => 'A',
        'mental_alertness' => 'A',
        'respect' => 'A',
        'neatness' => 'A',
        'politeness' => 'A',
        'honesty' => 'A',
        'relationship_with_peers' => 'A',
        'teamwork' => 'A',
        'perseverance' => 'A',
        'comment' => 'Excellent term.',
    ];

    $this->actingAs($maleParent->user)->postJson('/api/behavioral-assessments', $payload)->assertOk();

    expect(BehavioralAssessment::where('student_curriculum_id', $maleSC->id)->count())->toBe(1);

    // Re-submitting for the same term updates the existing assessment instead of creating a new one
    $this->actingAs($maleParent->user)
        ->postJson('/api/behavioral-assessments', [...$payload, 'punctuality' => 'C'])
        ->assertOk();

    expect(BehavioralAssessment::where('student_curriculum_id', $maleSC->id)->count())->toBe(1);
    expect(BehavioralAssessment::where('student_curriculum_id', $maleSC->id)->first()->punctuality->value)->toBe('C');

    // The female student is out of scope for this (male) boarding parent
    $this->actingAs($maleParent->user)
        ->postJson('/api/behavioral-assessments', [...$payload, 'student_curriculum_id' => $femaleSC->uuid])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Form teacher / head of school comments
// ---------------------------------------------------------------------------

it('restricts form teacher and head of school comment updates to their assigned class arms', function () {
    $school = al_makeSchool();
    $term = ta_activeTerm($school);
    $examType = ta_examType($school);
    $armA = ta_classLevelArm($school, 'JSS1', 'Gold');
    $armB = ta_classLevelArm($school, 'JSS2', 'Gold');
    $armC = ta_classLevelArm($school, 'JSS3', 'Gold');

    $curriculumA = ta_curriculum($school, $armA, $term, $examType);
    $curriculumB = ta_curriculum($school, $armB, $term, $examType);
    $curriculumC = ta_curriculum($school, $armC, $term, $examType);

    $scA = ta_enrolledStudent($curriculumA, 'male');
    $scB = ta_enrolledStudent($curriculumB, 'male');
    $scC = ta_enrolledStudent($curriculumC, 'male');

    // Form teacher is assigned only to arm A
    $formTeacher = ta_teacher($school, 'Form Teacher');
    ta_assign($school, $formTeacher, 'form_teacher', [$armA->id]);

    $ftIndex = $this->actingAs($formTeacher->user)->getJson('/api/form-teacher/students');
    $ftIndex->assertOk()->assertJsonCount(1, 'data.rows');
    expect($ftIndex->json('data.rows.0.student_curriculum_id'))->toBe($scA->uuid);

    $this->actingAs($formTeacher->user)
        ->patchJson("/api/form-teacher/students/{$scA->uuid}/comment", ['comment' => 'Great progress.'])
        ->assertOk();
    expect($scA->fresh()->form_teacher_comment)->toBe('Great progress.');

    $this->actingAs($formTeacher->user)
        ->patchJson("/api/form-teacher/students/{$scB->uuid}/comment", ['comment' => 'Out of scope'])
        ->assertForbidden();

    // Head of school supervises arms A and B
    $headOfSchool = ta_teacher($school, 'Head of School');
    ta_assign($school, $headOfSchool, 'head_of_school', [$armA->id, $armB->id]);

    $hosIndex = $this->actingAs($headOfSchool->user)->getJson('/api/head-of-school/students');
    $hosIndex->assertOk()->assertJsonCount(2, 'data');

    $this->actingAs($headOfSchool->user)
        ->patchJson("/api/head-of-school/students/{$scB->uuid}/comment", ['comment' => 'Keep it up.'])
        ->assertOk();
    expect($scB->fresh()->head_of_school_comment)->toBe('Keep it up.');

    $this->actingAs($headOfSchool->user)
        ->patchJson("/api/head-of-school/students/{$scC->uuid}/comment", ['comment' => 'Out of scope'])
        ->assertForbidden();
});

it('uses the active school term for form teacher students and outstanding comments', function () {
    // Create another school's active term first. The default term resolver
    // must not accidentally use it for the school in the request context.
    $otherSchool = al_makeSchool();
    ta_activeTerm($otherSchool);

    $school = al_makeSchool();
    $admin = ta_admin($school);
    $term = ta_activeTerm($school);
    $examType = ta_examType($school);
    $arm = ta_classLevelArm($school);
    $curriculum = ta_curriculum($school, $arm, $term, $examType);
    $studentCurriculum = ta_enrolledStudent($curriculum, 'male');
    $formTeacher = ta_teacher($school, 'Scoped Form Teacher');
    ta_assign($school, $formTeacher, 'form_teacher', [$arm->id]);

    $this->actingAs($formTeacher->user)
        ->withSession(['school_id' => $school->id])
        ->getJson('/api/form-teacher/students')
        ->assertOk()
        ->assertJsonPath('data.rows.0.student_curriculum_id', $studentCurriculum->uuid);

    $this->actingAs($admin)
        ->withSession(['school_id' => $school->id])
        ->getJson('/api/outstanding-comments')
        ->assertOk()
        ->assertJsonPath('data.form_teachers.0.teacher.id', $formTeacher->uuid)
        ->assertJsonPath('data.form_teachers.0.total', 1);
});

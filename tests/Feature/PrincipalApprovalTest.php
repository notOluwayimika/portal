<?php

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// C2 (role:->permission: swap): routes now authorize by GRANTS, not role
// names, so the locally-fabricated roles need the canonical grant map to
// reach the code under test.
beforeEach(fn () => (new RbacSeeder)->run());

function pa_setup(): array
{
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    $principal = al_makeUser($school->id);

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'principal', 'guard_name' => 'web']);
    setPermissionsTeamId($school->id);
    $admin->assignRole('admin');
    $principal->assignRole('principal');

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
        'school_id' => $session->school_id,
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
    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Ada',
        'last_name' => 'Student',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);
    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]);

    return compact('school', 'admin', 'principal', 'level', 'classLevelArm', 'enrollment');
}

it('lets principals and admins approve active class results', function (string $actor) {
    $data = pa_setup();

    $this->actingAs($data[$actor])
        ->withSession(['school_id' => $data['school']->id])
        ->patchJson("/api/class-levels/{$data['level']->uuid}/principal-approval", ['approved' => true])
        ->assertOk()
        ->assertJsonPath('updated', 1);

    expect($data['enrollment']->fresh()->principal_approval)->toBeTrue();
})->with(['principal', 'admin']);

it('does not change historical enrollments when disapproving an arm', function () {
    $data = pa_setup();
    $data['enrollment']->update(['status' => 'promoted', 'principal_approval' => true]);

    $this->actingAs($data['principal'])
        ->withSession(['school_id' => $data['school']->id])
        ->patchJson("/api/class-level-arms/{$data['classLevelArm']->uuid}/principal-approval", ['approved' => false])
        ->assertOk()
        ->assertJsonPath('updated', 0);

    expect($data['enrollment']->fresh()->principal_approval)->toBeTrue();
});

it('allows an admin to create a principal in the active school', function () {
    $data = pa_setup();

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->post('/setup/principals', [
            'first_name' => 'New',
            'last_name' => 'Principal',
            'email' => 'principal@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->assertRedirect();

    $created = User::where('email', 'principal@example.test')->firstOrFail();
    expect($created->school_id)->toBe($data['school']->id)
        ->and($created->hasRole('principal'))->toBeTrue();
});

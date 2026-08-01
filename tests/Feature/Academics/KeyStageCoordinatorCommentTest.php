<?php

use App\Enums\TeacherAssignmentRoleEnum;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmTeacher;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Teacher;
use App\Models\Term;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Primary calls its senior commenter a Key Stage Coordinator; secondary calls the
 * same job a Head of School. The seat is assignment-scoped: holding the permission
 * is not enough, you must hold the ARM.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

function ksc_world(): array
{
    $school = al_makeSchool();

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'key_stage_coordinator', 'guard_name' => 'web']);
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('key_stage_coordinator');

    $coordinator = Teacher::create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'staff_number' => 'STF-'.fake()->unique()->numerify('#####'),
        'first_name' => 'Ada',
        'last_name' => 'Coordinator',
    ]);

    $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'Year 3', 'order' => 3]);
    $session = AcademicSession::create([
        'school_id' => $school->id,
        'name' => '2026/2027',
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

    // Two arms: one the coordinator holds, one they do not.
    $makeArm = function (string $label) use ($school, $level, $term, $examType) {
        $arm = Arm::create(['school_id' => $school->id, 'label' => $label]);
        $classLevelArm = ClassLevelArm::forceCreate([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'arm_id' => $arm->id,
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
            'first_name' => 'Pupil'.$label,
            'last_name' => 'Test',
            'gender' => 'female',
            'admission_number' => 'ADM-'.Str::random(8),
        ]);
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        return [$classLevelArm, $student, $enrollment];
    };

    [$mine, $myStudent, $myEnrollment] = $makeArm('A');
    [$theirs, , $theirEnrollment] = $makeArm('B');

    ClassLevelArmTeacher::create([
        'class_level_arm_id' => $mine->id,
        'teacher_id' => $coordinator->id,
        'role' => TeacherAssignmentRoleEnum::KEY_STAGE_COORDINATOR->value,
    ]);

    return compact('school', 'user', 'coordinator', 'mine', 'theirs', 'myStudent', 'myEnrollment', 'theirEnrollment', 'term');
}

it('lists only enrolments in the arms the coordinator holds', function () {
    $w = ksc_world();

    $response = $this->actingAs($w['user'])
        ->withSession(['school_id' => $w['school']->id])
        ->getJson('/api/key-stage-coordinator/students')
        ->assertOk();

    $rows = $response->json('data');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['student_curriculum_id'])->toBe($w['myEnrollment']->uuid);
});

it('saves a comment for a held arm', function () {
    $w = ksc_world();

    $this->actingAs($w['user'])
        ->withSession(['school_id' => $w['school']->id])
        ->patchJson("/api/key-stage-coordinator/students/{$w['myEnrollment']->uuid}/comment", [
            'comment' => 'Steady progress across the key stage.',
        ])
        ->assertOk()
        ->assertJsonPath('data.comment', 'Steady progress across the key stage.');

    expect($w['myEnrollment']->fresh()->key_stage_coordinator_comment)
        ->toBe('Steady progress across the key stage.');
});

it('REFUSES a comment on an arm the coordinator does not hold', function () {
    $w = ksc_world();

    // The permission is held; the assignment is not. Listing already filters, so
    // this pins the WRITE guard specifically — without it the uuid alone would do.
    $this->actingAs($w['user'])
        ->withSession(['school_id' => $w['school']->id])
        ->patchJson("/api/key-stage-coordinator/students/{$w['theirEnrollment']->uuid}/comment", [
            'comment' => 'Should never land.',
        ])
        ->assertStatus(403);

    expect($w['theirEnrollment']->fresh()->key_stage_coordinator_comment)->toBeNull();
});

it('omits enrolments whose student has been withdrawn', function () {
    $w = ksc_world();

    $w['myStudent']->delete();

    $response = $this->actingAs($w['user'])
        ->withSession(['school_id' => $w['school']->id])
        ->getJson('/api/key-stage-coordinator/students')
        ->assertOk();

    expect($response->json('data'))->toBeEmpty();
});

it('leaves the comment out of reach of a user without the permission', function () {
    $w = ksc_world();

    $stranger = al_makeUser($w['school']->id);

    $this->actingAs($stranger)
        ->withSession(['school_id' => $w['school']->id])
        ->getJson('/api/key-stage-coordinator/students')
        ->assertForbidden();
});

/**
 * THE GAP THAT SHIPPED. The coordinator's NAME reached the result card (via the
 * `keyStageCoordinator` object on the details payload) while their COMMENT did not,
 * because StudentCurriculumResource never exposed the column. The tests above assert
 * the coordinator's own endpoint, which is why none of them noticed — the result
 * card reads a different payload.
 */
it('exposes the comment on the payload the result card actually reads', function () {
    $w = ksc_world();

    $w['myEnrollment']->update(['key_stage_coordinator_comment' => 'Reads fluently.']);

    // Acting as an ADMIN, not the coordinator: this endpoint feeds the printed
    // result, which the coordinator never opens. Reading it as them would 403 and
    // prove nothing about the card.
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = al_makeUser($w['school']->id);
    setPermissionsTeamId($w['school']->id);
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)
        ->withSession(['school_id' => $w['school']->id])
        ->getJson("/api/student-curricula/{$w['myEnrollment']->uuid}")
        ->assertOk();

    // Both halves: the name comes from one key, the comment from another, and the
    // card needs both to print the pair.
    expect($response->json('keyStageCoordinator.id'))->not->toBeNull();
    expect($response->json('studentCurriculum.key_stage_coordinator_comment'))
        ->toBe('Reads fluently.');
});

/**
 * The comment and assessment screens filter by class level and arm CLIENT-SIDE, so
 * the rows have to carry identifiers. They previously carried only `class_name`, a
 * formatted label, which cannot be filtered on without parsing it.
 */
/**
 * A coordinator covers several classes and will not know every pupil as their class
 * teacher does, so the class teacher's comment is shown as context to write from.
 * Read-only: exposing it must not make it writable.
 */
it('shows the class teacher comment as context', function () {
    $w = ksc_world();

    $w['myEnrollment']->update(['form_teacher_comment' => 'Settled well this term.']);

    $response = $this->actingAs($w['user'])
        ->withSession(['school_id' => $w['school']->id])
        ->getJson('/api/key-stage-coordinator/students')
        ->assertOk();

    expect($response->json('data.0.form_teacher_comment'))
        ->toBe('Settled well this term.');
});

it('REFUSES to let a coordinator rewrite the class teacher comment', function () {
    $w = ksc_world();

    $w['myEnrollment']->update(['form_teacher_comment' => 'Settled well this term.']);

    $this->actingAs($w['user'])
        ->withSession(['school_id' => $w['school']->id])
        ->patchJson("/api/key-stage-coordinator/students/{$w['myEnrollment']->uuid}/comment", [
            'comment' => 'Agreed.',
            'form_teacher_comment' => 'Overwritten.',
        ])
        ->assertOk();

    // Their own comment lands; the class teacher's is untouched. update() validates
    // `comment` alone, so the extra key is ignored rather than applied.
    expect($w['myEnrollment']->fresh()->key_stage_coordinator_comment)->toBe('Agreed.')
        ->and($w['myEnrollment']->fresh()->form_teacher_comment)->toBe('Settled well this term.');
});

it('ships class level and arm identifiers on each row for the filters', function () {
    $w = ksc_world();

    $response = $this->actingAs($w['user'])
        ->withSession(['school_id' => $w['school']->id])
        ->getJson('/api/key-stage-coordinator/students')
        ->assertOk();

    $row = $response->json('data.0');

    expect($row['class_name'])->not->toBeNull()
        ->and($row['class_level']['id'])->toBe($w['mine']->classLevel->uuid)
        ->and($row['class_level']['name'])->toBe('Year 3')
        ->and($row['class_level_arm']['id'])->toBe($w['mine']->uuid);
});

it('defaults the result-template flags so existing schools print what they always printed', function () {
    $school = School::factory()->create();

    expect($school->fresh()->show_subject_comments_on_result)->toBeTrue()
        ->and($school->fresh()->show_head_of_school_comment_on_result)->toBeTrue()
        ->and($school->fresh()->show_behaviour_comment_on_result)->toBeTrue()
        ->and($school->fresh()->result_approver_title)->toBeNull();
});

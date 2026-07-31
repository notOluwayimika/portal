<?php

use App\Enums\StudentSubjectStatus;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentResult;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\SubjectResultStatus;
use App\Models\Term;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * "Unassign a subject" had no honest implementation.
 *
 *  - DELETE could not run once anyone was enrolled: student_subjects is the one FK
 *    on curriculum_subjects that does not cascade (ON DELETE NO ACTION), so InnoDB
 *    raised 1451 and the controller flattened it into a 500 reading
 *    "Failed to delete curriculum subject".
 *  - ARCHIVE ran, but left every enrollment `active`. Result sheets and the
 *    readiness check both read student_subjects, not archived_at, so an archived
 *    subject kept printing and kept counting as a missing result.
 *
 * withdraw() is the missing act: archive the subject AND drop its enrollments, in
 * one transaction, keeping every recorded mark.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

function wcs_setup(bool $compulsory = true): array
{
    $school = al_makeSchool();

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $admin->assignRole('admin');

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

    $subject = Subject::create(['school_id' => $school->id, 'name' => 'Physics']);
    $curriculumSubject = CurriculumSubject::create([
        'curriculum_id' => $curriculum->id,
        'subject_id' => $subject->id,
        'is_compulsory' => $compulsory,
        'display_order' => 1,
    ]);

    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Ada',
        'last_name' => 'Pupil',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);
    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]);

    // Compulsory subjects are auto-attached by the enrollment observer; an
    // optional one has to be attached explicitly.
    if (! $compulsory) {
        StudentSubject::create([
            'student_curriculum_id' => $enrollment->id,
            'curriculum_subject_id' => $curriculumSubject->id,
            'status' => StudentSubjectStatus::Active,
        ]);
    }

    return compact('school', 'admin', 'curriculumSubject', 'student', 'enrollment');
}

it('withdraws every enrollment and archives the subject in one act', function () {
    $data = wcs_setup();
    $cs = $data['curriculumSubject'];

    expect($cs->studentAssignments()->where('status', StudentSubjectStatus::Active)->count())->toBe(1);

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->patchJson("/api/curriculum-subjects/{$cs->uuid}/withdraw")
        ->assertOk()
        ->assertJsonPath('withdrawn_count', 1);

    $cs->refresh();

    expect($cs->isArchived())->toBeTrue()
        ->and($cs->active)->toBeFalse()
        ->and($cs->studentAssignments()->where('status', StudentSubjectStatus::Active)->count())->toBe(0)
        ->and($cs->studentAssignments()->where('status', StudentSubjectStatus::Dropped)->count())->toBe(1);

    $dropped = $cs->studentAssignments()->first();

    expect($dropped->dropped_at)->not->toBeNull()
        ->and($dropped->dropped_by_user_id)->toBe($data['admin']->id)
        ->and($dropped->drop_reason)->toContain('withdrawn from the curriculum');
});

it('withdraws a COMPULSORY subject, which the per-pupil drop refuses', function () {
    $data = wcs_setup(compulsory: true);
    $cs = $data['curriculumSubject'];

    // The pupil-facing rule still holds — this is the exemption being deliberate.
    expect($cs->studentAssignments()->first()->canBeDropped())->toBeFalse();

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->patchJson("/api/curriculum-subjects/{$cs->uuid}/withdraw")
        ->assertOk()
        ->assertJsonPath('withdrawn_count', 1);
});

it('keeps every recorded mark when withdrawing', function () {
    $data = wcs_setup();
    $cs = $data['curriculumSubject'];

    $result = StudentResult::create([
        'student_id' => $data['student']->id,
        'curriculum_subject_id' => $cs->id,
        'total_score' => 74,
        'grade' => 'B',
        'status' => 'draft',
        'computed_at' => now(),
    ]);

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->patchJson("/api/curriculum-subjects/{$cs->uuid}/withdraw")
        ->assertOk();

    // The marks are the point: a mis-click must be recoverable.
    expect(StudentResult::find($result->id))->not->toBeNull()
        ->and(StudentResult::find($result->id)->total_score)->toEqual(74);
});

it('refuses to withdraw when results are locked', function (string $status) {
    $data = wcs_setup();
    $cs = $data['curriculumSubject'];

    SubjectResultStatus::create([
        'curriculum_subject_id' => $cs->id,
        'status' => $status,
    ]);

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->patchJson("/api/curriculum-subjects/{$cs->uuid}/withdraw")
        ->assertStatus(422)
        ->assertJsonPath('error', 'Results are locked for this subject. Contact an administrator.');

    expect($cs->fresh()->isArchived())->toBeFalse()
        ->and($cs->studentAssignments()->where('status', StudentSubjectStatus::Active)->count())->toBe(1);
})->with(['submitted', 'approved']);

it('refuses to DELETE a subject that has enrollments, with an actionable 409', function () {
    $data = wcs_setup();
    $cs = $data['curriculumSubject'];

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->deleteJson("/api/curriculum-subjects/{$cs->uuid}")
        ->assertStatus(409)
        ->assertJsonPath('error', 'This subject has enrolled students — withdraw it instead.')
        ->assertJsonPath('enrolled_count', 1);

    expect(CurriculumSubject::find($cs->id))->not->toBeNull();
});

it('still deletes a subject nobody was ever enrolled in', function () {
    $data = wcs_setup();

    // A second subject, assigned in error and never taught.
    $spare = CurriculumSubject::create([
        'curriculum_id' => $data['curriculumSubject']->curriculum_id,
        'subject_id' => Subject::create(['school_id' => $data['school']->id, 'name' => 'Typo'])->id,
        'is_compulsory' => false,
        'display_order' => 2,
    ]);

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->deleteJson("/api/curriculum-subjects/{$spare->uuid}")
        ->assertOk();

    expect(CurriculumSubject::find($spare->id))->toBeNull();
});

it('warns on unarchive that dropped enrollments stay dropped', function () {
    $data = wcs_setup();
    $cs = $data['curriculumSubject'];

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->patchJson("/api/curriculum-subjects/{$cs->uuid}/withdraw")
        ->assertOk();

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->patchJson("/api/curriculum-subjects/{$cs->uuid}/unarchive")
        ->assertOk()
        ->assertJsonPath('dropped_enrollment_count', 1)
        ->assertJsonPath(
            'warning',
            '1 enrollment(s) remain dropped for this subject and must be restored per student.'
        );

    // Asymmetry is the accepted design: unarchiving offers the subject again but
    // restores nobody.
    expect($cs->studentAssignments()->where('status', StudentSubjectStatus::Active)->count())->toBe(0);
});

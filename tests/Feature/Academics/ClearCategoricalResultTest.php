<?php

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\GradingScheme;
use App\Models\GradingSchemeItem;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentResult;
use App\Models\Subject;
use App\Models\SubjectResultStatus;
use App\Models\Term;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * A categorical rating could be OVERWRITTEN but never removed. The grid's
 * "Select rating" placeholder is `disabled`, so once anything was picked there was
 * no route back to "not assessed" — a mis-click on the wrong pupil was permanent,
 * and the result sheet then reported a rating nobody meant to give. The numeric
 * grid has had clearScore for exactly this.
 *
 * Clearing DELETES the row rather than nulling grading_scheme_item_id, because an
 * absent student_results row is what "not assessed" already means everywhere else
 * — the numeric clear deletes too, and the readiness check tests for existence.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

function ccr_setup(bool $categorical = true): array
{
    $school = al_makeSchool();

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $admin->assignRole('admin');

    $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'Nursery 1', 'order' => 1]);
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

    $schemeId = null;
    $item = null;

    if ($categorical) {
        $scheme = GradingScheme::create([
            'school_id' => $school->id,
            'family_uuid' => (string) Str::uuid(),
            'name' => 'Progress Ratings',
        ]);
        $item = GradingSchemeItem::create([
            'grading_scheme_id' => $scheme->id,
            'code' => 'E',
            'label' => 'Emerging',
        ]);
        $schemeId = $scheme->id;
    }

    $curriculum = Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $classLevelArm->id,
        'exam_type_id' => $examType->id,
        'grading_scheme_id' => $schemeId,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
    ]);

    $subject = Subject::create(['school_id' => $school->id, 'name' => 'Phonics']);
    $curriculumSubject = CurriculumSubject::create([
        'curriculum_id' => $curriculum->id,
        'subject_id' => $subject->id,
        'is_compulsory' => true,
        'display_order' => 1,
    ]);

    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Ada',
        'last_name' => 'Pupil',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    // The compulsory subject is attached by the enrollment observer.
    StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]);

    return compact('school', 'admin', 'curriculumSubject', 'student', 'item');
}

function ccr_rate(array $data): StudentResult
{
    return StudentResult::create([
        'student_id' => $data['student']->id,
        'curriculum_subject_id' => $data['curriculumSubject']->id,
        'grading_scheme_item_id' => $data['item']->id,
        'total_score' => null,
        'grade' => $data['item']->code,
        'status' => 'draft',
        'computed_at' => now(),
    ]);
}

it('clears a categorical rating back to not-assessed', function () {
    $data = ccr_setup();
    $result = ccr_rate($data);

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->deleteJson("/api/curriculum-subjects/{$data['curriculumSubject']->uuid}/categorical-results/{$data['student']->uuid}")
        ->assertNoContent();

    // The ROW is gone, not merely nulled: absent is what "not assessed" means.
    expect(StudentResult::find($result->id))->toBeNull();
});

it('is idempotent when there is nothing to clear', function () {
    $data = ccr_setup();

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->deleteJson("/api/curriculum-subjects/{$data['curriculumSubject']->uuid}/categorical-results/{$data['student']->uuid}")
        ->assertNoContent();
});

it('refuses to clear once results are locked', function (string $status) {
    $data = ccr_setup();
    $result = ccr_rate($data);

    SubjectResultStatus::create([
        'curriculum_subject_id' => $data['curriculumSubject']->id,
        'status' => $status,
    ]);

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->deleteJson("/api/curriculum-subjects/{$data['curriculumSubject']->uuid}/categorical-results/{$data['student']->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('error', 'Results are locked. Contact an administrator.');

    expect(StudentResult::find($result->id))->not->toBeNull();
})->with(['submitted', 'approved']);

it('refuses on a numerically-graded curriculum', function () {
    $data = ccr_setup(categorical: false);

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->deleteJson("/api/curriculum-subjects/{$data['curriculumSubject']->uuid}/categorical-results/{$data['student']->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('error', 'This curriculum uses numerical grading.');
});

it('refuses for a student not enrolled in the subject', function () {
    $data = ccr_setup();

    $stranger = Student::create([
        'school_id' => $data['school']->id,
        'first_name' => 'Bola',
        'last_name' => 'Outsider',
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->deleteJson("/api/curriculum-subjects/{$data['curriculumSubject']->uuid}/categorical-results/{$stranger->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('error', 'Student is not enrolled in this subject.');
});

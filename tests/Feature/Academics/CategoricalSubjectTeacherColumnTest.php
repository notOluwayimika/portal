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
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherCurriculumSubject;
use App\Models\Term;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * The categorical result card's "Subject Teacher" column reads the payload
 * directly — `curriculum_subject.teachers[0].teacher.full_name` — and
 * CurriculumSubjectResource emits `teachers` under whenLoaded('teacherAssignments').
 * ClassResultsController never loaded that relation, so on the class-level and
 * class-level-arm result sheets the key was ABSENT and every row fell through to
 * the `|| '—'` default however many teachers were assigned.
 *
 * The numeric card hid the bug: SubjectRow fetches
 * GET /curriculum-subjects/{uuid}/teachers itself rather than reading the payload,
 * so only the categorical column ever depended on this eager load.
 *
 * This pins the PAYLOAD, which is where the defect lives — the column is a plain
 * read of it, and there is no JS test runner in this project.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

function cstc_setup(): array
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

    // CATEGORICAL is not a type column or an enum — it is the presence of
    // grading_scheme_id (Curriculum::usesCategoricalGrading).
    $scheme = GradingScheme::create([
        'school_id' => $school->id,
        'family_uuid' => (string) Str::uuid(),
        'name' => 'Progress Ratings',
    ]);
    GradingSchemeItem::create([
        'grading_scheme_id' => $scheme->id,
        'code' => 'E',
        'label' => 'Emerging',
    ]);

    $curriculum = Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $classLevelArm->id,
        'exam_type_id' => $examType->id,
        'grading_scheme_id' => $scheme->id,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
    ]);

    expect($curriculum->usesCategoricalGrading())->toBeTrue();

    $subject = Subject::create(['school_id' => $school->id, 'name' => 'Phonics']);
    $curriculumSubject = CurriculumSubject::create([
        'curriculum_id' => $curriculum->id,
        'subject_id' => $subject->id,
        'is_compulsory' => true,
        'display_order' => 1,
    ]);

    $teacher = Teacher::create([
        'school_id' => $school->id,
        'staff_number' => 'STF-'.fake()->unique()->numerify('#####'),
        'first_name' => 'Cecilia',
        'last_name' => 'Wonah',
    ]);
    TeacherCurriculumSubject::create([
        'teacher_id' => $teacher->id,
        'curriculum_subject_id' => $curriculumSubject->id,
    ]);

    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => 'Ada',
        'last_name' => 'Pupil',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);
    // The compulsory subject is attached by the enrollment observer — creating
    // the StudentSubject here as well violates student_subject_unique.
    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]);

    expect(StudentSubject::where('student_curriculum_id', $enrollment->id)->count())->toBe(1);

    return compact('school', 'admin', 'level', 'classLevelArm', 'teacher');
}

/** Pull every subject row the categorical card would iterate. */
function cstc_subjectRows(array $props): array
{
    return collect($props['classLevelArms']['data'])
        ->flatMap(fn (array $arm) => $arm['curricula'] ?? [])
        ->flatMap(fn (array $curriculum) => $curriculum['student_curricula'] ?? [])
        ->flatMap(fn (array $enrollment) => $enrollment['subjects'] ?? [])
        ->all();
}

it('ships the assigned subject teacher on the class-arm result sheet', function () {
    $data = cstc_setup();

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->get("class-level-arm/{$data['classLevelArm']->uuid}/results")
        ->assertOk()
        ->assertInertia(function ($page) {
            $rows = cstc_subjectRows($page->toArray()['props']);

            expect($rows)->toHaveCount(1);

            $curriculumSubject = $rows[0]['curriculum_subject'];

            // The key must EXIST — whenLoaded omits it entirely when the relation
            // is not eager-loaded, which is what produced the '—'.
            expect($curriculumSubject)->toHaveKey('teachers');
            expect($curriculumSubject['teachers'])->toHaveCount(1);
            expect($curriculumSubject['teachers'][0]['teacher']['full_name'])
                ->toBe('Wonah Cecilia');
        });
});

it('ships it on the class-level result sheet too', function () {
    $data = cstc_setup();

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->get("class-level/{$data['level']->uuid}/results")
        ->assertOk()
        ->assertInertia(function ($page) {
            $rows = cstc_subjectRows($page->toArray()['props']);

            expect($rows[0]['curriculum_subject']['teachers'][0]['teacher']['full_name'])
                ->toBe('Wonah Cecilia');
        });
});

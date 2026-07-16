<?php

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentResult;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Builds a school with an admin, an active curriculum and two compulsory
 * subjects. Enrolling a student auto-attaches both subjects (observer).
 */
function ir_setup(): array
{
    $school = al_makeSchool();

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);
    $admin->unsetRelation('roles');

    $classLevel = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS1', 'order' => 1]);
    $arm = Arm::create(['school_id' => $school->id, 'label' => 'Gold']);
    $cla = ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $classLevel->id,
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
        'end_date' => now()->addMonths(2),
        'status' => 'active',
    ]);

    $examType = ExamType::create([
        'school_id' => $school->id,
        'name' => 'Internal Exam',
        'slug' => 'exam-'.Str::random(8),
    ]);

    $curriculum = Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $cla->id,
        'exam_type_id' => $examType->id,
        'status' => 'active',
        'is_ccm' => false,
        'min_subjects' => 1,
    ]);

    $math = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics']);
    $english = Subject::create(['school_id' => $school->id, 'name' => 'English']);

    $csMath = CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $math->id, 'is_compulsory' => true, 'active' => true]);
    $csEnglish = CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $english->id, 'is_compulsory' => true, 'active' => true]);

    return compact('school', 'admin', 'curriculum', 'csMath', 'csEnglish');
}

function ir_enrollStudent(School $school, Curriculum $curriculum, string $name): array
{
    $student = Student::create([
        'school_id' => $school->id,
        'first_name' => $name,
        'last_name' => Str::random(6),
        'gender' => 'male',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);

    $enrollment = StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]);

    return [$student, $enrollment];
}

function ir_result(Student $student, CurriculumSubject $cs): StudentResult
{
    return StudentResult::create([
        'student_id' => $student->id,
        'curriculum_subject_id' => $cs->id,
        'total_score' => 80,
        'grade' => 'A',
        'status' => 'approved',
    ]);
}

it('lists enrollments with missing results and excludes complete ones', function () {
    $s = ir_setup();

    // Complete: results for both subjects.
    [$done] = ir_enrollStudent($s['school'], $s['curriculum'], 'Complete');
    ir_result($done, $s['csMath']);
    ir_result($done, $s['csEnglish']);

    // Incomplete: result for Math only.
    [$partial] = ir_enrollStudent($s['school'], $s['curriculum'], 'Partial');
    ir_result($partial, $s['csMath']);

    $response = $this->actingAs($s['admin'])
        ->withSession(['school_id' => $s['school']->id])
        ->getJson('/api/results/incomplete')
        ->assertOk();

    $rows = collect($response->json('data'));

    expect($rows->pluck('student.uuid'))->toContain($partial->uuid)
        ->not->toContain($done->uuid);

    $row = $rows->firstWhere('student.uuid', $partial->uuid);

    expect($row['missing_results'])->toBe(1)
        ->and($row['subjects_offered'])->toBe(2)
        ->and(collect($row['missing_subjects'])->pluck('name'))->toContain('English')
        ->and($row['reason'])->toBe('missing_results');
});

it('filters incomplete results by curriculum uuid', function () {
    $s = ir_setup();

    [$partial] = ir_enrollStudent($s['school'], $s['curriculum'], 'Partial');
    ir_result($partial, $s['csMath']);

    $this->actingAs($s['admin'])
        ->withSession(['school_id' => $s['school']->id])
        ->getJson('/api/results/incomplete?curriculum_id='.$s['curriculum']->uuid)
        ->assertOk()
        ->assertJsonPath('data.0.student.uuid', $partial->uuid)
        ->assertJsonPath('pagination.total', 1);
});

it('filters incomplete results by reason', function () {
    $s = ir_setup();

    // Missing results: enrolled with subjects, one result absent.
    [$partial] = ir_enrollStudent($s['school'], $s['curriculum'], 'Partial');
    ir_result($partial, $s['csMath']);

    // No active subjects: enrolled, then every subject dropped.
    [$empty, $emptyEnrollment] = ir_enrollStudent($s['school'], $s['curriculum'], 'Empty');
    StudentSubject::where('student_curriculum_id', $emptyEnrollment->id)
        ->update(['status' => 'dropped']);

    $asAdmin = fn (string $query) => $this->actingAs($s['admin'])
        ->withSession(['school_id' => $s['school']->id])
        ->getJson('/api/results/incomplete'.$query);

    // Unfiltered: both appear.
    expect(collect($asAdmin('')->assertOk()->json('data'))->pluck('student.uuid'))
        ->toContain($partial->uuid)
        ->toContain($empty->uuid);

    // missing_results: only the partially graded enrollment.
    $missing = collect($asAdmin('?reason=missing_results')->assertOk()->json('data'));
    expect($missing->pluck('student.uuid'))->toContain($partial->uuid)->not->toContain($empty->uuid);
    expect($missing->pluck('reason')->unique()->all())->toBe(['missing_results']);

    // no_active_subjects: only the fully dropped enrollment.
    $none = collect($asAdmin('?reason=no_active_subjects')->assertOk()->json('data'));
    expect($none->pluck('student.uuid'))->toContain($empty->uuid)->not->toContain($partial->uuid);
    expect($none->pluck('reason')->unique()->all())->toBe(['no_active_subjects']);
});

it('blocks non-admin roles from the incomplete results endpoint', function () {
    $s = ir_setup();
    $user = al_makeUser($s['school']->id);

    $this->actingAs($user)
        ->withSession(['school_id' => $s['school']->id])
        ->getJson('/api/results/incomplete')
        ->assertForbidden();
});

it('allows a head of school to view incomplete results', function () {
    $s = ir_setup();

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'head_of_school', 'guard_name' => 'web']);
    $hos = al_makeUser($s['school']->id);
    setPermissionsTeamId($s['school']->id);
    $hos->assignRole('head_of_school');
    setPermissionsTeamId(null);
    $hos->unsetRelation('roles');

    [$partial] = ir_enrollStudent($s['school'], $s['curriculum'], 'Partial');
    ir_result($partial, $s['csMath']);

    $this->actingAs($hos)
        ->withSession(['school_id' => $s['school']->id])
        ->getJson('/api/results/incomplete')
        ->assertOk()
        ->assertJsonPath('data.0.student.uuid', $partial->uuid);

    // The web page is reachable for head_of_school too.
    $this->actingAs($hos)
        ->withSession(['school_id' => $s['school']->id])
        ->get('/results/incomplete')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/results/incomplete'));
});

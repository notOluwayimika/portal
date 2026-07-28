<?php

use App\Http\Resources\StudentResource;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmTeacher;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherCurriculumSubject;
use App\Models\Term;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Withdrawing a student must not take a teacher's page down.
 *
 * `students` is SOFT-DELETED and School-scoped, but `student_curricula` and `student_subjects`
 * rows outlive the withdrawal — so `$enrolment->student` resolves to NULL while the enrolment
 * still reads 'active'. Several endpoints dereferenced that relation directly, and production hit
 * two of them:
 *
 *   GET  /api/form-teacher/students          → "Call to a member function studentCurricula() on null"
 *   POST /api/curriculum-subjects/{id}/submit → "Attempt to read property \"id\" on null"
 *
 * One student leaving broke result submission for their whole class. These tests withdraw a
 * student exactly as the app does — soft-delete, leave the enrolment rows — and assert the pages
 * survive and simply stop listing them.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->school = al_makeSchool();
    setPermissionsTeamId($this->school->id);
});

/** A teacher account with a Teacher row, holding $role in this school. */
function ws_teacher(object $school, string $role = 'teacher'): array
{
    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, $role);
    $user->flushSchoolAccessCache();

    $teacher = ActiveSchool::runFor($school->id, fn () => Teacher::create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'first_name' => 'Tee',
        'last_name' => 'Cher '.Str::random(4),
        'status' => 'active',
    ]));

    return [$user, $teacher];
}

/** A curriculum + subject with one enrolled student. Returns [cs, student, enrolment]. */
function ws_classWithStudent(object $school): array
{
    return ActiveSchool::runFor($school->id, function () use ($school) {
        // A real class-level-arm: the form-teacher and head-of-school listings key off it, and
        // the factory leaves it null.
        $classLevelArm = ClassLevelArm::create([
            'school_id' => $school->id,
            'class_level_id' => ClassLevel::create([
                'school_id' => $school->id,
                'name' => 'Year '.random_int(1000, 9999),
                'order' => 1,
            ])->id,
            'arm_id' => Arm::create([
                'school_id' => $school->id,
                'label' => strtoupper(Str::random(3)),
            ])->id,
        ]);

        // A CURRENT session with an ACTIVE term: the listings resolve the term first and return
        // an empty payload without one — which would make every assertion below vacuously true.
        $session = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '20'.random_int(10, 99).'/20'.random_int(10, 99).'-'.Str::random(4),
            'slug' => Str::slug(Str::random(8)),
            'is_current' => true,
        ]);

        $term = Term::create([
            'academic_session_id' => $session->id,
            'name' => 'Term '.Str::random(4),
            'slug' => Str::slug(Str::random(8)),
            'order' => 1,
            'status' => 'active',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
        ]);

        $curriculum = Curriculum::factory()->create([
            'school_id' => $school->id,
            'class_level_arm_id' => $classLevelArm->id,
            'term_id' => $term->id,
        ]);

        $subject = Subject::create([
            'school_id' => $school->id,
            'name' => 'Subject '.Str::random(5),
            'code' => strtoupper(Str::random(4)),
        ]);

        $cs = CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'is_compulsory' => true,
            'active' => true,
        ]);

        $student = Student::factory()->create(['school_id' => $school->id]);

        $enrolment = StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $school->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        return [$cs, $student, $enrolment];
    });
}

// ── The reported production 500s ───────────────────────────────────────────

it('serves the form-teacher list after a student is withdrawn', function () {
    [$user, $teacher] = ws_teacher($this->school, 'form_teacher');
    [$cs, $student, $enrolment] = ws_classWithStudent($this->school);

    ActiveSchool::runFor($this->school->id, fn () => ClassLevelArmTeacher::create([
        'school_id' => $this->school->id,
        'teacher_id' => $teacher->id,
        'class_level_arm_id' => $cs->curriculum->class_level_arm_id,
        'role' => 'form_teacher',
    ]));

    // Green before AND non-empty: a listing test that returns [] for an unrelated reason (no
    // term, no assignment) would pass no matter how broken the mapping is.
    $before = $this->actingAs($user)->withSession(['school_id' => $this->school->id])
        ->getJson('/api/form-teacher/students')->assertOk();

    // toContain takes needles, not a message — so the guard is its own assertion.
    expect($before->json('data.rows'))->not->toBeEmpty();
    expect(collect($before->json('data.rows'))->pluck('student_curriculum_id'))
        ->toContain($enrolment->uuid);

    $student->delete(); // withdrawn — the enrolment row stays

    $response = $this->actingAs($user)->withSession(['school_id' => $this->school->id])
        ->getJson('/api/form-teacher/students')->assertOk();

    // Survives AND stops listing them: a withdrawn student does not belong in a live class list.
    expect(collect($response->json('data.rows') ?? [])->pluck('student_curriculum_id'))
        ->not->toContain($enrolment->uuid);
});

it('submits results after a student in the class is withdrawn', function () {
    [$user, $teacher] = ws_teacher($this->school, 'teacher');
    [$cs, $student] = ws_classWithStudent($this->school);

    ActiveSchool::runFor($this->school->id, fn () => TeacherCurriculumSubject::create([
        'teacher_id' => $teacher->id,
        'curriculum_subject_id' => $cs->id,
    ]));

    $this->actingAs($user)->withSession(['school_id' => $this->school->id])
        ->postJson("/api/curriculum-subjects/{$cs->uuid}/submit")->assertOk();

    $student->delete();

    // Before the fix this was a 500 — one withdrawal blocked submission for the entire class,
    // including every student who was still enrolled.
    $this->actingAs($user)->withSession(['school_id' => $this->school->id])
        ->postJson("/api/curriculum-subjects/{$cs->uuid}/submit")
        ->assertOk();
});

// ── The same fault, one page nobody had reached yet ────────────────────────

it('serves the head-of-school list after a student is withdrawn', function () {
    [$user, $teacher] = ws_teacher($this->school, 'head_of_school');
    [$cs, $student] = ws_classWithStudent($this->school);

    ActiveSchool::runFor($this->school->id, fn () => ClassLevelArmTeacher::create([
        'school_id' => $this->school->id,
        'teacher_id' => $teacher->id,
        'class_level_arm_id' => $cs->curriculum->class_level_arm_id,
        'role' => 'head_of_school',
    ]));

    $student->delete();

    $this->actingAs($user)->withSession(['school_id' => $this->school->id])
        ->getJson('/api/head-of-school/students')->assertSuccessful();
});

// ── The resource floor ─────────────────────────────────────────────────────

it('renders a null student as unknown rather than throwing', function () {
    // Six call sites build StudentResource straight from a nullable relation. Whatever the
    // callers do, the resource itself must not be the thing that 500s — this is the floor.
    $rendered = (new StudentResource(null))->toArray(request());

    expect($rendered['id'])->toBeNull()
        ->and($rendered['full_name'])->toBe('Unknown student')
        ->and($rendered['withdrawn'])->toBeTrue();
});

// ── The enrolment-status write ─────────────────────────────────────────────

it('refuses to reactivate an enrollment whose student is withdrawn, rather than 500ing', function () {
    [$cs, $student, $enrolment] = ws_classWithStudent($this->school);

    $admin = al_makeUser($this->school->id);
    $admin->grantSchoolAccess($this->school, 'admin');
    $admin->flushSchoolAccessCache();

    $enrolment->forceFill(['status' => 'promoted'])->save();
    $student->delete();

    $this->actingAs($admin)->withSession(['school_id' => $this->school->id])
        ->patchJson("/api/student-curricula/{$enrolment->uuid}", ['status' => 'active'])
        ->assertStatus(422);
});

// ── Students who are still here are unaffected ─────────────────────────────

it('still lists and submits for the students who remain', function () {
    [$user, $teacher] = ws_teacher($this->school, 'teacher');
    [$cs, $leaving] = ws_classWithStudent($this->school);

    // A second student in the same subject, who stays.
    $staying = ActiveSchool::runFor($this->school->id, function () use ($cs) {
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        $enrolment = StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $this->school->id,
            'curriculum_id' => $cs->curriculum_id,
            'status' => 'active',
        ]);

        StudentSubject::firstOrCreate([
            'student_curriculum_id' => $enrolment->id,
            'curriculum_subject_id' => $cs->id,
        ], ['status' => 'active']);

        return $student;
    });

    ActiveSchool::runFor($this->school->id, fn () => TeacherCurriculumSubject::create([
        'teacher_id' => $teacher->id,
        'curriculum_subject_id' => $cs->id,
    ]));

    $leaving->delete();

    $this->actingAs($user)->withSession(['school_id' => $this->school->id])
        ->postJson("/api/curriculum-subjects/{$cs->uuid}/submit")
        ->assertOk();

    // The withdrawal must not have taken the remaining student's enrolment with it.
    expect(StudentCurriculum::where('student_id', $staying->id)->exists())->toBeTrue();
});

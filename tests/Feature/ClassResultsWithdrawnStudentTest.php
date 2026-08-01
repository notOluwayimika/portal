<?php

use App\Enums\GenderTypeEnum;
use App\Enums\TeacherAssignmentRoleEnum;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmTeacher;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\FileUpload;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Teacher;
use App\Models\Term;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Routes authorize by GRANTS, not role names, so the fabricated role needs the
// canonical grant map to reach the code under test.
beforeEach(fn () => (new RbacSeeder)->run());

/**
 * A school with one active curriculum in one arm and two enrolled students.
 * Shaped after pa_setup() in PrincipalApprovalTest.
 */
function cr_setup(): array
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

    $makeStudent = function (string $firstName) use ($school, $curriculum) {
        $student = Student::create([
            'school_id' => $school->id,
            'first_name' => $firstName,
            'last_name' => 'Student',
            'gender' => 'female',
            'admission_number' => 'ADM-'.Str::random(8),
        ]);

        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        return [$student, $enrollment];
    };

    [$staying, $stayingEnrollment] = $makeStudent('Ada');
    [$leaving, $leavingEnrollment] = $makeStudent('Bola');

    return compact(
        'school', 'admin', 'level', 'classLevelArm', 'curriculum',
        'staying', 'stayingEnrollment', 'leaving', 'leavingEnrollment',
    );
}

/**
 * THE REGRESSION. `students` is soft-deleted while `student_curricula` rows
 * outlive the withdrawal, so a withdrawn student's enrollment stays `active` and
 * arrives with a null `->student`. The page serialized `student: null` and the
 * print view died on `sc.student.photo` — one leaver broke the whole class's
 * result sheet.
 */
it('omits enrollments whose student has been withdrawn from the class-arm result sheet', function () {
    $data = cr_setup();

    $data['leaving']->delete();

    expect($data['leavingEnrollment']->fresh()->status->value ?? $data['leavingEnrollment']->fresh()->status)
        ->toBe('active'); // the enrollment itself is untouched by the withdrawal

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->get("class-level-arm/{$data['classLevelArm']->uuid}/results")
        ->assertOk()
        ->assertInertia(function ($page) use ($data) {
            $page->component('student/results/list');

            $enrollments = collect($page->toArray()['props']['classLevelArms']['data'])
                ->flatMap(fn (array $arm) => $arm['curricula'] ?? [])
                ->flatMap(fn (array $curriculum) => $curriculum['student_curricula'] ?? []);

            // Every surviving row has a student — the null that crashed the page.
            expect($enrollments)->toHaveCount(1);
            expect($enrollments->pluck('student.id')->filter())->toHaveCount(1);
            expect($enrollments->first()['student']['first_name'])->toBe('Ada');

            unset($data);
        });
});

it('still renders the whole class-level sheet when a student is withdrawn', function () {
    $data = cr_setup();

    $data['leaving']->delete();

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->get("class-level/{$data['level']->uuid}/results")
        ->assertOk();
});

/**
 * The boarding-parent label on the result sheet reads this flag, and it must be
 * the SAME predicate that decides who may author the assessment — see
 * App\Support\Boarding.
 */
it('reports that the school has boarding parents only when one is assigned', function () {
    $data = cr_setup();

    $endpoint = "/api/student-curricula/{$data['stayingEnrollment']->uuid}";

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->getJson($endpoint)
        ->assertOk()
        ->assertJsonPath('schoolHasBoardingParents', false);

    $teacher = Teacher::create([
        'school_id' => $data['school']->id,
        'user_id' => al_makeUser($data['school']->id)->id,
        'first_name' => 'Boarding',
        'last_name' => 'Parent',
    ]);

    ClassLevelArmTeacher::create([
        'class_level_arm_id' => $data['classLevelArm']->id,
        'teacher_id' => $teacher->id,
        'role' => TeacherAssignmentRoleEnum::BOARDING_PARENT->value,
        'gender' => GenderTypeEnum::FEMALE->value,
    ]);

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->getJson($endpoint)
        ->assertOk()
        ->assertJsonPath('schoolHasBoardingParents', true);
});

/**
 * THE BUG. CurriculumCardFinal renders its "Approved by …" row only when a
 * `resultSignature` prop is present. The single-student routes have always passed
 * `resultSignatures`; ClassResultsController never did, so the class sheets — the
 * pages a school actually prints from — showed no signature at all while the
 * individual view of the same enrollment showed one.
 *
 * Asserted on BOTH routes, because they are two entry points into one private
 * renderResults() and a fix applied to either alone would look green from the other.
 */
it('ships a result signature for every enrollment on both class result sheets', function () {
    $data = cr_setup();

    $signature = FileUpload::create([
        'name' => 'signature.png',
        'folder_path' => 'school-result-signatures',
        'url' => 'https://example.test/signature.png',
    ]);

    $data['school']->update([
        'fallback_signature_id' => $signature->id,
        'result_approver_title' => 'Head of School',
    ]);

    $uuid = $data['stayingEnrollment']->uuid;

    foreach ([
        "class-level-arm/{$data['classLevelArm']->uuid}/results",
        "class-level/{$data['level']->uuid}/results",
    ] as $url) {
        $this->actingAs($data['admin'])
            ->withSession(['school_id' => $data['school']->id])
            ->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('student/results/list')
                // Keyed by ENROLLMENT uuid — the key the page indexes with
                // (`resultSignatures[sc.id]`). A signature keyed by student or by
                // curriculum would still be "present" and still print nothing.
                ->where("resultSignatures.{$uuid}.url", 'https://example.test/signature.png')
                ->where("resultSignatures.{$uuid}.label", 'Approved by the Head of School')
                ->etc()
            );
    }
});

/**
 * Primary asked for the behavioural-assessment comment to come off its result;
 * secondary keeps it. One field, two captions ("Boarding Parent Comment" where
 * boarding applies, "Behaviour Comment" otherwise), so one flag governs both.
 */
it('reports whether the behaviour comment should print, defaulting to yes', function () {
    $data = cr_setup();

    $endpoint = "/api/student-curricula/{$data['stayingEnrollment']->uuid}";

    // Untouched school: prints what it always printed.
    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->getJson($endpoint)
        ->assertOk()
        ->assertJsonPath('showBehaviourComment', true);

    $data['school']->update(['show_behaviour_comment_on_result' => false]);

    $this->actingAs($data['admin'])
        ->withSession(['school_id' => $data['school']->id])
        ->getJson($endpoint)
        ->assertOk()
        ->assertJsonPath('showBehaviourComment', false);
});

<?php

use App\Models\AcademicSession;
use App\Models\Activity;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\ExamType;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\ActivityLog\ActivitySeverityService;
use App\Support\StudentRecordAccessLog;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/*
|--------------------------------------------------------------------------
| Why this file exists
|--------------------------------------------------------------------------
|
| GuardianWardAuthorisationTest and GuardianBulkRecordAccessTest pin the three
| middlewares' DECISIONS. This file pins the trail they leave, which is a
| separate property and fails separately: every test here would stay green with
| the logging deleted if it only asserted status codes, and every test in those
| two files stays green with the logging deleted full stop. The question this
| exists to keep answerable is "did a parent read a child who is not theirs" —
| unanswerable on 2026-08-25 because nothing recorded a view.
|
| Helpers are `sral_`-prefixed (Pest test functions are global) so they cannot
| collide with the `gwa_` and `gbr_` sets in the two files above. The world is
| built here rather than borrowed from them for the same reason a fixture is
| never shared across files in this project: those functions only exist if their
| file happened to be loaded, so running this file alone would fatal.
*/

/**
 * A logger whose write always fails.
 *
 * Bound over Spatie's ActivityLogger, which the `activity()` helper resolves
 * fresh per call (the package binds it with bind(), not singleton), so every
 * write in the request under test throws. This is the only way to prove the
 * swallow: a logging failure that cannot be caused cannot be shown to be
 * harmless.
 */
class SralThrowingActivityLogger extends ActivityLogger
{
    public function log(string $description): ?ActivityContract
    {
        throw new RuntimeException('activity log unavailable');
    }
}

/**
 * One school; two students identical in every way EXCEPT the guardian_student
 * pivot; two guardians identical except which student they own; one class level
 * whose results are a bulk screen; plus staff.
 *
 * The two students share a curriculum so that "the entry names the ward" cannot
 * pass by naming the only student that exists, and the two guardians share a
 * school and a role so that a `guardian_self` refusal cannot pass by naming the
 * only guardian row.
 */
function sral_world(): array
{
    $school = al_makeSchool();

    $classLevel = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);
    $arm = Arm::create(['school_id' => $school->id, 'label' => 'A']);
    $classLevelArm = ClassLevelArm::forceCreate([
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
    $subject = Subject::create([
        'school_id' => $school->id,
        'name' => 'Mathematics',
        'code' => 'MTH-'.Str::random(4),
    ]);
    $curriculumSubject = CurriculumSubject::create([
        'curriculum_id' => $curriculum->id,
        'subject_id' => $subject->id,
        'is_compulsory' => true,
        'display_order' => 1,
        'active' => true,
    ]);

    $make = function (string $first) use ($school, $curriculum) {
        $student = Student::create([
            'school_id' => $school->id,
            'first_name' => $first,
            'last_name' => 'Child',
            'gender' => 'female',
            'admission_number' => 'ADM-'.Str::random(8),
        ]);
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
            'principal_approval' => true,
        ]);

        return [$student, $enrollment];
    };

    [$ward, $wardCurriculum] = $make('Ada');
    [$stranger, $strangerCurriculum] = $make('Zoe');

    $guardianUser = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $guardianUser->assignRole('guardian');
    $guardianUser->schools()->syncWithoutDetaching([$school->id]);
    $ownGuardian = al_makeGuardian($school->id, $guardianUser->id);
    $ownGuardian->students()->attach($ward->id, [
        'relationship' => 'mother', 'is_primary' => true, 'can_login' => true,
    ]);

    // A second parent, same school, same role, with the other student.
    $otherUser = al_makeUser($school->id);
    $otherUser->assignRole('guardian');
    $otherUser->schools()->syncWithoutDetaching([$school->id]);
    $otherGuardian = al_makeGuardian($school->id, $otherUser->id);
    $otherGuardian->students()->attach($stranger->id, [
        'relationship' => 'father', 'is_primary' => true, 'can_login' => true,
    ]);

    $admin = al_makeUser($school->id);
    $admin->assignRole('admin');
    $admin->schools()->syncWithoutDetaching([$school->id]);

    return compact(
        'school', 'classLevel', 'classLevelArm', 'curriculum', 'curriculumSubject',
        'ward', 'wardCurriculum', 'stranger', 'strangerCurriculum',
        'guardianUser', 'ownGuardian', 'otherUser', 'otherGuardian', 'admin',
    );
}

function sral_get(array $w, string $url, User $actor, bool $json = false)
{
    $request = test()->actingAs($actor)->withSession(['school_id' => $w['school']->id]);

    return $json ? $request->getJson($url) : $request->get($url);
}

/**
 * Entries of one event, optionally by one causer.
 *
 * Read straight off the model with no scope of its own, so the assertion is
 * about what was WRITTEN and not about what the activity-log read API would
 * choose to show.
 */
function sral_entries(string $event, ?User $causer = null): Collection
{
    return Activity::query()
        ->where('log_name', StudentRecordAccessLog::LOG_NAME)
        ->where('event', $event)
        ->when($causer !== null, fn ($q) => $q
            ->where('causer_type', User::class)
            ->where('causer_id', $causer->id))
        ->get();
}

/*
|--------------------------------------------------------------------------
| 1. The view
|--------------------------------------------------------------------------
*/

it('records one view entry naming the ward when a guardian reads their own child', function () {
    $w = sral_world();

    sral_get($w, "/students/{$w['ward']->uuid}/results/active", $w['guardianUser'])->assertOk();

    $entries = sral_entries(StudentRecordAccessLog::VIEWED, $w['guardianUser']);

    expect($entries)->toHaveCount(1);

    $entry = $entries->first();

    // The SUBJECT is the ward and not the other student in the same curriculum,
    // so this cannot pass by naming whichever student the fixture happens to
    // hold. The stranger id is asserted absent explicitly.
    expect($entry->subject_type)->toBe(Student::class);
    expect((int) $entry->subject_id)->toBe((int) $w['ward']->id);
    expect((int) $entry->subject_id)->not->toBe((int) $w['stranger']->id);
    expect($entry->properties->get('student_ids'))->toBe([(int) $w['ward']->id]);
    expect($entry->properties->get('route'))->toBe('students.results.active');
    expect((int) $entry->causer_id)->toBe((int) $w['guardianUser']->id);
    expect((int) $entry->school_id)->toBe((int) $w['school']->id);
});

/*
| The three enrollment-bound routes carry NO {student} parameter at all, so the
| subject cannot come off the request — it has to be resolved from the id the
| middleware derived through the enrollment. A version that only ever read a
| bound Student writes a subjectless row here while passing the test above.
*/
it('names the student on an enrollment-bound route, where the request never carries one', function () {
    $w = sral_world();

    sral_get($w, "/setup/student-curricula/{$w['wardCurriculum']->uuid}/subjects", $w['guardianUser'])
        ->assertOk();

    $entry = sral_entries(StudentRecordAccessLog::VIEWED, $w['guardianUser'])->sole();

    expect($entry->subject_type)->toBe(Student::class);
    expect((int) $entry->subject_id)->toBe((int) $w['ward']->id);
    expect($entry->properties->get('student_ids'))->toBe([(int) $w['ward']->id]);
});

/*
|--------------------------------------------------------------------------
| 2-4. The three refusals, each naming its own rule
|--------------------------------------------------------------------------
|
| The three rules are asserted as EXACT strings and each test also asserts the
| other two rules did not appear. "A refusal was logged" is satisfied by a
| middleware that writes one constant for all three, which is precisely the
| thing that cannot tell a probe from a misconfiguration.
*/

it('records a refusal naming guardian_ward when a guardian asks for a student who is not theirs', function () {
    $w = sral_world();

    sral_get($w, "/students/{$w['stranger']->uuid}/results/active", $w['guardianUser'])
        ->assertForbidden();

    $entry = sral_entries(StudentRecordAccessLog::REFUSED, $w['guardianUser'])->sole();

    expect($entry->properties->get('rule'))->toBe('guardian_ward');
    expect($entry->properties->get('student_ids'))->toBe([(int) $w['stranger']->id]);
    expect((int) $entry->subject_id)->toBe((int) $w['stranger']->id);
    expect($entry->properties->get('route'))->toBe('students.results.active');
    expect(sral_entries(StudentRecordAccessLog::VIEWED, $w['guardianUser']))->toHaveCount(0);
});

it('records a refusal naming guardian_no_bulk when a guardian hits a whole-cohort screen', function () {
    $w = sral_world();

    sral_get($w, "/class-level/{$w['classLevel']->uuid}/results", $w['guardianUser'])
        ->assertForbidden();

    $entry = sral_entries(StudentRecordAccessLog::REFUSED, $w['guardianUser'])->sole();

    expect($entry->properties->get('rule'))->toBe('guardian_no_bulk');
    expect($entry->properties->get('rule'))->not->toBe('guardian_ward');
    // No student was named by the request, so none is invented.
    expect($entry->subject_id)->toBeNull();
    expect((int) $entry->causer_id)->toBe((int) $w['guardianUser']->id);
});

it("records a refusal naming guardian_self when a guardian asks for another parent's record", function () {
    $w = sral_world();

    sral_get($w, "/api/guardians/{$w['otherGuardian']->uuid}/students", $w['guardianUser'], json: true)
        ->assertForbidden();

    $entry = sral_entries(StudentRecordAccessLog::REFUSED, $w['guardianUser'])->sole();

    expect($entry->properties->get('rule'))->toBe('guardian_self');
    expect($entry->properties->get('rule'))->not->toBe('guardian_ward');
    // The GUARDIAN asked for is recorded, and it is the other parent's row and
    // not the caller's own — two guardian rows exist precisely so this can fail.
    expect($entry->properties->get('guardian_ids'))->toBe([(int) $w['otherGuardian']->id]);
    expect($entry->properties->get('guardian_ids'))->not->toBe([(int) $w['ownGuardian']->id]);
});

/*
|--------------------------------------------------------------------------
| 5. Staff are not logged — a decision, not an omission
|--------------------------------------------------------------------------
|
| Both actors drive the SAME url in the SAME test, so the only difference
| between the arm that writes a row and the arm that does not is which account
| made the request. A test that only asserted "the admin wrote nothing" would
| also pass with the logging entirely broken.
*/

it('writes nothing for a member of staff reading the same student a guardian is logged for', function () {
    $w = sral_world();

    $url = "/students/{$w['ward']->uuid}/results/active";

    sral_get($w, $url, $w['admin'])->assertOk();

    expect(sral_entries(StudentRecordAccessLog::VIEWED))->toHaveCount(0);
    expect(sral_entries(StudentRecordAccessLog::REFUSED))->toHaveCount(0);

    // ...and the same request as the parent DOES write one, so the emptiness
    // above is the staff exemption and not a dead logger.
    sral_get($w, $url, $w['guardianUser'])->assertOk();

    expect(sral_entries(StudentRecordAccessLog::VIEWED))->toHaveCount(1);
    expect((int) sral_entries(StudentRecordAccessLog::VIEWED)->sole()->causer_id)
        ->toBe((int) $w['guardianUser']->id);
});

it('writes nothing for a member of staff reading a student no guardian owns', function () {
    $w = sral_world();

    sral_get($w, "/students/{$w['stranger']->uuid}/results/active", $w['admin'])->assertOk();

    expect(sral_entries(StudentRecordAccessLog::VIEWED))->toHaveCount(0);
    expect(sral_entries(StudentRecordAccessLog::REFUSED))->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| 6. A broken log changes nothing
|--------------------------------------------------------------------------
|
| Each of these asserts the write ACTUALLY FAILED (zero rows) as well as the
| status. Without that, a binding that silently did not take effect produces the
| same green — the test would be measuring nothing at all.
*/

it('still refuses when the audit write throws', function () {
    $w = sral_world();

    $this->app->bind(ActivityLogger::class, SralThrowingActivityLogger::class);

    sral_get($w, "/students/{$w['stranger']->uuid}/results/active", $w['guardianUser'])
        ->assertForbidden();

    expect(sral_entries(StudentRecordAccessLog::REFUSED))->toHaveCount(0);
});

it('still serves the view when the audit write throws', function () {
    $w = sral_world();

    $this->app->bind(ActivityLogger::class, SralThrowingActivityLogger::class);

    sral_get($w, "/students/{$w['ward']->uuid}/results/active", $w['guardianUser'])
        ->assertOk();

    expect(sral_entries(StudentRecordAccessLog::VIEWED))->toHaveCount(0);
});

it('still refuses the bulk and self routes when the audit write throws', function () {
    $w = sral_world();

    $this->app->bind(ActivityLogger::class, SralThrowingActivityLogger::class);

    sral_get($w, "/class-level/{$w['classLevel']->uuid}/results", $w['guardianUser'])
        ->assertForbidden();
    sral_get($w, "/api/guardians/{$w['otherGuardian']->uuid}/students", $w['guardianUser'], json: true)
        ->assertForbidden();

    expect(sral_entries(StudentRecordAccessLog::REFUSED))->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| Queryability
|--------------------------------------------------------------------------
|
| The event names are the whole point: the read API filters `event` with a
| whereIn and the screen offers the distinct values as a multi-select. A
| sentence in that column is a row nobody can select, and the log already
| carries both conventions.
*/

it('writes machine-queryable snake_case event names', function () {
    expect(StudentRecordAccessLog::VIEWED)->toMatch('/^[a-z][a-z0-9_]*$/');
    expect(StudentRecordAccessLog::REFUSED)->toMatch('/^[a-z][a-z0-9_]*$/');
});

/*
|--------------------------------------------------------------------------
| Severity
|--------------------------------------------------------------------------
|
| Resolved through ActivitySeverityService — the same object ActivityResource
| calls, so this is the tier the screen actually shows — and resolved from the
| log_name and event of the row the MIDDLEWARE wrote, never from the constants
| restated here. A config entry naming a log_name nothing emits would then fail
| this rather than agreeing with itself.
*/

it('classifies a refusal as warning and an authorised view as info', function () {
    $w = sral_world();

    sral_get($w, "/students/{$w['stranger']->uuid}/results/active", $w['guardianUser'])
        ->assertForbidden();
    sral_get($w, "/students/{$w['ward']->uuid}/results/active", $w['guardianUser'])
        ->assertOk();

    $severity = ActivitySeverityService::make();
    $refusal = sral_entries(StudentRecordAccessLog::REFUSED)->sole();
    $view = sral_entries(StudentRecordAccessLog::VIEWED)->sole();

    expect($severity->for($refusal->log_name, $refusal->event))->toBe('warning');
    expect($severity->for($view->log_name, $view->event))->toBe('info');
});

/*
| The `info` half of the test above cannot fail on its own axis, and saying so
| is the point of this one.
|
| ActivitySeverityService::for() iterates `critical`, `warning` and `notice`
| ONLY and returns 'info' as the fall-through — the config's `info` key is read
| by nothing. So "the view event is info" is currently true of every event name
| no tier claims, including one nobody ever classified. This test makes the
| axis live: it plants the view event under `notice` and shows the resolver
| moves, which is the assertion the one above would make if the classification
| were ever wrong, and it pins the real property — that no explicit tier claims
| an ordinary authorised read.
*/
it('leaves the view event unclaimed by every explicit tier, and would notice if one claimed it', function () {
    $severity = ActivitySeverityService::make();

    expect($severity->for(
        StudentRecordAccessLog::LOG_NAME,
        StudentRecordAccessLog::VIEWED,
    ))->toBe('info');

    config(['activity_log_severity.notice' => array_merge(
        (array) config('activity_log_severity.notice', []),
        [StudentRecordAccessLog::LOG_NAME.'.'.StudentRecordAccessLog::VIEWED],
    )]);

    expect(ActivitySeverityService::make()->for(
        StudentRecordAccessLog::LOG_NAME,
        StudentRecordAccessLog::VIEWED,
    ))->toBe('notice');
});
